<?php

namespace Tests\Feature\Modules\Tenancy;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MembershipControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_effective_member_can_resolve_context_but_cannot_manage_memberships(): void
    {
        $membership = OrganizationMembership::factory()->create();
        $this->actingAs(User::query()->findOrFail($membership->user_id));
        $this->getJson('/api/v1/tenants/'.$membership->organization_id.'/context')->assertOk();
        $this->getJson('/api/v1/tenants/'.$membership->organization_id.'/memberships')->assertForbidden();
        $this->postJson('/api/v1/tenants/'.$membership->organization_id.'/invitations', ['email' => 'new@example.test', 'roles' => ['member']])->assertForbidden();
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function ineffectiveMemberships(): array
    {
        return ['suspended' => [['status' => 'suspended']], 'revoked' => [['status' => 'revoked']],
            'future' => [['starts_at' => '2035-01-01T00:00:00+00:00']],
            'expired' => [['starts_at' => '2020-01-01T00:00:00+00:00', 'ends_at' => '2021-01-01T00:00:00+00:00']]];
    }

    /** @param array<string, mixed> $attributes */
    #[DataProvider('ineffectiveMemberships')]
    public function test_ineffective_membership_does_not_expose_context_or_accessible_organization(array $attributes): void
    {
        $membership = OrganizationMembership::factory()->create($attributes);
        $this->actingAs(User::query()->findOrFail($membership->user_id));
        $this->getJson('/api/v1/tenants/'.$membership->organization_id.'/context')->assertNotFound();
        $this->getJson('/api/v1/accessible-organizations')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_owner_changes_status_and_roles_with_an_audit_and_immediate_access_revocation(): void
    {
        $membership = OrganizationMembership::factory()->create();
        $organization = Organization::query()->findOrFail($membership->organization_id);
        $this->actingAs(User::query()->findOrFail($organization->owner_user_id))
            ->putJson('/api/v1/tenants/'.$organization->id.'/memberships/'.$membership->id, $this->payload('suspended', ['administrator']))
            ->assertOk()->assertJsonPath('data.status', 'suspended')->assertJsonPath('notification.type', 'success');
        $this->assertDatabaseHas('organization_access_events', ['action' => 'membership.updated', 'subject_id' => $membership->id]);
        $this->actingAs(User::query()->findOrFail($membership->user_id))->getJson('/api/v1/tenants/'.$organization->id.'/context')->assertNotFound();
    }

    public function test_administrator_cannot_grant_or_remove_administrator_roles(): void
    {
        $administrator = OrganizationMembership::factory()->administrator()->create();
        $target = OrganizationMembership::factory()->create(['organization_id' => $administrator->organization_id]);
        $this->actingAs(User::query()->findOrFail($administrator->user_id))
            ->putJson('/api/v1/tenants/'.$administrator->organization_id.'/memberships/'.$target->id, $this->payload('active', ['administrator']))->assertForbidden();
        $this->putJson('/api/v1/tenants/'.$administrator->organization_id.'/memberships/'.$target->id, $this->payload('suspended', ['member']))->assertOk();
        $this->putJson('/api/v1/tenants/'.$administrator->organization_id.'/memberships/'.$administrator->id, $this->payload('revoked', ['member']))->assertForbidden();
    }

    public function test_cross_tenant_membership_id_is_rejected_without_mutation(): void
    {
        $organization = Organization::factory()->create();
        $membership = OrganizationMembership::factory()->create();
        $this->actingAs(User::query()->findOrFail($organization->owner_user_id))
            ->putJson('/api/v1/tenants/'.$organization->id.'/memberships/'.$membership->id, $this->payload())->assertNotFound();
        $this->assertDatabaseHas('organization_memberships', ['id' => $membership->id, 'status' => 'active']);
        $this->assertDatabaseCount('organization_access_events', 0);
    }

    public function test_departure_preserves_history_and_does_not_affect_other_organizations(): void
    {
        $user = User::factory()->create();
        $first = OrganizationMembership::factory()->create(['user_id' => $user->id]);
        $second = OrganizationMembership::factory()->create(['user_id' => $user->id]);
        $this->actingAs($user)->deleteJson('/api/v1/my-memberships/'.$first->id, ['confirm' => true])
            ->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->assertDatabaseCount('organization_memberships', 2);
        $this->assertDatabaseHas('organization_memberships', ['id' => $second->id, 'status' => 'active']);
        $this->assertDatabaseHas('organization_access_events', ['action' => 'membership.left', 'subject_id' => $first->id]);
        $this->getJson('/api/v1/tenants/'.$first->organization_id.'/context')->assertNotFound();
        $this->getJson('/api/v1/tenants/'.$second->organization_id.'/context')->assertOk();
    }

    public function test_departure_is_self_only_and_requires_explicit_confirmation(): void
    {
        $membership = OrganizationMembership::factory()->create();
        $this->actingAs(User::factory()->create())->deleteJson('/api/v1/my-memberships/'.$membership->id, ['confirm' => true])->assertNotFound();
        $this->actingAs(User::query()->findOrFail($membership->user_id))->deleteJson('/api/v1/my-memberships/'.$membership->id)->assertUnprocessable()->assertJsonValidationErrors('confirm');
        $this->assertDatabaseHas('organization_memberships', ['id' => $membership->id, 'status' => 'active']);
    }

    public function test_database_protects_membership_identity_and_append_only_audits(): void
    {
        $membership = OrganizationMembership::factory()->create();
        $organization = Organization::query()->findOrFail($membership->organization_id);
        $this->actingAs(User::query()->findOrFail($organization->owner_user_id))
            ->putJson('/api/v1/tenants/'.$organization->id.'/memberships/'.$membership->id, $this->payload())->assertOk();
        foreach ([
            fn () => DB::table('organization_memberships')->where('id', $membership->id)->update(['user_id' => $organization->owner_user_id]),
            fn () => DB::table('organization_access_events')->update(['action' => 'rewritten']),
            fn () => DB::table('organization_access_events')->delete(),
        ] as $operation) {
            try {
                DB::transaction($operation);
                $this->fail('Identity and audit must be protected at the database boundary.');
            } catch (QueryException) {
                $this->assertDatabaseHas('organization_memberships', ['id' => $membership->id, 'user_id' => $membership->user_id]);
                $this->assertDatabaseCount('organization_access_events', 1);
            }
        }
    }

    public function test_invalid_roles_period_or_protected_fields_leave_membership_unchanged(): void
    {
        $membership = OrganizationMembership::factory()->create();
        $organization = Organization::query()->findOrFail($membership->organization_id);
        $this->actingAs(User::query()->findOrFail($organization->owner_user_id))
            ->putJson('/api/v1/tenants/'.$organization->id.'/memberships/'.$membership->id,
                [...$this->payload(), 'roles' => ['owner'], 'ends_at' => '2019-01-01T00:00:00+00:00', 'user_id' => $organization->owner_user_id])
            ->assertUnprocessable()->assertJsonValidationErrors(['roles.0', 'ends_at', 'user_id']);
        $this->assertDatabaseCount('organization_access_events', 0);
    }

    /**
     * @param  list<string>  $roles
     * @return array<string, mixed>
     */
    private function payload(string $status = 'active', array $roles = ['member']): array
    {
        return ['status' => $status, 'roles' => $roles, 'starts_at' => '2020-01-01T00:00:00+00:00', 'ends_at' => null];
    }
}
