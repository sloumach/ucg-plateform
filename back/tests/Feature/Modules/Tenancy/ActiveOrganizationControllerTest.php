<?php

namespace Tests\Feature\Modules\Tenancy;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationInvitation;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use App\Modules\Tenancy\Presentation\Support\ActiveOrganizationSession;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class ActiveOrganizationControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_empty_session_has_no_implicit_organization(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/v1/active-organization')->assertOk()
            ->assertJsonPath('data.context', null)->assertJsonPath('data.confirmed', false);
    }

    public function test_selection_is_visible_with_fresh_roles_and_requires_confirmation_for_sensitive_actions(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::query()->findOrFail($organization->owner_user_id);
        $response = $this->actingAs($owner)->postJson('/api/v1/active-organization', ['organization_id' => $organization->id])
            ->assertOk()->assertJsonPath('data.context.organization_id', $organization->id)
            ->assertJsonPath('data.context.roles', ['owner'])->assertJsonPath('data.confirmed', false);
        $revision = $response->json('data.revision');
        $this->assertTrue(Str::isUuid($revision));
        $this->withHeader('X-Tenant-Revision', $revision)->postJson('/api/v1/tenants/'.$organization->id.'/invitations', ['email' => 'new@example.test', 'roles' => ['member']])
            ->assertConflict()->assertJsonPath('code', 'TENANT_CONFIRMATION_REQUIRED');
        $this->postJson('/api/v1/active-organization/confirmation', ['organization_id' => $organization->id, 'revision' => $revision, 'confirm' => true])
            ->assertOk()->assertJsonPath('data.confirmed', true);
        Mail::fake();
        $this->postJson('/api/v1/tenants/'.$organization->id.'/invitations', ['email' => 'new@example.test', 'roles' => ['member']])->assertCreated();
    }

    public function test_switch_resets_confirmation_and_rejects_stale_revision_and_old_tenant(): void
    {
        $owner = User::factory()->create();
        $first = Organization::factory()->create(['owner_user_id' => $owner->id]);
        $second = Organization::factory()->create(['owner_user_id' => $owner->id]);
        $revision = $this->actingAs($owner)->postJson('/api/v1/active-organization', ['organization_id' => $first->id])->assertOk()->json('data.revision');
        $this->postJson('/api/v1/active-organization/confirmation', ['organization_id' => $first->id, 'revision' => $revision, 'confirm' => true])->assertOk();
        $next = $this->postJson('/api/v1/active-organization', ['organization_id' => $second->id])->assertOk()->assertJsonPath('data.confirmed', false)->json('data.revision');
        $this->assertNotSame($revision, $next);
        $this->withHeader('X-Tenant-Revision', $revision)->getJson('/api/v1/tenants/'.$second->id.'/context')->assertConflict()->assertJsonPath('code', 'TENANT_CONTEXT_CHANGED');
        $this->withHeader('X-Tenant-Revision', $next)->getJson('/api/v1/tenants/'.$first->id.'/context')->assertConflict();
        $this->postJson('/api/v1/active-organization/confirmation', ['organization_id' => $second->id, 'revision' => $revision, 'confirm' => true])->assertConflict();
        $this->getJson('/api/v1/tenants/'.$second->id.'/context')->assertOk();
    }

    public function test_unauthorized_selection_does_not_replace_previous_context(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $this->actingAs(User::query()->findOrFail($organization->owner_user_id))->postJson('/api/v1/active-organization', ['organization_id' => $organization->id])->assertOk();
        $this->postJson('/api/v1/active-organization', ['organization_id' => $other->id])->assertNotFound();
        $this->getJson('/api/v1/active-organization')->assertOk()->assertJsonPath('data.context.organization_id', $organization->id);
    }

    public function test_current_context_is_cleared_after_membership_revocation(): void
    {
        $membership = OrganizationMembership::factory()->administrator()->create();
        $this->actingAs(User::query()->findOrFail($membership->user_id))->postJson('/api/v1/active-organization', ['organization_id' => $membership->organization_id])
            ->assertOk()->assertJsonPath('data.context.roles', ['administrator']);
        $membership->forceFill(['status' => 'revoked'])->save();
        $this->getJson('/api/v1/active-organization')->assertOk()->assertJsonPath('data.context', null)->assertJsonPath('notification.type', 'warning');
        $this->assertNull(session()->get(ActiveOrganizationSession::KEY));
    }

    public function test_member_roles_and_permissions_are_scoped_to_each_selected_organization(): void
    {
        $user = User::factory()->create();
        $first = OrganizationMembership::factory()->administrator()->create(['user_id' => $user->id]);
        $second = OrganizationMembership::factory()->create(['user_id' => $user->id]);
        $this->actingAs($user)->postJson('/api/v1/active-organization', ['organization_id' => $first->organization_id])
            ->assertOk()->assertJsonPath('data.context.permissions.2', 'memberships.manage');
        $this->postJson('/api/v1/active-organization', ['organization_id' => $second->organization_id])
            ->assertOk()->assertJsonPath('data.context.permissions', ['organization.view']);
    }

    public function test_selection_and_confirmation_reject_invalid_identifiers_and_missing_consent(): void
    {
        $this->actingAs(User::factory()->create())->postJson('/api/v1/active-organization', ['organization_id' => 'invalid'])->assertUnprocessable();
        $this->postJson('/api/v1/active-organization/confirmation', ['organization_id' => (string) Str::uuid(), 'revision' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors('confirm');
    }

    public function test_global_departure_and_invitation_response_reject_a_stale_selected_session(): void
    {
        $user = User::factory()->create();
        $first = OrganizationMembership::factory()->create(['user_id' => $user->id]);
        $second = OrganizationMembership::factory()->create(['user_id' => $user->id]);
        $invitation = OrganizationInvitation::factory()->create(['email' => $user->email]);
        $oldRevision = $this->actingAs($user)->postJson('/api/v1/active-organization', ['organization_id' => $first->organization_id])->assertOk()->json('data.revision');
        $this->postJson('/api/v1/active-organization', ['organization_id' => $second->organization_id])->assertOk();
        $this->withHeader('X-Tenant-Revision', $oldRevision)->deleteJson('/api/v1/my-memberships/'.$first->id, ['confirm' => true])
            ->assertConflict()->assertJsonPath('code', 'TENANT_CONTEXT_CHANGED');
        $this->putJson('/api/v1/my-invitations/'.$invitation->id, ['decision' => 'accepted', 'confirm' => true])->assertConflict();
        $this->assertDatabaseHas('organization_memberships', ['id' => $first->id, 'status' => 'active']);
        $this->assertDatabaseHas('organization_invitations', ['id' => $invitation->id, 'status' => 'pending']);
        $this->assertDatabaseCount('organization_access_events', 0);
    }
}
