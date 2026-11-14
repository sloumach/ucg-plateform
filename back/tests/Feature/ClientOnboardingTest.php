<?php

namespace Tests\Feature;

use App\Modules\Identity\Application\Services\MfaSession;
use App\Modules\Identity\Application\Services\PlatformRoleService;
use App\Modules\Identity\Domain\Models\PlatformRole;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\PlatformRoleType;
use App\Modules\Tenancy\Domain\Contracts\ClientActivationDelivery;
use App\Modules\Tenancy\Domain\Contracts\OrganizationRepository;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Infrastructure\Mail\MailClientActivationDelivery;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\TestCase;

class ClientOnboardingTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase;

    public function test_invitation_creates_nothing_until_recipient_activates_and_replay_creates_no_duplicate(): void
    {
        $admin = $this->operator();
        $token = $this->invite();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('organizations', 0);
        $this->app['auth']->forgetGuards();

        $response = $this->postJson('/api/v1/client-onboarding/confirm', $this->activation($token))->assertCreated();

        $owner = User::query()->where('email', 'client@example.test')->sole();
        $this->assertTrue($owner->hasVerifiedEmail());
        $this->assertTrue(Hash::check($this->activation($token)['password'], $owner->password));
        $response->assertJsonPath('data.owner_user_id', $owner->id)->assertJsonMissingPath('data.password');
        $this->assertDatabaseHas('organizations', ['slug' => 'client-org', 'owner_user_id' => $owner->id]);
        $this->assertDatabaseHas('organization_lifecycle_events', ['actor_user_id' => $admin->user_id, 'action' => 'created']);
        $this->assertDatabaseHas('identity_security_events', ['user_id' => $owner->id, 'action' => 'auth.email_verified']);
        $this->postJson('/api/v1/client-onboarding/confirm', $this->activation($token))->assertUnprocessable();
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('organizations', 1);
        $this->assertDatabaseCount('platform_roles', 1);
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->actingAs($owner);
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.mfa.required', true);
        $this->getJson('/api/v1/accessible-organizations')->assertForbidden()->assertJsonPath('code', 'MFA_ENROLLMENT_REQUIRED');
        $this->actingAsWithMfa($owner);
        $this->getJson('/api/v1/accessible-organizations')->assertJsonPath('data.0.id', $response->json('data.id'));
        $this->flushSession();
        $this->actingAsWithMfa(User::findOrFail($admin->user_id));
        $this->getJson('/api/v1/organizations/'.$response->json('data.id'))->assertNotFound();
    }

    public function test_only_superadmin_with_current_mfa_can_invite(): void
    {
        $this->postJson('/api/v1/platform/client-invitations', $this->payload())->assertUnauthorized();
        $this->actingAsWithMfa(User::factory()->withMfa()->create());
        $this->postJson('/api/v1/platform/client-invitations', $this->payload())->assertForbidden();
        $support = PlatformRole::factory()->create();
        $this->actingAsWithMfa(User::findOrFail($support->user_id));
        $this->postJson('/api/v1/platform/client-invitations', $this->payload())->assertForbidden();
        $this->assertDatabaseCount('organizations', 0);
    }

    public function test_superadmin_requires_a_current_mfa_proof(): void
    {
        $this->operator();
        $this->withSession([MfaSession::PROOF_KEY => null]);
        $this->postJson('/api/v1/platform/client-invitations', $this->payload())
            ->assertForbidden()->assertJsonPath('code', 'MFA_CHALLENGE_REQUIRED');
        $this->assertDatabaseCount('organizations', 0);
    }

    public function test_auth_response_only_exposes_provisioning_capability_to_superadmin(): void
    {
        $this->actingAs(User::factory()->create());
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.can_provision_clients', false);
        $this->operator();
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.can_provision_clients', true);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidInvitations(): array
    {
        return [
            'confirmation' => [['confirm' => false], 'confirm'],
            'email' => [['email' => 'invalid'], 'email'],
            'timezone' => [['timezone' => 'unknown'], 'timezone'],
            'country' => [['country' => 'XX'], 'country'],
            'slug' => [['slug' => '../admin'], 'slug'],
            'owner injection' => [['owner_user_id' => 1], 'owner_user_id'],
            'role injection' => [['role' => 'superadmin'], 'role'],
        ];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidInvitations')]
    public function test_invalid_invitations_are_rejected(array $overrides, string $field): void
    {
        $this->operator();

        $this->postJson('/api/v1/platform/client-invitations', [...$this->payload(), ...$overrides])
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('organizations', 0);
    }

    public function test_existing_account_cannot_be_taken_over_by_invitation(): void
    {
        $this->operator();
        $user = User::factory()->create(['email' => 'CLIENT@example.test']);
        $hash = $user->password;

        $this->postJson('/api/v1/platform/client-invitations', $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertSame($hash, $user->refresh()->password);
        $this->assertDatabaseCount('organizations', 0);
    }

    public function test_tampered_expired_and_weak_password_activations_are_rejected(): void
    {
        $this->operator();
        $token = $this->invite();
        $this->postJson('/api/v1/client-onboarding/confirm', $this->activation($token.'changed'))
            ->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->postJson('/api/v1/client-onboarding/confirm', [...$this->activation($token), 'password' => 'weak', 'password_confirmation' => 'weak'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $longPassword = str_repeat('é', 36).'Aa1!';
        $this->postJson('/api/v1/client-onboarding/confirm', [...$this->activation($token), 'password' => $longPassword, 'password_confirmation' => $longPassword])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->travel(60)->minutes();
        $this->postJson('/api/v1/client-onboarding/confirm', $this->activation($token))
            ->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('organizations', 0);
    }

    public function test_revocation_and_regrant_invalidates_previously_sent_link(): void
    {
        $admin = $this->operator();
        $token = $this->invite();
        $admin->forceFill(['active' => false, 'version' => (string) Str::uuid()])->save();
        $this->postJson('/api/v1/client-onboarding/confirm', $this->activation($token))->assertForbidden();
        $admin->forceFill(['active' => true, 'version' => (string) Str::uuid()])->save();
        $this->postJson('/api/v1/client-onboarding/confirm', $this->activation($token))
            ->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->assertDatabaseCount('organizations', 0);
    }

    public function test_later_slug_conflict_leaves_no_new_account(): void
    {
        $this->operator();
        $token = $this->invite();
        Organization::factory()->create(['slug' => 'client-org']);
        $count = User::query()->count();

        $this->postJson('/api/v1/client-onboarding/confirm', $this->activation($token))
            ->assertUnprocessable()->assertJsonValidationErrors('slug');

        $this->assertDatabaseCount('users', $count);
    }

    public function test_failure_after_account_creation_rolls_back_account_verification_audit_and_organization(): void
    {
        $this->operator();
        $token = $this->invite();
        $this->mock(OrganizationRepository::class, function ($mock): void {
            $mock->shouldReceive('findBySlug')->andReturnNull();
            $mock->shouldReceive('create')->once()->andThrow(new RuntimeException('Simulated organization write failure'));
        });

        $this->postJson('/api/v1/client-onboarding/confirm', $this->activation($token))->assertInternalServerError();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseCount('identity_security_events', 0);
    }

    public function test_local_preview_does_not_send_or_log_activation_link(): void
    {
        app()->detectEnvironment(fn (): string => 'local');
        config(['mail.default' => 'log']);

        $link = app(MailClientActivationDelivery::class)->send('client@example.test', 'Client', 'Club', 'https://app.test/#client_setup=test');

        $this->assertSame('https://app.test/#client_setup=test', $link);
    }

    public function test_production_refuses_a_log_mail_transport(): void
    {
        app()->detectEnvironment(fn (): string => 'production');
        config(['mail.default' => 'log']);
        $this->expectException(ValidationException::class);

        app(MailClientActivationDelivery::class)->send('client@example.test', 'Client', 'Club', 'https://app.test/#client_setup=test');
    }

    public function test_delivery_addresses_the_email_to_the_client_and_contains_activation_instructions(): void
    {
        config(['mail.default' => 'smtp']);
        $transport = new ArrayTransport;
        Mail::mailer()->setSymfonyTransport($transport);

        $preview = app(MailClientActivationDelivery::class)->send('client@example.test', 'Client', 'Club', 'https://app.test/#client_setup=test');

        $this->assertNull($preview);
        $sent = $transport->messages()->sole();
        $this->assertInstanceOf(SentMessage::class, $sent);
        $message = $sent->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $message);
        $this->assertSame('client@example.test', $message->getTo()[0]->getAddress());
        $this->assertStringContainsString('https://app.test/#client_setup=test', (string) $message->getTextBody());
        $this->assertStringContainsString('expire dans une heure', (string) $message->getTextBody());
    }

    public function test_delivery_refuses_a_failover_transport_that_could_log_the_link(): void
    {
        config(['mail.default' => 'failover']);
        $this->expectException(ValidationException::class);

        app(MailClientActivationDelivery::class)->send('client@example.test', 'Client', 'Club', 'https://app.test/#client_setup=test');
    }

    private function operator(): PlatformRole
    {
        $user = User::factory()->withMfa()->create();
        $role = app(PlatformRoleService::class)->configure($user->id, PlatformRoleType::Superadmin, true,
            null, 'Initialisation du gestionnaire de plateforme', (string) Str::uuid(), true);
        $this->actingAsWithMfa($user);

        return $role;
    }

    private function invite(): string
    {
        $this->mock(ClientActivationDelivery::class, function ($mock): void {
            $mock->shouldReceive('send')->once()->andReturnUsing(fn (string $email, string $name, string $organization, string $url): string => $url);
        });
        $response = $this->postJson('/api/v1/platform/client-invitations', $this->payload())->assertAccepted();
        $url = $response->json('data.activation_url');
        $fragmentString = parse_url($url, PHP_URL_FRAGMENT);
        $this->assertIsString($fragmentString);
        parse_str($fragmentString, $fragment);
        $this->assertIsString($fragment['client_setup']);

        return $fragment['client_setup'];
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['client_name' => 'Client', 'email' => 'client@example.test', 'name' => 'Client Organization', 'slug' => 'client-org',
            'timezone' => 'Africa/Tunis', 'language' => 'fr', 'country' => 'TN',
            'settings' => ['week_starts_on' => 1, 'date_format' => 'd/m/Y'], 'reason' => 'Ouverture du compte client', 'confirm' => true];
    }

    /** @return array{token: string, password: string, password_confirmation: string} */
    private function activation(string $token): array
    {
        return ['token' => $token, 'password' => 'Example-test-password-123!', 'password_confirmation' => 'Example-test-password-123!'];
    }
}
