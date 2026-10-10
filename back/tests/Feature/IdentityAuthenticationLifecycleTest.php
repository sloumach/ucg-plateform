<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class IdentityAuthenticationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_reset_request_is_non_enumerating_and_audited(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'member@ucg.test']);

        $known = $this->withHeaders($this->spaHeaders())->postJson('/api/v1/auth/forgot-password', [
            'email' => 'MEMBER@UCG.TEST',
        ]);
        $unknown = $this->withHeaders($this->spaHeaders())->postJson('/api/v1/auth/forgot-password', [
            'email' => 'absent@ucg.test',
        ]);

        foreach ([$known, $unknown] as $response) {
            $response->assertOk()->assertJsonPath(
                'notification.message',
                'Si un compte correspond à cette adresse, un lien de réinitialisation a été envoyé.',
            );
        }
        Notification::assertSentTo($user, ResetPassword::class);
        $this->assertDatabaseCount('identity_security_events', 2);
        $this->assertDatabaseHas('identity_security_events', [
            'action' => 'auth.password_reset.requested',
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('identity_security_events', [
            'action' => 'auth.password_reset.requested',
            'user_id' => null,
        ]);
    }

    public function test_valid_password_reset_changes_password_revokes_session_and_is_audited(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'email' => 'member@ucg.test',
            'password' => 'Old-password-123!',
        ]);
        $token = null;

        $this->withHeaders($this->spaHeaders())->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Old-password-123!',
        ])->assertOk();
        $this->getJson('/api/v1/auth/me')->assertOk();
        $this->withHeaders($this->spaHeaders())->postJson('/api/v1/auth/forgot-password', [
            'email' => $user->email,
        ])->assertOk();

        Notification::assertSentTo(
            $user,
            ResetPassword::class,
            function (ResetPassword $notification) use (&$token): bool {
                $token = $notification->token;

                return true;
            },
        );
        $this->assertIsString($token);

        $this->withHeaders($this->spaHeaders())->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'New-password-456!',
            'password_confirmation' => 'New-password-456!',
        ])->assertOk()->assertJsonPath(
            'notification.message',
            'Le mot de passe a été réinitialisé. Les anciennes sessions ont été révoquées.',
        );

        $refreshedUser = $user->fresh();
        $this->assertInstanceOf(User::class, $refreshedUser);
        $this->assertTrue(Hash::check('New-password-456!', $refreshedUser->password));
        Auth::forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertDatabaseHas('identity_security_events', [
            'action' => 'auth.password_reset.succeeded',
            'user_id' => $user->id,
        ]);
    }

    public function test_invalid_or_weak_password_reset_is_rejected(): void
    {
        $user = User::factory()->create(['email' => 'member@ucg.test']);

        $this->withHeaders($this->spaHeaders())->postJson('/api/v1/auth/reset-password', [
            'token' => 'invalid-token',
            'email' => $user->email,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->withHeaders($this->spaHeaders())->postJson('/api/v1/auth/reset-password', [
            'token' => 'invalid-token',
            'email' => $user->email,
            'password' => 'Strong-password-456!',
            'password_confirmation' => 'Strong-password-456!',
        ])->assertUnprocessable()->assertJsonValidationErrors('token');

        $this->assertDatabaseMissing('identity_security_events', [
            'action' => 'auth.password_reset.succeeded',
        ]);
    }

    public function test_unverified_user_can_request_and_complete_signed_email_verification(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/auth/email/verification-notification')
            ->assertOk();
        Notification::assertSentTo($user, VerifyEmail::class);

        $url = URL::temporarySignedRoute(
            'api.v1.verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );

        $this->actingAs($user)->getJson($url)
            ->assertOk()
            ->assertJsonPath('notification.message', 'L’adresse e-mail a été vérifiée.');

        $refreshedUser = $user->fresh();
        $this->assertInstanceOf(User::class, $refreshedUser);
        $this->assertNotNull($refreshedUser->email_verified_at);
        $this->assertDatabaseHas('identity_security_events', [
            'action' => 'auth.email_verified',
            'user_id' => $user->id,
        ]);
    }

    public function test_account_notifications_build_frontend_reset_and_signed_verification_urls(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'member@ucg.test']);

        $resetMail = (new ResetPassword('secret-token'))->toMail($user);
        $verificationMail = (new VerifyEmail)->toMail($user);

        $this->assertStringContainsString(
            'http://localhost:5173/?reset_token=secret-token&email=member%40ucg.test',
            (string) $resetMail->actionUrl,
        );
        $this->assertStringContainsString(
            "/api/v1/auth/email/verify/{$user->id}/".sha1($user->email),
            (string) $verificationMail->actionUrl,
        );
        $this->assertStringContainsString('signature=', (string) $verificationMail->actionUrl);
    }

    public function test_email_verification_rejects_unsigned_or_other_account_links(): void
    {
        $user = User::factory()->unverified()->create();
        $other = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->getJson("/api/v1/auth/email/verify/{$user->id}/".sha1($user->email))
            ->assertForbidden();

        $url = URL::temporarySignedRoute(
            'api.v1.verification.verify',
            now()->addMinutes(60),
            ['id' => $other->id, 'hash' => sha1($other->email)],
        );
        $this->actingAs($user)->getJson($url)->assertForbidden();
        $refreshedUser = $user->fresh();
        $refreshedOther = $other->fresh();
        $this->assertInstanceOf(User::class, $refreshedUser);
        $this->assertInstanceOf(User::class, $refreshedOther);
        $this->assertNull($refreshedUser->email_verified_at);
        $this->assertNull($refreshedOther->email_verified_at);
    }

    public function test_user_can_revoke_other_sessions_only_with_current_password(): void
    {
        $user = User::factory()->create(['password' => 'Current-password-123!']);

        $this->actingAs($user)->postJson('/api/v1/auth/sessions/revoke-others', [
            'password' => 'wrong-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->actingAs($user)->postJson('/api/v1/auth/sessions/revoke-others', [
            'password' => 'Current-password-123!',
        ])->assertOk()->assertJsonPath(
            'notification.message',
            'Les autres sessions ont été révoquées.',
        );

        $this->assertDatabaseHas('identity_security_events', [
            'action' => 'auth.sessions.revoked',
            'user_id' => $user->id,
        ]);
    }

    public function test_login_is_rate_limited_and_security_outcomes_are_audited(): void
    {
        $user = User::factory()->create([
            'email' => 'member@ucg.test',
            'password' => 'Valid-password-123!',
        ]);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withHeaders($this->spaHeaders())->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'invalid-password',
            ])->assertUnprocessable();
        }

        $this->withHeaders($this->spaHeaders())->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'invalid-password',
        ])->assertTooManyRequests()->assertHeader('Retry-After');

        $this->assertDatabaseCount('identity_security_events', 5);
        $this->assertDatabaseHas('identity_security_events', [
            'action' => 'auth.login.failed',
            'user_id' => $user->id,
        ]);
    }

    public function test_security_audit_is_append_only_at_database_level(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)
            ->withHeaders($this->spaHeaders())
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $event = DB::table('identity_security_events')->first();
        $this->assertNotNull($event);

        foreach (
            [
                fn () => DB::table('identity_security_events')->where('id', $event->id)->update(['action' => 'rewritten']),
                fn () => DB::table('identity_security_events')->where('id', $event->id)->delete(),
            ] as $mutation
        ) {
            try {
                DB::transaction($mutation);
                $this->fail('The database accepted a mutation of the security audit.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }

            $this->assertDatabaseHas('identity_security_events', [
                'id' => $event->id,
                'action' => 'auth.logout.succeeded',
            ]);
        }
    }

    /** @return array<string, string> */
    private function spaHeaders(): array
    {
        return [
            'Origin' => 'http://localhost:5173',
            'Referer' => 'http://localhost:5173/',
        ];
    }
}
