<?php

namespace Tests\Feature;

use App\Exceptions\DomainConflictException;
use App\Modules\Identity\Application\Services\PlatformRoleService;
use App\Modules\Identity\Application\Services\SecurityAuditService;
use App\Modules\Identity\Domain\Models\IdentityAuditEvent;
use App\Modules\Identity\Domain\Models\PlatformRole;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\PlatformRoleType;
use App\Modules\Identity\Domain\SecurityEventAction;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Domain\Contracts\AccessAuditRepository;
use App\Modules\Tenancy\Domain\Contracts\OrganizationRepository;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationAuditEvent;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditRetentionPolicy;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\TestCase;

class AuditJournalTest extends TestCase
{
    use AuthenticatesWithMfa,LazilyRefreshDatabase;

    public function test_guests_and_tenant_owners_cannot_read_the_global_journal_and_refusal_contains_no_payload(): void
    {
        $this->getJson('/api/v1/platform/audit-events')->assertUnauthorized();
        $user = User::factory()->withMfa()->create();
        Organization::factory()->create(['owner_user_id' => $user->id]);
        $this->actingAsWithMfa($user);
        $response = $this->getJson('/api/v1/platform/audit-events?password=SHOULD_NOT_BE_LOGGED')->assertForbidden();
        $this->assertFalse(Gate::allows('viewAny', IdentityAuditEvent::class));
        $this->assertDatabaseHas('identity_audit_events', ['actor_user_id' => $user->id, 'action' => 'access.denied',
            'target_id' => 'api.v1.platform.audit.index', 'request_id' => $response->headers->get('X-Request-ID')]);
        $row = DB::table('identity_audit_events')->first();
        $this->assertStringNotContainsString('SHOULD_NOT_BE_LOGGED', json_encode($row, JSON_THROW_ON_ERROR));
        $this->assertDatabaseCount('identity_audit_events', 1);
    }

    public function test_support_role_and_technical_tenant_owner_cannot_read_a_tenant_or_platform_journal(): void
    {
        $role = PlatformRole::factory()->create();
        $user = User::findOrFail($role->user_id);
        $org = Organization::factory()->create(['owner_user_id' => $user->id]);
        $this->actingAsWithMfa($user);
        $this->getJson('/api/v1/platform/audit-events')->assertForbidden();
        $this->getJson($this->path($org))->assertForbidden();
        $this->assertDatabaseCount('organization_audit_events', 0);
    }

