<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\Contracts\AuthorizationScopeDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Data\RoleData;
use App\Modules\Tenancy\Application\Services\RoleManagementService;
use App\Modules\Tenancy\Application\Services\TenantJobRunner;
use App\Modules\Tenancy\Domain\Contracts\AccessAuditRepository;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use App\Modules\Tenancy\Domain\Exceptions\TenantExecutionRejectedException;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use App\Support\Queue\JobContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\TestCase;

class ContextualRolesTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
    }

    public function test_guests_and_members_cannot_manage_roles_and_owner_requires_mfa(): void
    {
        $org = Organization::factory()->create();
        $this->getJson($this->path($org, 'roles'))->assertUnauthorized();
        $member = OrganizationMembership::factory()->create(['organization_id' => $org->id]);
        $this->actingAsWithMfa(User::findOrFail($member->user_id));
        $this->getJson($this->path($org, 'roles'))->assertForbidden();
        $this->postJson($this->path($org, 'roles'), $this->rolePayload())->assertForbidden();
        $this->getJson($this->path($org, 'my-role-assignments'))->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('organization_roles', 0);
    }

    public function test_owner_creates_updates_and_disables_a_versioned_audited_role(): void
    {
        $org = $this->owner();
        $role = $this->postJson($this->path($org, 'roles'), $this->rolePayload())->assertCreated()->assertJsonPath('data.privileged', false)->json('data');
        $this->getJson($this->path($org, 'permission-catalogue'))->assertOk()->assertJsonPath('data.scope_types', ['organization', 'team'])->assertJsonPath('data.can_manage', true);
        $this->putJson($this->path($org, 'roles/'.$role['id']), [...$this->rolePayload(), 'active' => false, 'version' => $role['version']])
            ->assertOk()->assertJsonPath('data.active', false);
        $this->putJson($this->path($org, 'roles/'.$role['id']), [...$this->rolePayload(), 'version' => $role['version']])
            ->assertConflict()->assertJsonPath('code', 'AUTHORIZATION_VERSION_STALE');
        $this->assertDatabaseCount('organization_access_events', 2);
        $this->assertDatabaseHas('organization_access_events', ['action' => 'role.created', 'subject_id' => $role['id']]);
        $this->assertDatabaseHas('organization_roles', ['id' => $role['id'], 'active' => false]);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidRoles(): array
    {
        return [
            'reserved' => [['key' => 'owner'], 'key'],
            'platform' => [['permissions' => ['platform.admin']], 'permissions.0'],
            'wildcard' => [['permissions' => ['*']], 'permissions.0'],
            'owner governance' => [['permissions' => ['roles.manage']], 'permissions.0'],
            'duplicate' => [['permissions' => ['sports.read', 'sports.read']], 'permissions.0'],
            'empty' => [['permissions' => []], 'permissions'],
            'spoofed privilege' => [['privileged' => true], 'privileged'],
            'array key' => [['key' => ['coach']], 'key'],
        ];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('invalidRoles')]
    public function test_invalid_role_input_returns_422_without_persistence(array $override, string $field): void
    {
        $org = $this->owner();
        $response = $this->postJson($this->path($org, 'roles'), [...$this->rolePayload(), ...$override])->assertUnprocessable()->assertJsonValidationErrors($field);
        if ($field === 'permissions.0') {
            $this->assertStringContainsString('permission', $response->json('errors')[$field][0]);
        }
        $this->assertDatabaseCount('organization_roles', 0);
        $this->assertDatabaseCount('organization_access_events', 0);
    }

    #[Group('tenant-isolation')]
    public function test_role_keys_are_unique_inside_their_tenant_and_foreign_role_ids_return_404(): void
    {
        $org = $this->owner();
        $this->postJson($this->path($org, 'roles'), $this->rolePayload())->assertCreated();
        $this->postJson($this->path($org, 'roles'), $this->rolePayload())->assertConflict()->assertJsonPath('code', 'ROLE_ALREADY_EXISTS');
        $foreign = OrganizationRole::factory()->create();
        $this->putJson($this->path($org, 'roles/'.$foreign->id), [...$this->rolePayload(), 'version' => $foreign->version])->assertNotFound();
        $this->assertDatabaseCount('organization_roles', 2);
    }

    public function test_assignments_are_paginated_and_eager_loaded_and_isolated(): void
    {
        $assignment = OrganizationRoleAssignment::factory()->create();
        $org = Organization::findOrFail($assignment->organization_id);
        $this->owner($org);
        OrganizationRoleAssignment::factory()->count(3)->create();
        DB::enableQueryLog();
        $this->getJson($this->path($org, 'role-assignments').'?per_page=1')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.role_name', 'Coach')->assertJsonPath('meta.total', 1);
        $roleQueries = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'from "organization_roles"'));
        $this->assertLessThanOrEqual(2, count($roleQueries));
        DB::disableQueryLog();
    }

    public function test_owner_assigns_dated_permissions_and_cannot_assign_their_own_rights(): void
    {
        $org = $this->owner();
        $role = OrganizationRole::factory()->create(['organization_id' => $org->id]);
        $member = OrganizationMembership::factory()->create(['organization_id' => $org->id]);
        $payload = $this->assignmentPayload($org, $role, $member->user_id);
        $result = $this->postJson($this->path($org, 'role-assignments'), $payload)->assertCreated()->json('data');
        $this->assertDatabaseHas('organization_role_assignments', ['id' => $result['id'], 'membership_id' => $member->id]);
        $this->postJson($this->path($org, 'role-assignments'), [...$payload, 'user_id' => $org->owner_user_id])->assertConflict()->assertJsonPath('code', 'ROLE_SELF_ESCALATION_FORBIDDEN');
        $this->assertDatabaseHas('organization_access_events', ['action' => 'role_assignment.created', 'subject_id' => $result['id']]);
        $this->postJson($this->path($org, 'role-assignments'), $payload)->assertConflict()->assertJsonPath('code', 'ROLE_ASSIGNMENT_ALREADY_EXISTS');
    }

    public function test_foreign_scope_role_assignment_and_member_are_rejected_without_leaking_data(): void
    {
        $org = $this->owner();
        $role = OrganizationRole::factory()->create(['organization_id' => $org->id]);
        $member = OrganizationMembership::factory()->create(['organization_id' => $org->id]);
        $foreign = OrganizationRoleAssignment::factory()->create();
        $payload = $this->assignmentPayload($org, $role, $member->user_id);
        $this->postJson($this->path($org, 'role-assignments'), [...$payload, 'role_id' => $foreign->role_id])->assertNotFound();
        $this->postJson($this->path($org, 'role-assignments'), [...$payload, 'scope_id' => $foreign->organization_id])->assertConflict()->assertJsonPath('code', 'AUTHORIZATION_SCOPE_UNAVAILABLE');
        $this->postJson($this->path($org, 'role-assignments'), [...$payload, 'user_id' => $foreign->user_id])->assertConflict()->assertJsonPath('code', 'ROLE_SUBJECT_INVALID');
        $this->putJson($this->path($org, 'role-assignments/'.$foreign->id), [...$payload, 'version' => $foreign->version])->assertNotFound();
        $this->assertDatabaseCount('organization_role_assignments', 1);
    }

    public function test_unknown_module_scopes_are_closed_until_a_resource_validator_exists(): void
    {
        $org = $this->owner();
        $role = OrganizationRole::factory()->create(['organization_id' => $org->id]);
        $member = OrganizationMembership::factory()->create(['organization_id' => $org->id]);
        $this->postJson($this->path($org, 'role-assignments'), [...$this->assignmentPayload($org, $role, $member->user_id),
            'scope_type' => 'team', 'scope_id' => (string) Str::uuid()])->assertConflict()->assertJsonPath('code', 'AUTHORIZATION_SCOPE_UNAVAILABLE');
        $this->assertDatabaseCount('organization_role_assignments', 0);
    }

    public function test_permission_evaluation_is_scoped_and_never_trusts_an_old_context_snapshot(): void
    {
        $assignment = OrganizationRoleAssignment::factory()->create();
        $context = $this->context($assignment);
        $team = (string) Str::uuid();
        $this->scopes($assignment->organization_id, $team);
        $assignment = OrganizationRoleAssignment::factory()->create(['organization_id' => $assignment->organization_id, 'role_id' => $assignment->role_id,
            'user_id' => $assignment->user_id, 'membership_id' => $assignment->membership_id, 'scope_type' => 'team', 'scope_id' => $team]);
        $ordinary = OrganizationRoleAssignment::query()->where('organization_id', $context->organizationId)->where('scope_type', 'organization')->firstOrFail();
        $ordinary->forceFill(['status' => 'revoked'])->save();
        $authorization = $this->app->make(TenantAuthorization::class);
        $this->assertTrue($authorization->allows($context, 'sports.read', 'team', $team));
        $this->assertFalse($authorization->allows($context, 'sports.read'));
        $this->assertFalse($authorization->allows($context, 'sports.read', 'team', (string) Str::uuid()));
        $this->assertFalse($authorization->allows($context, 'financial.read', 'team', $team));
        $assignment->forceFill(['status' => 'suspended'])->save();
        $this->assertFalse($authorization->allows($context, 'sports.read', 'team', $team));
    }

    public function test_one_account_has_different_permissions_in_two_organizations(): void
    {
        $first = OrganizationRoleAssignment::factory()->create();
        $secondMember = OrganizationMembership::factory()->create(['user_id' => $first->user_id]);
        $this->actingAsWithMfa(User::findOrFail($first->user_id));
        $authorization = $this->app->make(TenantAuthorization::class);
        $this->assertTrue($authorization->allows($this->context($first), 'sports.read'));
        $secondOrg = Organization::findOrFail($secondMember->organization_id);
        $this->assertFalse($authorization->allows(new TenantContext($secondOrg->id, $first->user_id, $secondOrg->slug, 'UTC', 'fr', (string) Str::uuid()), 'sports.read'));
    }

    public function test_privileged_custom_permissions_require_mfa_and_do_not_grant_unrelated_actions(): void
    {
        $assignment = OrganizationRoleAssignment::factory()->create();
        $assignment->role->forceFill(['permissions' => ['memberships.manage'], 'privileged' => true])->save();
        $user = User::findOrFail($assignment->user_id);
        $this->withHeaders(['Origin' => 'http://localhost:5173'])->actingAs($user);
        $org = Organization::findOrFail($assignment->organization_id);
        $this->getJson($this->path($org, 'memberships'))->assertForbidden()->assertJsonPath('code', 'MFA_ENROLLMENT_REQUIRED');
        $this->actingAsWithMfa($user);
        $this->getJson($this->path($org, 'memberships'))->assertOk();
        $this->getJson($this->path($org, 'invitations'))->assertForbidden();
        $this->getJson($this->path($org, 'roles'))->assertForbidden();
        $this->assertFalse(Gate::forUser($user)->allows('tenant.permission', [$this->context($assignment), 'roles.manage']));
    }

    public function test_future_expired_or_disabled_roles_do_not_authorize_an_action(): void
    {
        $assignment = OrganizationRoleAssignment::factory()->create(['starts_at' => now()->addHour()]);
        $authorization = $this->app->make(TenantAuthorization::class);
        $context = $this->context($assignment);
        $this->assertFalse($authorization->allows($context, 'sports.read'));
        $assignment->forceFill(['starts_at' => now()->subHour(), 'ends_at' => now()])->save();
        $this->assertFalse($authorization->allows($context, 'sports.read'));
        $assignment->forceFill(['ends_at' => null])->save();
        $assignment->role->forceFill(['active' => false])->save();
        $this->assertFalse($authorization->allows($context, 'sports.read'));
    }

    public function test_suspension_overrides_all_grants_and_departure_permanently_revokes_them(): void
    {
        $assignment = OrganizationRoleAssignment::factory()->create();
        $member = OrganizationMembership::findOrFail($assignment->membership_id);
        $context = $this->context($assignment);
        $authorization = $this->app->make(TenantAuthorization::class);
        $member->forceFill(['status' => 'suspended'])->save();
        $this->assertFalse($authorization->allows($context, 'sports.read'));
        $member->forceFill(['status' => 'active'])->save();
        $this->actingAsWithMfa(User::findOrFail($assignment->user_id));
        $this->deleteJson('/api/v1/my-memberships/'.$member->id, ['confirm' => true])->assertOk();
        $this->assertDatabaseHas('organization_role_assignments', ['id' => $assignment->id, 'status' => 'revoked']);
        $this->assertDatabaseHas('organization_access_events', ['action' => 'role_assignment.membership_revoked', 'subject_id' => $assignment->id]);
        $member->forceFill(['status' => 'active'])->save();
        $this->assertFalse($authorization->allows($context, 'sports.read'));
    }

    public function test_revoke_updates_version_and_prevents_reactivation_or_identity_changes(): void
    {
        $assignment = OrganizationRoleAssignment::factory()->create();
        $org = Organization::findOrFail($assignment->organization_id);
        $this->owner($org);
        $payload = $this->assignmentPayload($org, $assignment->role, $assignment->user_id);
        $this->putJson($this->path($org, 'role-assignments/'.$assignment->id), [...$payload, 'version' => $assignment->version, 'scope_id' => (string) Str::uuid()])
            ->assertConflict()->assertJsonPath('code', 'ROLE_ASSIGNMENT_IDENTITY_IMMUTABLE');
        $updated = $this->putJson($this->path($org, 'role-assignments/'.$assignment->id), [...$payload, 'status' => 'revoked', 'version' => $assignment->version])->assertOk()->json('data');
        $this->putJson($this->path($org, 'role-assignments/'.$assignment->id), [...$payload, 'version' => $updated['version']])
            ->assertConflict()->assertJsonPath('code', 'ROLE_ASSIGNMENT_REVOKED');
    }

    public function test_delegation_cannot_exceed_permissions_or_duration_and_cannot_target_self(): void
    {
        $delegation = OrganizationRoleAssignment::factory()->create(['ends_at' => now()->addHour()]);
        $delegation->role->forceFill(['permissions' => ['roles.assign', 'sports.read'], 'privileged' => true])->save();
        $org = Organization::findOrFail($delegation->organization_id);
        $recipient = OrganizationMembership::factory()->create(['organization_id' => $org->id]);
        $readRole = OrganizationRole::factory()->create(['organization_id' => $org->id]);
        $writeRole = OrganizationRole::factory()->privileged()->create(['organization_id' => $org->id]);
        $this->actingAsWithMfa(User::findOrFail($delegation->user_id));
        $payload = [...$this->assignmentPayload($org, $readRole, $recipient->user_id), 'starts_at' => now()->toIso8601String(), 'ends_at' => now()->addHour()->toIso8601String()];
        $this->postJson($this->path($org, 'role-assignments'), [...$payload, 'role_id' => $writeRole->id])->assertConflict()->assertJsonPath('code', 'ROLE_DELEGATION_DENIED');
        $this->postJson($this->path($org, 'role-assignments'), [...$payload, 'ends_at' => null])->assertConflict()->assertJsonPath('code', 'ROLE_DELEGATION_DENIED');
        $this->postJson($this->path($org, 'role-assignments'), [...$payload, 'user_id' => $delegation->user_id])->assertConflict()->assertJsonPath('code', 'ROLE_SELF_ESCALATION_FORBIDDEN');
        $this->postJson($this->path($org, 'role-assignments'), $payload)->assertCreated();
        $this->postJson($this->path($org, 'roles'), $this->rolePayload())->assertForbidden();
        $this->assertDatabaseCount('organization_role_assignments', 2);
    }

    public function test_a_role_assigned_to_owner_cannot_be_edited_to_escalate_their_own_rights(): void
    {
        $org = $this->owner();
        $role = OrganizationRole::factory()->create(['organization_id' => $org->id]);
        OrganizationRoleAssignment::factory()->create(['organization_id' => $org->id, 'role_id' => $role->id,
            'user_id' => $org->owner_user_id, 'membership_id' => null, 'scope_id' => $org->id]);
        $this->putJson($this->path($org, 'roles/'.$role->id), [...$this->rolePayload(), 'key' => $role->key, 'permissions' => ['financial.read'], 'version' => $role->version])
            ->assertConflict()->assertJsonPath('code', 'ROLE_SELF_ESCALATION_FORBIDDEN');
        $this->assertDatabaseHas('organization_roles', ['id' => $role->id, 'privileged' => false]);
    }

    public function test_pending_job_rechecks_permissions_before_running_the_business_operation(): void
    {
        $assignment = OrganizationRoleAssignment::factory()->create();
        $job = new JobContext((string) Str::uuid(), $assignment->organization_id, $assignment->user_id);
        $runner = $this->app->make(TenantJobRunner::class);
        $this->assertSame('handled', $runner->user($job, fn (): string => 'handled', 'sports.read'));
        $assignment->forceFill(['status' => 'revoked'])->save();
        $this->expectException(TenantExecutionRejectedException::class);
        $runner->user($job, function (): void {
            $this->fail('A revoked permission must not run a job.');
        }, 'sports.read');
    }

    public function test_database_constraints_reject_a_foreign_role_even_with_known_identifiers(): void
    {
        $assignment = OrganizationRoleAssignment::factory()->create();
        $foreign = OrganizationRole::factory()->create();
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('organization_role_assignments')->where('id', $assignment->id)->update(['role_id' => $foreign->id]));
    }

    public function test_audit_failure_rolls_back_role_creation(): void
    {
        $org = Organization::factory()->withMfaOwner()->create();
        $context = new TenantContext($org->id, $org->owner_user_id, $org->slug, 'UTC', 'fr', (string) Str::uuid());
        $this->mock(AccessAuditRepository::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        try {
            $this->app->make(RoleManagementService::class)->saveRole($context, new RoleData('coach', 'Coach', ['sports.read'], true));
            $this->fail('Audit failure must abort the mutation.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertDatabaseCount('organization_roles', 0);
    }

    public function test_assignment_period_must_fit_membership_and_governance_requires_organization_scope(): void
    {
        $org = $this->owner();
        $member = OrganizationMembership::factory()->create(['organization_id' => $org->id, 'ends_at' => now()->addDay()]);
        $role = OrganizationRole::factory()->create(['organization_id' => $org->id]);
        $payload = $this->assignmentPayload($org, $role, $member->user_id);
        $team = (string) Str::uuid();
        $this->scopes($org->id, $team);
        $this->postJson($this->path($org, 'role-assignments'), $payload)->assertConflict()->assertJsonPath('code', 'ROLE_PERIOD_OUTSIDE_MEMBERSHIP');
        $role->forceFill(['active' => false])->save();
        $this->postJson($this->path($org, 'role-assignments'), [...$payload, 'ends_at' => now()->addHour()->toIso8601String()])->assertConflict()->assertJsonPath('code', 'ROLE_INACTIVE');
        $role->forceFill(['active' => true, 'permissions' => ['memberships.manage'], 'privileged' => true])->save();
        $this->postJson($this->path($org, 'role-assignments'), [...$payload, 'scope_type' => 'team', 'scope_id' => $team, 'ends_at' => now()->addHour()->toIso8601String()])
            ->assertConflict()->assertJsonPath('code', 'ROLE_SCOPE_INCOMPATIBLE');
        $this->assertDatabaseCount('organization_role_assignments', 0);
    }

    public function test_a_scoped_delegate_cannot_delegate_permissions_to_another_valid_team(): void
    {
        $delegate = OrganizationRoleAssignment::factory()->create(['ends_at' => now()->addDay()]);
        $delegate->role->forceFill(['permissions' => ['roles.assign'], 'privileged' => true])->save();
        $org = Organization::findOrFail($delegate->organization_id);
        $team = (string) Str::uuid();
        $other = (string) Str::uuid();
        $this->scopes($org->id, $team, $other);
        $role = OrganizationRole::factory()->create(['organization_id' => $org->id]);
        OrganizationRoleAssignment::factory()->create(['organization_id' => $org->id, 'role_id' => $role->id,
            'membership_id' => $delegate->membership_id, 'user_id' => $delegate->user_id, 'scope_type' => 'team', 'scope_id' => $team, 'ends_at' => now()->addDay()]);
        $member = OrganizationMembership::factory()->create(['organization_id' => $org->id]);
        $this->actingAsWithMfa(User::findOrFail($delegate->user_id));
        $payload = [...$this->assignmentPayload($org, $role, $member->user_id), 'starts_at' => now()->toIso8601String(),
            'ends_at' => now()->addHour()->toIso8601String(), 'scope_type' => 'team', 'scope_id' => $other];
        $this->postJson($this->path($org, 'role-assignments'), $payload)->assertConflict()->assertJsonPath('code', 'ROLE_DELEGATION_DENIED');
        $this->postJson($this->path($org, 'role-assignments'), [...$payload, 'scope_id' => $team])->assertCreated();
        $this->assertDatabaseCount('organization_role_assignments', 3);
    }

    #[Group('tenant-isolation')]
    public function test_suspension_and_revoke_are_immediate_for_http_and_other_tenants_remain_accessible(): void
    {
        $assignment = OrganizationRoleAssignment::factory()->create();
        $assignment->role->forceFill(['permissions' => ['memberships.manage'], 'privileged' => true])->save();
        $otherMember = OrganizationMembership::factory()->create(['user_id' => $assignment->user_id]);
        $user = User::findOrFail($assignment->user_id);
        $org = Organization::findOrFail($assignment->organization_id);
        $this->actingAsWithMfa($user);
        $this->getJson($this->path($org, 'memberships'))->assertOk();
        $assignment->forceFill(['status' => 'revoked'])->save();
        $this->getJson($this->path($org, 'memberships'))->assertForbidden();
        $member = OrganizationMembership::findOrFail($assignment->membership_id);
        $member->forceFill(['status' => 'suspended'])->save();
        $this->getJson($this->path($org, 'my-role-assignments'))->assertNotFound();
        $this->getJson('/api/v1/tenants/'.$otherMember->organization_id.'/context')->assertOk();
    }

    public function test_required_assignment_fields_are_validated_before_domain_services(): void
    {
        $org = $this->owner();
        $this->postJson($this->path($org, 'role-assignments'), [])->assertUnprocessable()
            ->assertJsonValidationErrors(['role_id', 'user_id', 'scope_type', 'scope_id', 'status', 'starts_at']);
        $this->assertDatabaseCount('organization_role_assignments', 0);
    }

    public function test_database_composite_keys_refuse_cross_tenant_role_inserts(): void
    {
        $valid = OrganizationRoleAssignment::factory()->create();
        $foreign = OrganizationRole::factory()->create();
        $attributes = $valid->getAttributes();
        $attributes['id'] = (string) Str::uuid();
        $attributes['role_id'] = $foreign->id;
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('organization_role_assignments')->insert($attributes));
    }

    public function test_audit_cannot_reference_another_tenants_role(): void
    {
        $org = Organization::factory()->create();
        $foreign = OrganizationRole::factory()->create();
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('organization_access_events')->insert([
            'id' => (string) Str::uuid(), 'organization_id' => $org->id, 'actor_user_id' => $org->owner_user_id,
            'action' => 'role.created', 'subject_id' => $foreign->id, 'after' => '{}', 'request_id' => (string) Str::uuid(), 'occurred_at' => now(),
        ]));
    }

    public function test_migration_rollback_refuses_to_discard_audited_rights(): void
    {
        $org = $this->owner();
        $this->postJson($this->path($org, 'roles'), $this->rolePayload())->assertCreated();
        $migration = require database_path('migrations/2026_10_11_120303_create_organization_roles_and_role_assignments.php');
        try {
            $migration->down();
            $this->fail('Audited rights must not be discarded.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('forward fix', $exception->getMessage());
        }
        $this->assertDatabaseCount('organization_roles', 1);
        $this->assertDatabaseCount('organization_access_events', 1);
    }

    public function test_delegate_can_suspend_an_existing_grant_without_covering_its_original_period(): void
    {
        $delegate = OrganizationRoleAssignment::factory()->create(['ends_at' => now()->addDay()]);
        $delegate->role->forceFill(['permissions' => ['roles.assign', 'sports.read'], 'privileged' => true])->save();
        $org = Organization::findOrFail($delegate->organization_id);
        $role = OrganizationRole::factory()->create(['organization_id' => $org->id]);
        $member = OrganizationMembership::factory()->create(['organization_id' => $org->id, 'starts_at' => now()->subDays(2)]);
        $target = OrganizationRoleAssignment::factory()->create(['organization_id' => $org->id, 'role_id' => $role->id, 'membership_id' => $member->id, 'user_id' => $member->user_id, 'starts_at' => now()->subDay(), 'ends_at' => null]);
        $this->actingAsWithMfa(User::findOrFail($delegate->user_id));
        $payload = [...$this->assignmentPayload($org, $role, $target->user_id), 'starts_at' => $target->starts_at->toIso8601String(),
            'ends_at' => null, 'status' => 'suspended', 'version' => $target->version];
        $this->putJson($this->path($org, 'role-assignments/'.$target->id), $payload)->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->assertDatabaseCount('organization_access_events', 1);
        $this->assertFalse($this->app->make(TenantAuthorization::class)->allows($this->context($target), 'sports.read'));
    }

    public function test_portability_exports_roles_and_assignments_with_audit_without_another_tenant(): void
    {
        $org = $this->owner();
        $created = $this->postJson($this->path($org, 'roles'), $this->rolePayload())->assertCreated()->json('data');
        $role = OrganizationRole::query()->where('id', $created['id'])->firstOrFail();
        $member = OrganizationMembership::factory()->create(['organization_id' => $org->id]);
        $assignment = $this->postJson($this->path($org, 'role-assignments'), $this->assignmentPayload($org, $role, $member->user_id))->assertCreated()->json('data');
        $foreign = OrganizationRoleAssignment::factory()->create();
        $repository = $this->app->make(PortabilityRepository::class);
        $rows = iterator_to_array($repository->rows($org->id, 100));
        $roles = array_values(array_filter($rows, fn (array $row): bool => $row['table'] === 'organization_roles'));
        $assignments = array_values(array_filter($rows, fn (array $row): bool => $row['table'] === 'organization_role_assignments'));
        $audit = array_values(array_filter($rows, fn (array $row): bool => $row['table'] === 'organization_access_events'));
        $this->assertCount(1, $roles);
        $this->assertSame($role->id, $roles[0]['data']['id']);
        $this->assertCount(1, $assignments);
        $this->assertSame($assignment['id'], $assignments[0]['data']['id']);
        $this->assertSame($role->id, $assignments[0]['data']['role_id']);
        $this->assertCount(2, $audit);
        $this->assertArrayNotHasKey('role_id', $audit[0]['data']);
        foreach ($rows as $row) {
            $this->assertNotSame($foreign->organization_id, $row['data']['organization_id'] ?? $row['data']['id']);
        }
    }

    private function owner(?Organization $org = null): Organization
    {
        $org ??= Organization::factory()->withMfaOwner()->create();
        $this->actingAsWithMfa(User::findOrFail($org->owner_user_id));

        return $org;
    }

    private function path(Organization $org, string $suffix): string
    {
        return '/api/v1/tenants/'.$org->id.'/'.$suffix;
    }

    /** @return array<string, mixed> */
    private function rolePayload(): array
    {
        return ['key' => 'coach', 'name' => 'Coach', 'permissions' => ['sports.read'], 'active' => true];
    }

    /** @return array<string, mixed> */
    private function assignmentPayload(Organization $org, OrganizationRole $role, int $userId): array
    {
        return ['role_id' => $role->id, 'user_id' => $userId, 'scope_type' => 'organization', 'scope_id' => $org->id,
            'status' => 'active', 'starts_at' => now()->subHour()->toIso8601String(), 'ends_at' => null];
    }

    private function context(OrganizationRoleAssignment $assignment): TenantContext
    {
        $org = Organization::findOrFail($assignment->organization_id);

        return new TenantContext($org->id, $assignment->user_id, $org->slug, 'UTC', 'fr', (string) Str::uuid(), ['administrator'], true);
    }

    private function scopes(string $org, string $team, ?string $other = null): void
    {
        $this->app->instance(AuthorizationScopeDirectory::class, new class($org, $team, $other) implements AuthorizationScopeDirectory
        {
            public function __construct(private string $org, private string $team, private ?string $other) {}

            public function exists(string $organizationId, string $type, string $id): bool
            {
                return $organizationId === $this->org && (($type === 'organization' && $id === $this->org) || ($type === 'team' && in_array($id, [$this->team, $this->other], true)));
            }

            public function availableTypes(): array
            {
                return ['organization', 'team'];
            }
        });
    }
}
