<?php

namespace Tests\Feature\Modules\Tenancy;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MembershipPolicyTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_anonymous_access_is_denied_on_all_new_workflows(): void
    {
        $this->getJson('/api/v1/accessible-organizations')->assertUnauthorized();
        $this->getJson('/api/v1/my-invitations')->assertUnauthorized();
        $this->getJson('/api/v1/active-organization')->assertUnauthorized();
        $this->postJson('/api/v1/active-organization', ['organization_id' => '27b92035-6a81-4f1c-93b0-d69ba56e16a1'])->assertUnauthorized();
    }

    public function test_administrator_can_invite_members_but_only_owner_can_delegate_administration(): void
    {
        Mail::fake();
        $membership = OrganizationMembership::factory()->administrator()->create();
        $this->actingAs(User::query()->findOrFail($membership->user_id));
        $url = '/api/v1/tenants/'.$membership->organization_id.'/invitations';
        $this->postJson($url, ['email' => 'member@example.test', 'roles' => ['member']])->assertCreated();
        $this->postJson($url, ['email' => 'admin@example.test', 'roles' => ['administrator']])->assertForbidden();
        $this->assertDatabaseCount('organization_invitations', 1);
    }

    public function test_ownership_is_implicit_not_an_assignable_membership_role(): void
    {
        $organization = Organization::factory()->create();
        $this->actingAs(User::query()->findOrFail($organization->owner_user_id))
            ->getJson('/api/v1/accessible-organizations')->assertOk()->assertJsonPath('data.0.roles', ['owner'])->assertJsonPath('data.0.membership_id', null);
        $this->postJson('/api/v1/tenants/'.$organization->id.'/invitations', ['email' => 'new@example.test', 'roles' => ['owner']])
            ->assertUnprocessable()->assertJsonValidationErrors('roles.0');
        $this->assertDatabaseCount('organization_memberships', 0);
    }

    public function test_membership_collections_are_bounded_and_tenant_filtered(): void
    {
        $organization = Organization::factory()->create();
        OrganizationMembership::factory()->count(3)->create(['organization_id' => $organization->id]);
        OrganizationMembership::factory()->create();
        $this->actingAs(User::query()->findOrFail($organization->owner_user_id))
            ->getJson('/api/v1/tenants/'.$organization->id.'/memberships?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v1/tenants/'.$organization->id.'/memberships?per_page=101&user_id=99')
            ->assertUnprocessable()->assertJsonValidationErrors(['per_page', 'user_id']);
    }
}
