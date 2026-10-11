<?php

namespace Tests\Feature;

use App\Modules\Identity\Application\Services\PlatformRoleService;
use App\Modules\Identity\Domain\Models\PlatformRole;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\PlatformRoleType;
use App\Modules\Tenancy\Application\Contracts\FieldVisibility;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Contracts\TenantDataRecord;
use App\Modules\Tenancy\Application\Contracts\TenantDataVisibility;
use App\Modules\Tenancy\Application\Data\OrganizationAuditData;
use App\Modules\Tenancy\Application\Services\OrganizationPortabilityService;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use App\Modules\Tenancy\Domain\Models\OrganizationSupportAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\TestCase;

class PlatformSupportAccessTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase;

    public function test_guests_and_tenant_owners_cannot_open_support_access(): void
    {
        $org = Organization::factory()->withMfaOwner()->create();
        $this->postJson($this->path($org), $this->payload())->assertUnauthorized();
        $this->actingAsWithMfa(User::findOrFail($org->owner_user_id));
        $this->postJson($this->path($org), $this->payload())->assertForbidden();
        $this->assertFalse(Gate::allows('operate', OrganizationSupportAccess::class));
        $this->assertDatabaseCount('organization_support_accesses', 0);
        $this->assertDatabaseCount('organization_support_events', 0);
    }

    public function test_platform_operator_needs_a_current_session_mf_a_proof(): void
    {
        $role = PlatformRole::factory()->create();
        $org = Organization::factory()->create();
        $user = User::findOrFail($role->user_id);
        $this->withHeaders(['Origin' => 'http://localhost:5173']);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();

        $this->postJson($this->path($org), $this->payload())->assertForbidden()->assertJsonPath('code', 'MFA_CHALLENGE_REQUIRED');

        $this->assertDatabaseCount('organization_support_accesses', 0);
    }

    public function test_operator_opens_only_explicit_actions_and_every_diagnostic_is_audited(): void
    {
        $this->freezeTime();
        $this->operator();
        $org = Organization::factory()->create();
        $access = $this->postJson($this->path($org), $this->payload())->assertCreated()
            ->assertJsonPath('data.reason', 'Diagnostic incident de connexion')->assertJsonPath('notification.type', 'success')->json('data');
        $diagnostic = $this->getJson($this->path($org, $access['id'].'/organization'))->assertOk();
        $this->assertSame(['organization_id' => $org->id, 'status' => 'active', 'timezone' => $org->timezone, 'language' => $org->language], $diagnostic->json('data'));
        $this->assertSame($diagnostic->headers->get('X-Request-ID'), $diagnostic->json('meta.request_id'));
        $this->getJson($this->path($org, $access['id'].'/access-summary'))->assertForbidden();
        $this->assertDatabaseCount('organization_support_events', 3);
        $this->assertDatabaseHas('organization_support_events', ['support_access_id' => $access['id'], 'action' => 'organization.inspect', 'organization_id' => $org->id]);
        $this->assertDatabaseHas('organization_support_events', ['support_access_id' => $access['id'], 'action' => 'access.inspect.denied']);
        $this->getJson('/api/v1/tenants/'.$org->id.'/context')->assertNotFound();
    }

    public function test_access_summary_returns_only_counts_for_its_own_tenant(): void
    {
        $this->operator();
        $org = Organization::factory()->create();
        OrganizationMembership::factory()->create(['organization_id' => $org->id]);
        OrganizationMembership::factory()->count(3)->create();
        $access = $this->postJson($this->path($org), [...$this->payload(), 'actions' => ['access.inspect']])->assertCreated()->json('data');

        $this->getJson($this->path($org, $access['id'].'/access-summary'))->assertOk()
            ->assertJsonPath('data.memberships', 1)->assertJsonPath('data.roles', 0)->assertJsonPath('data.role_assignments', 0)
            ->assertJsonMissingPath('data.email')->assertJsonMissingPath('data.owner_user_id');
    }

    public function test_expiration_is_enforced_at_the_deadline_without_a_scheduler(): void
    {
        $this->freezeTime();
        $this->operator();
        $org = Organization::factory()->create();
        $access = $this->postJson($this->path($org), $this->payload())->assertCreated()->json('data');
        $this->travel(15)->minutes();

        $this->getJson($this->path($org, $access['id'].'/organization'))->assertForbidden();
        $this->getJson('/api/v1/platform/support-accesses')->assertOk()->assertJsonPath('data.0.status', 'expired');
        $this->assertDatabaseHas('organization_support_events', ['support_access_id' => $access['id'], 'action' => 'organization.inspect.denied']);
    }

    public function test_revocation_is_immediate_and_repeating_it_does_not_duplicate_the_audit(): void
    {
        $this->operator();
        $org = Organization::factory()->create();
        $access = $this->postJson($this->path($org), $this->payload())->assertCreated()->json('data');
        $this->deleteJson($this->path($org, $access['id']))->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->deleteJson($this->path($org, $access['id']))->assertOk();
        $this->getJson($this->path($org, $access['id'].'/organization'))->assertForbidden();
        $this->assertDatabaseCount('organization_support_events', 3);
        $this->assertDatabaseHas('organization_support_accesses', ['id' => $access['id'], 'status' => 'revoked']);
    }

    public function test_foreign_tenant_or_operator_cannot_reuse_a_known_access_id(): void
    {
        $this->operator();
        $org = Organization::factory()->create();
        $foreign = Organization::factory()->create();
        $access = $this->postJson($this->path($org), $this->payload())->assertCreated()->json('data');
        $this->getJson($this->path($foreign, $access['id'].'/organization'))->assertNotFound();
        $this->operator();
        $this->getJson($this->path($org, $access['id'].'/organization'))->assertNotFound();
        $this->deleteJson($this->path($org, $access['id']))->assertNotFound();
        $this->getJson('/api/v1/platform/support-accesses')->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('organization_support_events', 1);
    }

    public function test_revoking_and_regranting_the_platform_role_does_not_revive_a_support_access(): void
    {
        $admin = User::factory()->withMfa()->create();
        $roles = app(PlatformRoleService::class);
        $roles->configure($admin->id, PlatformRoleType::Superadmin, true, null, 'Initialisation opérateur approuvée', (string) Str::uuid(), true);
        $support = User::factory()->withMfa()->create();
        $roles->configure($support->id, PlatformRoleType::Support, true, $admin->id, 'Assistance incident autorisée', (string) Str::uuid());
        $this->actingAsWithMfa($support);
        $org = Organization::factory()->create();
        $access = $this->postJson($this->path($org), $this->payload())->assertCreated()->json('data');
        $roles->configure($support->id, PlatformRoleType::Support, false, $admin->id, 'Fin de mission assistance', (string) Str::uuid());
        $this->getJson($this->path($org, $access['id'].'/organization'))->assertForbidden();
        $roles->configure($support->id, PlatformRoleType::Support, true, $admin->id, 'Nouvelle mission assistance', (string) Str::uuid());

        $this->getJson($this->path($org, $access['id'].'/organization'))->assertForbidden();

        $this->assertDatabaseHas('organization_support_events', ['support_access_id' => $access['id'], 'action' => 'organization.inspect.denied']);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidPayloads(): array
    {
        return ['no reason' => [['reason' => ''], 'reason'], 'short reason' => [['reason' => 'court'], 'reason'],
            'too long reason' => [['reason' => str_repeat('a', 501)], 'reason'],
            'too long duration' => [['duration_minutes' => 31], 'duration_minutes'], 'zero duration' => [['duration_minutes' => 0], 'duration_minutes'],
            'fractional duration' => [['duration_minutes' => 1.5], 'duration_minutes'], 'no action' => [['actions' => []], 'actions'],
            'business action' => [['actions' => ['financial.validate']], 'actions.0'], 'wildcard' => [['actions' => ['*']], 'actions.0'],
            'duplicate action' => [['actions' => ['organization.inspect', 'organization.inspect']], 'actions.0'],
            'actor spoof' => [['actor_user_id' => 123], 'actor_user_id'], 'tenant spoof' => [['organization_id' => 'other'], 'organization_id'],
            'end time spoof' => [['expires_at' => '2099-01-01'], 'expires_at'], 'missing confirmation' => [['confirm' => false], 'confirm']];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('invalidPayloads')]
    public function test_invalid_support_request_returns_422_without_opening_access(array $override, string $field): void
    {
        $this->operator();
        $org = Organization::factory()->create();

        $response = $this->postJson($this->path($org), [...$this->payload(), ...$override])->assertUnprocessable()->assertJsonValidationErrors($field);

        $message = match (true) {
            $field === 'reason' => 'platform.reason',
            $field === 'duration_minutes' => 'platform.duration',
            str_starts_with($field, 'actions') => 'platform.actions',
            $field === 'confirm' => 'platform.confirm',
            default => 'platform.reserved',
        };
        $this->assertSame(__($message), $response->json('errors')[$field][0]);
        $this->assertDatabaseCount('organization_support_accesses', 0);
        $this->assertDatabaseCount('organization_support_events', 0);
    }

    public function test_technical_account_cannot_use_business_grants_or_expose_financial_fields(): void
    {
        $operator = $this->operator();
        $membership = OrganizationMembership::factory()->create(['user_id' => $operator->user_id]);
        $role = OrganizationRole::factory()->privileged()->create(['organization_id' => $membership->organization_id,
            'permissions' => ['financial.read', 'financial.validate', 'administrative.validate', 'sports.publish']]);
        OrganizationRoleAssignment::factory()->create(['organization_id' => $membership->organization_id,
            'membership_id' => $membership->id, 'user_id' => $membership->user_id, 'role_id' => $role->id]);
        $org = Organization::findOrFail($membership->organization_id);
        $context = new TenantContext($org->id, $membership->user_id, $org->slug, 'UTC', 'fr', (string) Str::uuid());
        $authorization = app(TenantAuthorization::class);
        foreach (['financial.read', 'financial.validate', 'administrative.validate', 'sports.publish'] as $permission) {
            $this->assertFalse($authorization->allows($context, $permission));
        }
        $this->assertSame(['organization.view'], $authorization->permissions($context));

        $data = app(TenantDataVisibility::class)->project($context, new TenantDataRecord($org->id, ['salary' => 1000]), ['salary' => new FieldVisibility('financial.read')]);

        $this->assertSame([], $data);
    }

    public function test_support_audit_cannot_be_modified_through_the_database(): void
    {
        $this->operator();
        $org = Organization::factory()->create();
        $this->postJson($this->path($org), $this->payload())->assertCreated();
        $this->expectException(QueryException::class);

        DB::table('organization_support_events')->delete();
    }

    public function test_technical_account_cannot_export_business_backup_even_as_tenant_owner(): void
    {
        $role = $this->operator();
        $org = Organization::factory()->create(['owner_user_id' => $role->user_id]);
        try {
            app(OrganizationPortabilityService::class)->requestExport($org->id,
                new OrganizationAuditData($role->user_id, 'Sauvegarde demandée par le support', (string) Str::uuid()));
            $this->fail('A technical account must not export a complete business backup.');
        } catch (AuthorizationException $exception) {
            $this->assertSame(__('platform.backup_forbidden'), $exception->getMessage());
        }
        $this->assertDatabaseCount('tenant_portability_operations', 0);
    }

    public function test_database_refuses_changing_the_tenant_or_expiration_of_an_access(): void
    {
        $this->operator();
        $org = Organization::factory()->create();
        $access = $this->postJson($this->path($org), $this->payload())->assertCreated()->json('data');
        $this->expectException(QueryException::class);

        DB::table('organization_support_accesses')->where('id', $access['id'])->update(['expires_at' => now()->addMinutes(20)]);
    }

    public function test_complete_backup_keeps_only_its_support_history_and_excludes_platform_roles(): void
    {
        $this->operator();
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $this->postJson($this->path($org), $this->payload())->assertCreated();
        $this->postJson($this->path($other), $this->payload())->assertCreated();

        $rows = iterator_to_array(app(PortabilityRepository::class)->rows($org->id, 100));

        $support = array_values(array_filter($rows, fn (array $row): bool => str_starts_with($row['table'], 'organization_support_')));
        $this->assertCount(2, $support);
        foreach ($support as $row) {
            $this->assertSame($org->id, $row['data']['organization_id']);
        }
        $this->assertNotContains('platform_roles', array_column($rows, 'table'));
        $this->assertNotContains('platform_role_events', array_column($rows, 'table'));
    }

    private function operator(): PlatformRole
    {
        $role = PlatformRole::factory()->create();
        $this->actingAsWithMfa(User::findOrFail($role->user_id));
        $this->withHeader('X-Request-Id', '00000000-0000-4000-8000-000000000005');

        return $role;
    }

    public function test_support_role_cannot_access_horizon_even_with_its_email_allowlisted(): void
    {
        $role = $this->operator();
        $user = User::findOrFail($role->user_id);
        config(['horizon.authorized_emails' => [$user->email]]);
        $this->getJson('/api/v1/auth/me')->assertOk();

        $this->assertFalse(Gate::forUser($user)->allows('viewHorizon'));
    }

    public function test_changing_mf_a_invalidates_the_support_session_proof(): void
    {
        $role = $this->operator();
        $org = Organization::factory()->create();
        $access = $this->postJson($this->path($org), $this->payload())->assertCreated()->json('data');
        User::findOrFail($role->user_id)->forceFill(['mfa_version' => (string) Str::uuid()])->save();

        $this->getJson($this->path($org, $access['id'].'/organization'))->assertUnauthorized()->assertJsonPath('code', 'AUTHENTICATION_REQUIRED');

        $this->assertDatabaseCount('organization_support_events', 1);
    }

    public function test_horizon_email_allowlist_does_not_grant_a_platform_role(): void
    {
        $user = User::factory()->withMfa()->create();
        config(['horizon.authorized_emails' => [$user->email]]);
        $this->actingAsWithMfa($user);
        $this->getJson('/api/v1/auth/me')->assertOk();

        $this->assertFalse(Gate::forUser($user)->allows('viewHorizon'));
        $this->assertDatabaseCount('platform_roles', 0);
    }

    public function test_platform_roles_require_mf_a_enrollment_even_without_any_tenant_membership(): void
    {
        $user = User::factory()->create();
        PlatformRole::factory()->create(['user_id' => $user->id]);
        $org = Organization::factory()->create();
        $this->withHeaders(['Origin' => 'http://localhost:5173']);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();

        $this->postJson($this->path($org), $this->payload())->assertForbidden()->assertJsonPath('code', 'MFA_ENROLLMENT_REQUIRED');

        $this->assertDatabaseCount('organization_support_accesses', 0);
    }

    public function test_audit_failure_rolls_back_the_new_access(): void
    {
        $this->operator();
        $org = Organization::factory()->create();
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER reject_support_audit BEFORE INSERT ON organization_support_events BEGIN SELECT RAISE(ABORT, 'Audit unavailable'); END");
        } else {
            DB::unprepared("CREATE FUNCTION reject_support_audit() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'Audit unavailable'; END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER reject_support_audit BEFORE INSERT ON organization_support_events FOR EACH ROW EXECUTE FUNCTION reject_support_audit()");
        }
        try {
            $this->postJson($this->path($org), $this->payload())->assertInternalServerError();
            $this->assertDatabaseCount('organization_support_accesses', 0);
            $this->assertDatabaseCount('organization_support_events', 0);
        } finally {
            DB::unprepared('DROP TRIGGER reject_support_audit'.(DB::getDriverName() === 'pgsql' ? ' ON organization_support_events' : ''));
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared('DROP FUNCTION reject_support_audit()');
            }
        }
    }

    public function test_active_accesses_are_bounded_for_an_operator_and_tenant(): void
    {
        $this->operator();
        $org = Organization::factory()->create();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson($this->path($org), $this->payload())->assertCreated();
        }

        $this->postJson($this->path($org), $this->payload())->assertConflict()->assertJsonPath('code', 'SUPPORT_ACCESS_LIMIT');

        $this->assertDatabaseCount('organization_support_accesses', 5);
        $this->assertDatabaseCount('organization_support_events', 5);
    }

    public function test_database_rejects_an_audit_subject_from_another_tenant(): void
    {
        $access = OrganizationSupportAccess::factory()->create();
        $other = Organization::factory()->create();
        $this->expectException(QueryException::class);

        DB::table('organization_support_events')->insert(['id' => (string) Str::uuid(), 'organization_id' => $other->id,
            'support_access_id' => $access->id, 'actor_user_id' => $access->actor_user_id, 'action' => 'opened',
            'request_id' => (string) Str::uuid(), 'occurred_at' => now()]);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidDatabaseAccesses(): array
    {
        return ['business action' => [['actions' => ['financial.validate']]], 'wildcard' => [['actions' => ['*']]],
            'duplicate actions' => [['actions' => ['organization.inspect', 'organization.inspect']]],
            'duration over thirty minutes' => [['expires_at' => '2026-10-11 00:31:00']]];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('invalidDatabaseAccesses')]
    public function test_database_refuses_an_access_with_unsafe_permissions_or_duration(array $override): void
    {
        $this->expectException(QueryException::class);

        OrganizationSupportAccess::factory()->create(['starts_at' => '2026-10-11 00:00:00', 'expires_at' => '2026-10-11 00:15:00', ...$override]);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['reason' => 'Diagnostic incident de connexion', 'duration_minutes' => 15, 'actions' => ['organization.inspect'], 'confirm' => true];
    }

    private function path(Organization $org, string $suffix = ''): string
    {
        return '/api/v1/platform/tenants/'.$org->id.'/support-accesses'.($suffix === '' ? '' : '/'.$suffix);
    }
}