    public function test_superadmin_reads_security_events_without_subject_hash_agent_email_or_request_body(): void
    {
        $user = $this->superadmin();
        $request = Request::create('/auth/forgot-password', 'POST', ['password' => 'REQUEST_SECRET'], server: ['HTTP_USER_AGENT' => 'AGENT_SECRET']);
        app(SecurityAuditService::class)->record(SecurityEventAction::PasswordResetRequested, $request, $user, 'secret@example.com');

        $response = $this->getJson('/api/v1/platform/audit-events?source=identity.security')->assertOk()->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.actor_user_id', null)->assertJsonPath('data.0.target.id', (string) $user->id)
            ->assertJsonPath('data.0.reason_code', 'authentication_event')->assertJsonPath('meta.retention.mode', 'unlimited');
        $json = json_encode($response->json(), JSON_THROW_ON_ERROR);
        foreach (['AGENT_SECRET', 'REQUEST_SECRET', 'secret@example.com', 'subject_hash', 'user_agent'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertDatabaseHas('identity_audit_events', ['actor_user_id' => $user->id, 'action' => 'journal.viewed', 'target_id' => 'platform']);
    }

    public function test_platform_role_audit_preserves_useful_before_after_without_free_form_reason(): void
    {
        $admin = User::factory()->withMfa()->create();
        $target = User::factory()->withMfa()->create();
        $roles = app(PlatformRoleService::class);
        $roles->configure($admin->id, PlatformRoleType::Superadmin, true, null, 'Initialisation approuvée', (string) Str::uuid(), true);
        $roles->configure($target->id, PlatformRoleType::Support, true, $admin->id, 'PRIVATE_CONTRACT_AND_SECRET', (string) Str::uuid());
        $this->actingAsWithMfa($admin);

        $response = $this->getJson('/api/v1/platform/audit-events?source=identity.platform_roles')->assertOk()->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.action', 'platform.role.changed')->assertJsonPath('data.0.after.role', 'support')
            ->assertJsonPath('data.0.after.active', true)->assertJsonPath('data.0.target.id', (string) $target->id);
        $this->assertStringNotContainsString('PRIVATE_CONTRACT_AND_SECRET', json_encode($response->json(), JSON_THROW_ON_ERROR));
    }

    public function test_tenant_journal_projects_legacy_state_and_never_includes_another_tenant(): void
    {
        $org = $this->owner();
        $role = OrganizationRole::factory()->create(['organization_id' => $org->id]);
        app(AccessAuditRepository::class)->record(['organization_id' => $org->id, 'actor_user_id' => $org->owner_user_id,
            'action' => 'role.created', 'subject_id' => $role->id, 'before' => null,
            'after' => ['name' => 'SECRET_CONTRACT', 'salary' => 123456, 'email' => 'secret@example.com', 'permissions' => ['sports.read'], 'status' => 'active'],
            'request_id' => (string) Str::uuid(), 'occurred_at' => now()]);
        OrganizationAuditEvent::factory()->create();

        $response = $this->getJson($this->path($org).'?source=tenancy.access')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.organization_id', $org->id)->assertJsonPath('data.0.after.permissions', ['sports.read'])
            ->assertJsonPath('data.0.after.status', 'active')->assertJsonPath('data.0.target.id', $role->id);
        foreach (['SECRET_CONTRACT', 'SECRET_REASON', '123456', 'secret@example.com'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($response->json(), JSON_THROW_ON_ERROR));
        }
        $this->assertDatabaseHas('organization_audit_events', ['organization_id' => $org->id, 'action' => 'journal.viewed']);
    }

    public function test_admin_has_governance_audit_access_but_an_ordinary_member_needs_an_effective_grant(): void
    {
        $org = Organization::factory()->withMfaOwner()->create();
        $admin = OrganizationMembership::factory()->create(['organization_id' => $org->id, 'roles' => ['administrator']]);
        $member = OrganizationMembership::factory()->create(['organization_id' => $org->id]);
        $this->actingAsWithMfa(User::findOrFail($admin->user_id));
        $this->getJson($this->path($org))->assertOk();
        $this->actingAsWithMfa(User::findOrFail($member->user_id));
        $this->getJson($this->path($org))->assertForbidden();
        $role = OrganizationRole::factory()->privileged()->create(['organization_id' => $org->id, 'permissions' => ['audit.view']]);
        $assignment = OrganizationRoleAssignment::factory()->create(['organization_id' => $org->id, 'membership_id' => $member->id, 'user_id' => $member->user_id, 'role_id' => $role->id]);
        $this->getJson($this->path($org))->assertOk();
        $assignment->forceFill(['status' => 'revoked'])->save();
        $this->getJson($this->path($org))->assertForbidden();
    }

    public function test_foreign_tenant_is_404_even_for_a_platform_superadmin(): void
    {
        $this->superadmin();
        $foreign = Organization::factory()->withMfaOwner()->create();
        $this->getJson($this->path($foreign))->assertNotFound();
        $this->assertDatabaseHas('identity_audit_events', ['action' => 'access.denied', 'target_id' => 'api.v1.tenants.audit.index']);
        $this->assertDatabaseCount('organization_audit_events', 0);
    }

    public function test_mfa_proof_is_required_to_read_the_global_journal(): void
    {
        $role = PlatformRole::factory()->superadmin()->create();
        $user = User::findOrFail($role->user_id);
        $this->withHeaders(['Origin' => 'http://localhost:5173']);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $this->getJson('/api/v1/platform/audit-events')->assertForbidden()->assertJsonPath('code', 'MFA_CHALLENGE_REQUIRED');
    }

    public function test_cursor_walks_equal_timestamps_across_sources_without_duplicates_and_refuses_scope_replay(): void
    {
        $this->freezeTime();
        $org = $this->owner();
        $expected = [];
        for ($i = 0; $i < 5; $i++) {
            $row = OrganizationAuditEvent::factory()->create(['organization_id' => $org->id, 'actor_user_id' => $org->owner_user_id, 'occurred_at' => now()->subDay()]);
            $expected[] = $row->id;
        }
        $lifecycleId = (string) Str::uuid();
        app(OrganizationRepository::class)->recordLifecycleEvent(['id' => $lifecycleId, 'organization_id' => $org->id, 'actor_user_id' => $org->owner_user_id,
            'action' => 'created', 'from_status' => null, 'to_status' => 'active', 'reason' => 'Fixture lifecycle', 'request_id' => (string) Str::uuid(), 'occurred_at' => now()->subDay()]);
        $lifecycle = DB::table('organization_lifecycle_events')->where('organization_id', $org->id)->value('id');
        $expected[] = $lifecycle;
        $first = $this->getJson($this->path($org).'?per_page=2')->assertOk()->assertJsonCount(2, 'data');
        $cursor = $first->json('meta.next_cursor');
        $seen = array_column($first->json('data'), 'id');
        $other = Organization::factory()->create(['owner_user_id' => $org->owner_user_id]);
        $this->getJson($this->path($other).'?cursor='.urlencode($cursor))->assertUnprocessable()->assertJsonPath('errors.cursor.0', __('audit.invalid_cursor'));
        $this->getJson($this->path($org).'?action=domain.changed&cursor='.urlencode($cursor))->assertUnprocessable();
        while ($cursor !== null) {
            $page = $this->getJson($this->path($org).'?per_page=2&cursor='.urlencode($cursor))->assertOk();
            $seen = [...$seen, ...array_column($page->json('data'), 'id')];
            $cursor = $page->json('meta.next_cursor');
            $this->assertLessThanOrEqual(8, count($seen));
        }
        sort($seen);
        sort($expected);
        $this->assertSame($expected, $seen);
    }

    public function test_cursor_is_bound_to_the_authenticated_reviewer(): void
    {
        $org = $this->owner();
        OrganizationAuditEvent::factory()->count(3)->create(['organization_id' => $org->id]);
        $first = $this->getJson($this->path($org).'?source=tenancy.audit&action=domain.changed&per_page=1')->assertOk();
        $admin = OrganizationMembership::factory()->create(['organization_id' => $org->id, 'roles' => ['administrator']]);
        $this->actingAsWithMfa(User::findOrFail($admin->user_id));
        $this->getJson($this->path($org).'?source=tenancy.audit&action=domain.changed&cursor='.urlencode($first->json('meta.next_cursor')))->assertUnprocessable();
    }

    /** @return array<string,array{array<string,mixed>,string}> */
    public static function invalidFilters(): array
    {
        return ['too many' => [['per_page' => 101], 'per_page'], 'zero page size' => [['per_page' => 0], 'per_page'],
            'offset' => [['page' => 1000000], 'page'], 'foreign source' => [['source' => 'identity.security'], 'source'],
            'unknown source' => [['source' => 'users'], 'source'], 'SQL action' => [['action' => "x' OR 1=1"], 'action'],
            'invalid actor' => [['actor_user_id' => 0], 'actor_user_id'], 'invalid date' => [['from' => 'not-a-date'], 'from'],
            'reverse dates' => [['from' => '2026-10-11T12:00:00+00:00', 'until' => '2026-10-10T12:00:00+00:00'], 'until'],
            'tenant override' => [['organization_id' => 'spoof'], 'organization_id']];
    }

    /** @param array<string,mixed> $query */
    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_are_422_and_do_not_record_a_successful_review(array $query, string $field): void
    {
        $org = $this->owner();
        $this->getJson($this->path($org).'?'.http_build_query($query))->assertUnprocessable()
            ->assertJsonPath('errors.'.$field.'.0', __('audit.invalid_filter'));
        $this->assertDatabaseCount('organization_audit_events', 0);
    }

    public function test_tampered_cursor_is_422(): void
    {
        $org = $this->owner();
        $this->getJson($this->path($org).'?cursor=invalid')->assertUnprocessable()->assertJsonPath('errors.cursor.0', __('audit.invalid_cursor'));
    }

    /** @return array<string,array{array<string,mixed>}> */
    public static function unsafeStates(): array
    {
        return ['password' => [['password' => 'SECRET']], 'nested money' => [['salary' => 12345]], 'email' => [['email' => 'secret@example.com']],
            'secret in status' => [['status' => 'SECRET']], 'secret in permissions' => [['permissions' => ['SECRET']]],
            'invalid role' => [['role' => 'root']], 'script date' => [['starts_at' => '<script>']], 'oversized list' => [['roles' => ['member', 'administrator', 'member']]]];
    }

    /** @param array<string,mixed> $state */
    #[DataProvider('unsafeStates')]
    public function test_writer_rejects_unknown_or_unsafe_state_before_persistence(array $state): void
    {
        $this->expectException(ValidationException::class);
        new AuditWriteData(null, AuditAction::DomainChanged, AuditTarget::Journal, 'platform', AuditReason::Domain, (string) Str::uuid(), after: $state);
    }

    public function test_generic_events_preserve_typed_metadata_and_safe_state(): void
    {
        $org = $this->owner();
        $requestId = (string) Str::uuid();
        app(TenantAuditJournal::class)->record($org->id, new AuditWriteData($org->owner_user_id, AuditAction::DomainChanged,
            AuditTarget::Organization, $org->id, AuditReason::Domain, $requestId, '127.0.0.1', before: ['status' => 'active'], after: ['status' => 'suspended']));
        $this->getJson($this->path($org).'?source=tenancy.audit&action=domain.changed')->assertOk()->assertJsonPath('data.0.before.status', 'active')
            ->assertJsonPath('data.0.after.status', 'suspended')->assertJsonPath('data.0.request_id', $requestId);
    }

    public function test_global_audit_is_not_a_gateway_to_tenant_audits(): void
    {
        OrganizationAuditEvent::factory()->create();
        $this->superadmin();
        $this->getJson('/api/v1/platform/audit-events')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/platform/audit-events?source=tenancy.audit')->assertUnprocessable();
    }

    public function test_export_includes_only_own_tenant_audit_and_excludes_global_journals(): void
    {
        $org = Organization::factory()->create();
        OrganizationAuditEvent::factory()->create(['organization_id' => $org->id]);
        OrganizationAuditEvent::factory()->create();
        IdentityAuditEvent::factory()->create();
        $rows = iterator_to_array(app(PortabilityRepository::class)->rows($org->id, 1000));
        $audits = array_values(array_filter($rows, fn (array $row): bool => $row['table'] === 'organization_audit_events'));
        $this->assertCount(1, $audits);
        $this->assertSame($org->id, $audits[0]['data']['organization_id']);
        $this->assertNotContains('identity_audit_events', array_column($rows, 'table'));
    }

    public function test_sql_refuses_mutation_of_both_audit_journals(): void
    {
        IdentityAuditEvent::factory()->create();
        OrganizationAuditEvent::factory()->create();
        foreach (['identity_audit_events', 'organization_audit_events'] as $table) {
            foreach (['update', 'delete'] as $operation) {
                try {
                    DB::transaction(fn () => $operation === 'delete' ? DB::table($table)->delete() : DB::table($table)->update(['reason_code' => 'domain_change']));
                    $this->fail('Ordinary writes must not mutate audit.');
                } catch (QueryException) {
                    $this->assertDatabaseCount($table, 1);
                }
            }
        }

    }

    /** @return array<string,array{bool}> */
    public static function modelKinds(): array
    {
        return ['global' => [true], 'tenant' => [false]];
    }

    #[DataProvider('modelKinds')]
    public function test_eloquent_refuses_audit_deletion(bool $global): void
    {
        $event = $global ? IdentityAuditEvent::factory()->create() : OrganizationAuditEvent::factory()->create();
        $this->expectException(DomainConflictException::class);
        $this->expectExceptionMessage(__('audit.immutable'));
        $event->delete();
    }

    public function test_unlimited_retention_keeps_old_events_and_refuses_purge_configuration(): void
    {
        $org = $this->owner();
        OrganizationAuditEvent::factory()->create(['organization_id' => $org->id, 'occurred_at' => now()->subYears(10)]);
        $this->getJson($this->path($org).'?action=domain.changed')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.retention.purge_enabled', false);
        $this->assertSame(0, Artisan::call('audit:retention'));
        $this->assertStringContainsString(__('audit.retention'), Artisan::output());
        config(['audit.retention.purge_enabled' => true]);
        $this->expectException(\LogicException::class);
        app(AuditRetentionPolicy::class)->description();
    }

    public function test_date_and_actor_filters_use_exact_scoped_values(): void
    {
        $org = $this->owner();
        OrganizationAuditEvent::factory()->create(['organization_id' => $org->id, 'actor_user_id' => $org->owner_user_id, 'occurred_at' => '2026-10-10 12:00:00']);
        OrganizationAuditEvent::factory()->create(['organization_id' => $org->id, 'occurred_at' => '2026-10-09 12:00:00']);
        $query = http_build_query(['action' => 'domain.changed', 'actor_user_id' => $org->owner_user_id, 'from' => '2026-10-10T12:00:00+00:00', 'until' => '2026-10-10T12:00:00+00:00']);
        $this->getJson($this->path($org).'?'.$query)->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_failed_review_audit_prevents_returning_journal_data(): void
    {
        $org = $this->owner();
        OrganizationAuditEvent::factory()->create(['organization_id' => $org->id]);
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER reject_review_audit BEFORE INSERT ON organization_audit_events BEGIN SELECT RAISE(ABORT, 'Audit unavailable'); END");
        } else {
            DB::unprepared("CREATE FUNCTION reject_review_audit() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'Audit unavailable'; END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER reject_review_audit BEFORE INSERT ON organization_audit_events FOR EACH ROW EXECUTE FUNCTION reject_review_audit()");
        }
        try {
            $this->getJson($this->path($org))->assertInternalServerError()->assertJsonMissingPath('data');
            $this->assertDatabaseCount('organization_audit_events', 1);
        } finally {
            DB::unprepared('DROP TRIGGER reject_review_audit'.(DB::getDriverName() === 'pgsql' ? ' ON organization_audit_events' : ''));
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared('DROP FUNCTION reject_review_audit()');
            }
        }
    }

    /** @return array<string, array{string}> */
    public static function auditCacheStores(): array
    {
        return ['memory' => ['array'], 'database' => ['database']];
    }

    #[DataProvider('auditCacheStores')]
    public function test_audit_query_count_is_constant_and_each_source_is_bounded(string $cacheStore): void
    {
        config(['tenancy.cache_store' => $cacheStore]);
        $this->freezeTime();
        $org = $this->owner();
        OrganizationAuditEvent::factory()->create(['organization_id' => $org->id]);
        // Initialize the request quota before comparing steady-state journal reads.
        $this->getJson($this->path($org).'?per_page=20&action=domain.changed')->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $small = $this->getJson($this->path($org).'?per_page=20&action=domain.changed')->assertOk();
        $smallCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        OrganizationAuditEvent::factory()->count(100)->create(['organization_id' => $org->id]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $large = $this->getJson($this->path($org).'?per_page=20&action=domain.changed')->assertOk()->assertJsonCount(20, 'data');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame($smallCount, count($queries));
        $timelineQueries = array_values(array_filter($queries, fn (array $query): bool => str_starts_with($query['query'], 'select') && str_contains($query['query'], 'order by "occurred_at" desc')));
        $this->assertCount(4, $timelineQueries);
        foreach ($timelineQueries as $query) {
            $this->assertStringContainsString('limit 21', $query['query']);
            $this->assertStringNotContainsString('offset', $query['query']);
        }
        $this->assertNotNull($large->json('meta.next_cursor'));
        $this->assertSame($large->headers->get('X-Request-ID'), $large->json('meta.request_id'));
        $this->assertNull($small->json('meta.next_cursor'));
    }

    public function test_rollback_refuses_to_discard_recorded_audit_events(): void
    {
        IdentityAuditEvent::factory()->create();
        $migration = require database_path('migrations/2026_10_11_203439_create_transverse_audit_events.php');
        try {
            $migration->down();
            $this->fail('Rollback must preserve audited events.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('cannot discard audited events', $exception->getMessage());
        }
        $this->assertDatabaseCount('identity_audit_events', 1);
    }

    private function owner(): Organization
    {
        $org = Organization::factory()->withMfaOwner()->create();
        $this->actingAsWithMfa(User::findOrFail($org->owner_user_id));

        return $org;
    }

    private function superadmin(): User
    {
        $role = PlatformRole::factory()->superadmin()->create();
        $user = User::findOrFail($role->user_id);
        $this->actingAsWithMfa($user);

        return $user;
    }

    private function path(Organization $org): string
    {
        return '/api/v1/tenants/'.$org->id.'/audit-events';
    }
}
