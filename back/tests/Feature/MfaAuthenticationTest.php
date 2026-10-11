<?php

namespace Tests\Feature;

use App\Modules\Identity\Application\Services\MfaService;
use App\Modules\Identity\Application\Services\MfaSession;
use App\Modules\Identity\Domain\Models\PlatformRole;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Totp;
use App\Modules\Tenancy\Application\Services\TenantJobRunner;
use App\Modules\Tenancy\Domain\Exceptions\TenantExecutionRejectedException;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use App\Support\Queue\JobContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\TestCase;

class MfaAuthenticationTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase;

    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/']);
        $this->freezeTime();
    }

    public function test_guest_cannot_read_or_manage_mfa(): void
    {
        $this->getJson('/api/v1/auth/mfa')->assertUnauthorized();
        $this->postJson('/api/v1/auth/mfa/enrollment', ['password' => 'password'])->assertUnauthorized();
        $this->postJson('/api/v1/auth/mfa/challenge', ['code' => '287082'])->assertUnauthorized();
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidPasswords(): array
    {
        return [
            'missing' => [[], 'Le mot de passe est obligatoire.'],
            'non string' => [['password' => ['wrong']], 'Le mot de passe doit être du texte.'],
            'too long' => [['password' => str_repeat('x', 256)], 'Le mot de passe ne peut pas dépasser 255 caractères.'],
        ];
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidPasswords')]
    public function test_mfa_enrollment_validates_password_shape_before_hashing(array $payload, string $message): void
    {
        $account = User::factory()->create();
        $this->loginAccount($account);
        $response = $this->postJson('/api/v1/auth/mfa/enrollment', $payload)->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertSame($message, $response->json('errors.password.0'));
        $this->assertFalse($this->reload($account)->hasMfa());
    }

    public function test_mfa_management_and_session_revocation_reject_a_non_string_password(): void
    {
        $account = User::factory()->withMfa()->create();
        $this->actingAsWithMfa($account);
        $this->postJson('/api/v1/auth/mfa/recovery-codes', ['password' => ['invalid']])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/v1/auth/sessions/revoke-others', ['password' => ['invalid']])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertSame([], $this->reload($account)->mfa_recovery_codes);
    }

    public function test_additive_mfa_migration_can_be_reversed_without_removing_existing_accounts(): void
    {
        $account = User::factory()->create();
        $passwordHash = $account->password;
        $migration = require database_path('migrations/2026_10_11_100719_add_mfa_credentials_to_users_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('users', 'mfa_secret'));
        $this->assertDatabaseHas('users', ['id' => $account->id, 'password' => $passwordHash, 'email' => $account->email]);
        $migration->up();
        $this->assertTrue(Schema::hasColumns('users', ['mfa_secret', 'mfa_confirmed_at', 'mfa_version', 'mfa_recovery_codes', 'mfa_last_used_step']));
        $this->assertFalse($this->reload($account)->hasMfa());
    }

    public function test_mfa_mutations_refuse_an_authenticated_request_without_a_stateful_session(): void
    {
        $account = User::factory()->create();
        $this->flushHeaders()->actingAs($account);
        $this->postJson('/api/v1/auth/mfa/enrollment', ['password' => 'password'])->assertUnauthorized();
        $this->assertFalse($this->reload($account)->hasMfa());
    }

    public function test_password_only_owner_cannot_use_business_or_broadcast_or_session_revocation(): void
    {
        $owner = User::factory()->create();
        Organization::factory()->create(['owner_user_id' => $owner->id]);
        $this->loginAccount($owner);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.mfa', [
            'required' => true, 'enabled' => false, 'verified' => false,
        ]);
        foreach (['/api/v1/organizations', '/api/v1/accessible-organizations', '/api/v1/active-organization'] as $path) {
            $this->getJson($path)->assertForbidden()->assertJsonPath('code', 'MFA_ENROLLMENT_REQUIRED');
        }
        $this->postJson('/api/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => 'private-users.'.$owner->id])
            ->assertForbidden()->assertJsonPath('code', 'MFA_ENROLLMENT_REQUIRED');
        $this->postJson('/api/v1/auth/sessions/revoke-others', ['password' => 'password'])
            ->assertForbidden()->assertJsonPath('code', 'MFA_ENROLLMENT_REQUIRED');
        $this->assertDatabaseCount('organization_access_events', 0);
    }

    public function test_administrator_requires_mfa_and_an_ordinary_account_can_continue(): void
    {
        $member = User::factory()->create();
        $membership = OrganizationMembership::factory()->create(['user_id' => $member->id]);
        $this->loginAccount($member);
        $this->getJson('/api/v1/accessible-organizations')->assertOk();
        $membership->roles = ['administrator'];
        $membership->save();
        $this->getJson('/api/v1/accessible-organizations')->assertForbidden()->assertJsonPath('code', 'MFA_ENROLLMENT_REQUIRED');
    }

    public function test_suspended_or_expired_admin_membership_does_not_create_a_privilege(): void
    {
        $member = User::factory()->create();
        OrganizationMembership::factory()->administrator()->suspended()->create(['user_id' => $member->id]);
        OrganizationMembership::factory()->administrator()->create(['user_id' => $member->id, 'ends_at' => now()->subSecond()]);
        $this->loginAccount($member);
        $this->getJson('/api/v1/auth/mfa')->assertOk()->assertJsonPath('data.required', false);
    }

    public function test_enrollment_confirms_totp_encrypts_secret_hashes_codes_and_allows_owner(): void
    {
        $owner = User::factory()->create();
        Organization::factory()->create(['owner_user_id' => $owner->id]);
        $this->loginAccount($owner);
        $secret = $this->postJson('/api/v1/auth/mfa/enrollment', ['password' => 'password'])
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data.secret');
        $this->assertFalse($this->reload($owner)->hasMfa());
        $this->getJson('/api/v1/organizations')->assertForbidden();
        $response = $this->postJson('/api/v1/auth/mfa/confirmation', ['code' => $this->code($secret)])
            ->assertOk()->assertJsonCount(10, 'data.recovery_codes')->assertJsonPath('notification.type', 'success');
        $codes = $response->json('data.recovery_codes');
        $account = $this->reload($owner);
        $this->assertTrue($account->hasMfa());
        $this->assertSame($secret, $account->mfa_secret);
        $this->assertNotSame($secret, DB::table('users')->where('id', $owner->id)->value('mfa_secret'));
        $this->assertSame(hash('sha256', $codes[0]), $account->mfa_recovery_codes[0] ?? null);
        $this->assertStringNotContainsString($codes[0], (string) DB::table('users')->where('id', $owner->id)->value('mfa_recovery_codes'));
        $this->getJson('/api/v1/organizations')->assertOk();
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.mfa.verified', true);
        $this->assertDatabaseHas('identity_security_events', ['action' => 'auth.mfa.enabled', 'user_id' => $owner->id]);
        $this->assertStringNotContainsString($secret, json_encode($account->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_unverified_email_and_wrong_password_cannot_start_enrollment(): void
    {
        $account = User::factory()->unverified()->create();
        $this->loginAccount($account);
        $this->postJson('/api/v1/auth/mfa/enrollment', ['password' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/v1/auth/mfa/enrollment', ['password' => 'password'])
            ->assertConflict()->assertJsonPath('code', 'EMAIL_VERIFICATION_REQUIRED');
        $this->assertFalse($this->reload($account)->hasMfa());
    }

    public function test_confirmation_requires_an_unexpired_enrollment_in_this_session(): void
    {
        $account = User::factory()->create();
        $this->loginAccount($account);
        $this->postJson('/api/v1/auth/mfa/confirmation', ['code' => '287082'])
            ->assertConflict()->assertJsonPath('code', 'MFA_ENROLLMENT_EXPIRED');
        $secret = $this->postJson('/api/v1/auth/mfa/enrollment', ['password' => 'password'])->assertOk()->json('data.secret');
        $this->travel(10)->minutes();
        $this->postJson('/api/v1/auth/mfa/confirmation', ['code' => $this->code($secret)])
            ->assertConflict()->assertJsonPath('code', 'MFA_ENROLLMENT_EXPIRED');
        $this->assertFalse($this->reload($account)->hasMfa());
    }

    public function test_bad_confirmation_does_not_enable_mfa_and_is_audited(): void
    {
        $account = User::factory()->create();
        $this->loginAccount($account);
        $this->postJson('/api/v1/auth/mfa/enrollment', ['password' => 'password'])->assertOk();
        $this->postJson('/api/v1/auth/mfa/confirmation', ['code' => 'invalid'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/mfa/confirmation', ['code' => '000000'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertFalse($this->reload($account)->hasMfa());
        $this->assertDatabaseHas('identity_security_events', ['action' => 'auth.mfa.challenge_failed', 'user_id' => $account->id]);
    }

    public function test_new_login_requires_challenge_and_rotates_session_on_success(): void
    {
        $account = User::factory()->withMfa()->create();
        $this->loginAccount($account);
        $oldSession = session()->getId();
        $this->getJson('/api/v1/accessible-organizations')->assertForbidden()->assertJsonPath('code', 'MFA_CHALLENGE_REQUIRED');
        $this->postJson('/api/v1/auth/mfa/challenge', ['code' => $this->code(self::SECRET)])
            ->assertOk()->assertJsonPath('data.verified', true);
        $this->assertNotSame($oldSession, session()->getId());
        $this->getJson('/api/v1/accessible-organizations')->assertOk();
        $this->assertDatabaseHas('identity_security_events', ['action' => 'auth.mfa.challenge_succeeded', 'user_id' => $account->id]);
    }

    public function test_legacy_session_without_a_security_binding_is_revoked_after_mfa_activation(): void
    {
        $account = User::factory()->withMfa()->create();
        $this->actingAs($account)->withSession([]);
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_password_revocation_after_middleware_cannot_be_bypassed_by_a_valid_totp(): void
    {
        $account = User::factory()->withMfa()->create();
        $this->loginAccount($account);
        Route::middleware(['api', 'auth:sanctum', 'identity.session'])->post('/api/testing/mfa-race',
            function (Request $request, MfaService $mfa) use ($account): void {
                $changed = $this->reload($account);
                $changed->forceFill(['password' => 'Replacement-password-123!'])->save();
                $mfa->challenge($account, $this->code(self::SECRET), null, $request);
            });
        $this->postJson('/api/testing/mfa-race')->assertUnauthorized();
        $this->assertNull($this->reload($account)->mfa_last_used_step);
        $this->assertDatabaseMissing('identity_security_events', ['action' => 'auth.mfa.challenge_succeeded', 'user_id' => $account->id]);
    }

    public function test_totp_is_not_reusable_across_sessions_and_attempts_are_account_limited(): void
    {
        $account = User::factory()->withMfa()->create();
        $this->loginAccount($account);
        $code = $this->code(self::SECRET);
        $this->postJson('/api/v1/auth/mfa/challenge', ['code' => $code])->assertOk();
        $this->loginAccount($account);
        $this->postJson('/api/v1/auth/mfa/challenge', ['code' => $code])->assertUnprocessable()->assertJsonValidationErrors('code');
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->postJson('/api/v1/auth/mfa/challenge', ['code' => '000000'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/mfa/challenge', ['code' => '000000'])->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_recovery_is_single_use_audited_and_invalidates_previous_session_proofs(): void
    {
        $recovery = '01234567-89abcdef-01234567-89abcdef';
        $account = User::factory()->withMfa()->create(['mfa_recovery_codes' => [hash('sha256', $recovery)]]);
        $this->actingAsWithMfa($account);
        $old = session()->all();
        $this->loginAccount($account);
        $this->postJson('/api/v1/auth/mfa/challenge', ['recovery_code' => $recovery])->assertOk()->assertJsonPath('data.verified', true);
        $this->assertSame([], $this->reload($account)->mfa_recovery_codes);
        $this->assertNotSame($account->mfa_version, $this->reload($account)->mfa_version);
        $this->assertDatabaseHas('identity_security_events', ['action' => 'auth.mfa.recovery_used', 'user_id' => $account->id]);
        $this->postJson('/api/v1/auth/mfa/challenge', ['recovery_code' => $recovery])->assertUnprocessable();
        $this->withSession($old);
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_recovery_deletes_other_database_sessions_only_for_this_account(): void
    {
        $recovery = '01234567-89abcdef-01234567-89abcdef';
        $account = User::factory()->withMfa()->create(['mfa_recovery_codes' => [hash('sha256', $recovery)]]);
        $other = User::factory()->create();
        DB::table('sessions')->insert([
            ['id' => 'old-account-session', 'user_id' => $account->id, 'payload' => '', 'last_activity' => now()->getTimestamp()],
            ['id' => 'other-account-session', 'user_id' => $other->id, 'payload' => '', 'last_activity' => now()->getTimestamp()],
        ]);
        config(['session.driver' => 'database']);
        $this->loginAccount($account);
        $this->postJson('/api/v1/auth/mfa/challenge', ['recovery_code' => $recovery])->assertOk();
        $this->assertDatabaseMissing('sessions', ['id' => 'old-account-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'other-account-session']);
    }

    public function test_recovery_cannot_be_combined_with_totp_and_cannot_confirm_enrollment(): void
    {
        $account = User::factory()->withMfa()->create();
        $this->loginAccount($account);
        $this->postJson('/api/v1/auth/mfa/challenge', [
            'code' => $this->code(self::SECRET), 'recovery_code' => '01234567-89abcdef-01234567-89abcdef',
        ])->assertUnprocessable()->assertJsonValidationErrors(['code', 'recovery_code']);
        $this->postJson('/api/v1/auth/mfa/confirmation', ['recovery_code' => '01234567-89abcdef-01234567-89abcdef'])
            ->assertUnprocessable()->assertJsonValidationErrors(['code', 'recovery_code']);
    }

    public function test_privileged_account_cannot_disable_mfa_and_password_only_cannot_manage_codes(): void
    {
        $owner = User::factory()->withMfa()->create();
        Organization::factory()->create(['owner_user_id' => $owner->id]);
        $this->loginAccount($owner);
        $this->postJson('/api/v1/auth/mfa/recovery-codes', ['password' => 'password'])->assertForbidden();
        $this->actingAsWithMfa($owner);
        $this->deleteJson('/api/v1/auth/mfa', ['password' => 'password'])
            ->assertConflict()->assertJsonPath('code', 'MFA_REQUIRED_FOR_PRIVILEGED_ACCOUNT');
        $this->assertTrue($this->reload($owner)->hasMfa());
    }

    public function test_ordinary_account_can_disable_with_completed_mfa_and_password(): void
    {
        $account = User::factory()->withMfa()->create();
        $this->actingAsWithMfa($account);
        $this->deleteJson('/api/v1/auth/mfa', ['password' => 'wrong'])->assertUnprocessable();
        $this->deleteJson('/api/v1/auth/mfa', ['password' => 'password'])->assertOk()->assertJsonPath('data.enabled', false);
        $this->assertNull($this->reload($account)->mfa_secret);
        $this->assertNull($this->reload($account)->mfa_recovery_codes);
        $this->getJson('/api/v1/accessible-organizations')->assertOk();
        $this->assertDatabaseHas('identity_security_events', ['action' => 'auth.mfa.disabled', 'user_id' => $account->id]);
    }

    public function test_regeneration_revokes_old_codes_and_preserves_current_session(): void
    {
        $oldCode = '01234567-89abcdef-01234567-89abcdef';
        $account = User::factory()->withMfa()->create(['mfa_recovery_codes' => [hash('sha256', $oldCode)]]);
        $this->actingAsWithMfa($account);
        $this->postJson('/api/v1/auth/mfa/recovery-codes', ['password' => 'password'])
            ->assertOk()->assertJsonCount(10, 'data.recovery_codes')->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson('/api/v1/auth/mfa/challenge', ['recovery_code' => $oldCode])->assertUnprocessable();
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.mfa.verified', true);
        $this->assertDatabaseHas('identity_security_events', ['action' => 'auth.mfa.recovery_regenerated', 'user_id' => $account->id]);
    }

    public function test_recovered_privileged_user_can_replace_authenticator_without_disabling_mfa(): void
    {
        $account = User::factory()->withMfa()->create();
        Organization::factory()->create(['owner_user_id' => $account->id]);
        $this->actingAsWithMfa($account);
        $secret = $this->postJson('/api/v1/auth/mfa/enrollment', ['password' => 'password'])->assertOk()->json('data.secret');
        $this->assertSame(self::SECRET, $this->reload($account)->mfa_secret);
        $this->postJson('/api/v1/auth/mfa/confirmation', ['code' => $this->code($secret)])->assertOk();
        $this->assertSame($secret, $this->reload($account)->mfa_secret);
        $this->assertNotSame($account->mfa_version, $this->reload($account)->mfa_version);
        $this->getJson('/api/v1/organizations')->assertOk();
    }

    public function test_password_reset_preserves_mfa_and_invalidates_old_mfa_proof(): void
    {
        Notification::fake();
        $account = User::factory()->withMfa()->create();
        $this->actingAsWithMfa($account);
        $old = session()->all();
        $token = app('auth.password.broker')->createToken($account);
        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $account->email, 'token' => $token,
            'password' => 'New-password-456!', 'password_confirmation' => 'New-password-456!',
        ])->assertOk();
        $this->assertSame(self::SECRET, $this->reload($account)->mfa_secret);
        $this->assertTrue($this->reload($account)->hasMfa());
        Auth::forgetGuards();
        $this->actingAs($this->reload($account))->withSession($old);
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        Notification::assertNothingSent();
    }

    public function test_revoking_other_sessions_preserves_the_current_mfa_verification(): void
    {
        $account = User::factory()->withMfa()->create();
        $this->actingAsWithMfa($account);
        $this->postJson('/api/v1/auth/sessions/revoke-others', ['password' => 'password'])->assertOk();
        $this->assertSame(app(MfaSession::class)->fingerprint($this->reload($account)), session()->get(MfaSession::ACCOUNT_KEY)['password']);
        $this->assertSame(Auth::guard('web')->hashPasswordForCookie($this->reload($account)->password), session()->get('password_hash_web'));
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.mfa.verified', true);
    }

    public function test_horizon_requires_a_live_mfa_proof_even_for_an_allowlisted_operator(): void
    {
        $operator = User::factory()->withMfa()->create();
        PlatformRole::factory()->superadmin()->create(['user_id' => $operator->id]);
        config(['horizon.authorized_emails' => [$operator->email]]);
        $this->loginAccount($operator);
        $this->assertFalse(Gate::forUser($operator)->allows('viewHorizon'));
        $this->actingAsWithMfa($operator);
        $this->getJson('/api/v1/auth/me')->assertOk();
        $this->assertTrue(Gate::forUser($operator)->allows('viewHorizon'));
        $operator->forceFill(['mfa_version' => (string) Str::uuid()])->save();
        $this->assertFalse(Gate::forUser($operator)->allows('viewHorizon'));
    }

    public function test_a_privileged_job_actor_without_mfa_fails_before_execution(): void
    {
        $account = User::factory()->create();
        $organization = Organization::factory()->create(['owner_user_id' => $account->id]);
        $context = new JobContext((string) Str::uuid(), $organization->id, $account->id);
        $this->expectException(TenantExecutionRejectedException::class);
        $this->expectExceptionMessage(__('tenancy.errors.background_rejected'));
        app(TenantJobRunner::class)->user($context, function (): never {
            $this->fail('A privileged actor without MFA must not execute work.');
        });
    }

    private function reload(User $user): User
    {
        return User::query()->whereKey($user->id)->firstOrFail();
    }

    private function loginAccount(User $user): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
    }

    private function code(string $secret): string
    {
        return (new Totp)->code($secret, now()->getTimestamp());
    }
}
