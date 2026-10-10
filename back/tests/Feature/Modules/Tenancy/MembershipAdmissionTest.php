<?php

namespace Tests\Feature\Modules\Tenancy;

use App\Exceptions\DomainConflictException;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\Data\OrganizationAuditData;
use App\Modules\Tenancy\Application\Data\OrganizationDetailsData;
use App\Modules\Tenancy\Application\Services\OrganizationService;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationInvitation;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MembershipAdmissionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_multiple_organizations_are_allowed_by_default_without_merging_roles(): void
    {
        $user = User::factory()->create();
        $first = OrganizationMembership::factory()->administrator()->create(['user_id' => $user->id]);
        $invitation = OrganizationInvitation::factory()->create(['email' => $user->email]);
        $this->actingAs($user)->putJson('/api/v1/my-invitations/'.$invitation->id, ['decision' => 'accepted', 'confirm' => true])->assertOk();
        $this->assertDatabaseCount('organization_memberships', 2);
        $this->assertSame(['administrator'], $first->refresh()->roles);
        $this->assertSame(['member'], OrganizationMembership::query()->where('organization_id', $invitation->organization_id)->where('user_id', $user->id)->firstOrFail()->roles);
    }

    /** @return array<string, array{string}> */
    public static function blockingStatuses(): array
    {
        return ['active' => ['active'], 'suspended' => ['suspended']];
    }

    #[DataProvider('blockingStatuses')]
    public function test_exclusivity_rejects_overlapping_admission_even_when_first_membership_is_suspended(string $status): void
    {
        config()->set('tenancy.allow_multiple_organizations', false);
        $user = User::factory()->create();
        OrganizationMembership::factory()->create(['user_id' => $user->id, 'status' => $status]);
        $invitation = OrganizationInvitation::factory()->create(['email' => $user->email]);
        $this->actingAs($user)->putJson('/api/v1/my-invitations/'.$invitation->id, ['decision' => 'accepted', 'confirm' => true])
            ->assertConflict()->assertJsonPath('code', 'MULTIPLE_ORGANIZATIONS_FORBIDDEN');
        $this->assertDatabaseCount('organization_memberships', 1);
        $this->assertDatabaseCount('organization_access_events', 0);
        $this->assertDatabaseHas('organization_invitations', ['id' => $invitation->id, 'status' => 'pending']);
    }

    public function test_exclusivity_allows_non_overlapping_periods_and_does_not_delete_history(): void
    {
        config()->set('tenancy.allow_multiple_organizations', false);
        $user = User::factory()->create();
        OrganizationMembership::factory()->create(['user_id' => $user->id, 'starts_at' => now()->subDays(3), 'ends_at' => now()->subDay()]);
        $invitation = OrganizationInvitation::factory()->create(['email' => $user->email]);
        $this->actingAs($user)->putJson('/api/v1/my-invitations/'.$invitation->id, ['decision' => 'accepted', 'confirm' => true])->assertOk();
        $this->assertDatabaseCount('organization_memberships', 2);
    }

    public function test_explicit_departure_allows_next_admission_and_existing_ownership_blocks_it(): void
    {
        config()->set('tenancy.allow_multiple_organizations', false);
        $user = User::factory()->create();
        $membership = OrganizationMembership::factory()->create(['user_id' => $user->id]);
        $invitation = OrganizationInvitation::factory()->create(['email' => $user->email]);
        $this->actingAs($user)->deleteJson('/api/v1/my-memberships/'.$membership->id, ['confirm' => true])->assertOk();
        $this->putJson('/api/v1/my-invitations/'.$invitation->id, ['decision' => 'accepted', 'confirm' => true])->assertOk();
        $owner = User::factory()->create();
        Organization::factory()->create(['owner_user_id' => $owner->id]);
        $other = OrganizationInvitation::factory()->create(['email' => $owner->email]);
        $this->actingAs($owner)->putJson('/api/v1/my-invitations/'.$other->id, ['decision' => 'accepted', 'confirm' => true])
            ->assertConflict()->assertJsonPath('code', 'MULTIPLE_ORGANIZATIONS_FORBIDDEN');
    }

    public function test_exclusivity_checks_reactivation_without_retroactively_revoking_existing_memberships(): void
    {
        $user = User::factory()->create();
        OrganizationMembership::factory()->create(['user_id' => $user->id]);
        $revoked = OrganizationMembership::factory()->create(['user_id' => $user->id, 'status' => 'revoked']);
        config()->set('tenancy.allow_multiple_organizations', false);
        $organization = Organization::query()->findOrFail($revoked->organization_id);
        $this->actingAs(User::query()->findOrFail($organization->owner_user_id))
            ->putJson('/api/v1/tenants/'.$organization->id.'/memberships/'.$revoked->id,
                ['roles' => ['member'], 'status' => 'active', 'starts_at' => now()->toIso8601String(), 'ends_at' => null])
            ->assertConflict()->assertJsonPath('code', 'MULTIPLE_ORGANIZATIONS_FORBIDDEN');
        $this->assertDatabaseCount('organization_memberships', 2);
        $this->assertDatabaseHas('organization_memberships', ['id' => $revoked->id, 'status' => 'revoked']);
    }

    public function test_exclusivity_also_prevents_provisioning_another_owned_organization(): void
    {
        config()->set('tenancy.allow_multiple_organizations', false);
        $owner = User::factory()->create();
        Organization::factory()->create(['owner_user_id' => $owner->id]);
        try {
            app(OrganizationService::class)->create('second-owned', $owner->id,
                new OrganizationDetailsData('Second owned', 'UTC', 'fr', 'FR'),
                new OrganizationAuditData($owner->id, 'Test admission', (string) Str::uuid()));
            $this->fail('Implicit ownership must not bypass exclusivity.');
        } catch (DomainConflictException $exception) {
            $this->assertSame('MULTIPLE_ORGANIZATIONS_FORBIDDEN', $exception->errorCode());
            $this->assertDatabaseCount('organizations', 1);
            $this->assertDatabaseCount('organization_lifecycle_events', 0);
        }
    }
}
