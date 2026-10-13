<?php

namespace Tests\Feature;

use App\Exceptions\DomainConflictException;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Teams\Domain\Models\TeamMembership;
use App\Modules\Tenancy\Application\Contracts\AuthorizationScopeDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Data\RoleAssignmentData;
use App\Modules\Tenancy\Application\Services\RoleManagementService;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class TeamAuthorizationScopesTest extends TestCase
{
    use LazilyRefreshDatabase,ProvidesPeopleAccess,\Tests\Fixtures\AuthenticatesWithMfa;

    public function test_scoped_reader_can_read_only_the_team_detail_and_loses_it_on_period_end(): void
    {
        $this->freezeSecond();
        [$org,$owner,$team,$subject,$person] = $this->setupTeam();
        $period = TeamMembership::factory()->create(['team_id' => $team->id, 'person_id' => $person->id]);
        $this->assign($org, $owner, $team, $subject, now()->toIso8601String(), null);
        $this->actingAsWithMfa($subject);
        $path = '/api/v1/tenants/'.$org->id.'/teams';
        $this->getJson($path.'/'.$team->id)->assertOk()->assertJsonPath('data.id', $team->id);
        $this->getJson($path)->assertForbidden();
        $this->getJson($path.'/'.$team->id.'/memberships')->assertForbidden();
        $period->forceFill(['status' => 'closed', 'ends_at' => now()])->save();
        $this->getJson($path.'/'.$team->id)->assertForbidden();
    }

    public function test_team_scopes_are_registered_and_foreign_or_archived_teams_are_unavailable(): void
    {
        [$org,$owner,$team] = $this->setupTeam();
        $scopes = app(AuthorizationScopeDirectory::class);
        $this->assertSame(['organization', 'team'], $scopes->availableTypes());
        $this->assertTrue($scopes->exists($org->id, 'team', $team->id));
        $foreign = Team::factory()->create();
        $this->assertFalse($scopes->exists($org->id, 'team', $foreign->id));
        $team->forceFill(['status' => 'archived'])->save();
        $this->assertFalse($scopes->exists($org->id, 'team', $team->id));
    }

    public function test_explicit_team_grants_take_effect_at_start_and_stop_at_end_without_cache_or_scheduler(): void
    {
        $this->freezeSecond();
        [$org,$owner,$team,$subject,$person] = $this->setupTeam();
        $start = now()->addHours(2);
        $end = now()->addHours(4);
        TeamMembership::factory()->create(['team_id' => $team->id, 'person_id' => $person->id, 'starts_at' => $start, 'ends_at' => $end]);
        $grant = $this->assign($org, $owner, $team, $subject, $start->toIso8601String(), $end->toIso8601String());
        $ctx = $this->personContext($org, $subject);
        $auth = app(TenantAuthorization::class);
        $this->assertFalse($auth->allows($ctx, 'sports.read', 'team', $team->id));
        $this->assertFalse($auth->allows($ctx, 'sports.read'));
        $this->travelTo($start);
        $this->assertTrue($auth->allows($ctx, 'sports.read', 'team', $team->id));
        $this->assertDatabaseHas('team_scope_grants', ['role_assignment_id' => $grant->id]);
        $this->travelTo($end);
        $this->assertFalse($auth->allows($ctx, 'sports.read', 'team', $team->id));
        $this->assertFalse($auth->allows($ctx, 'sports.read', 'team', Team::factory()->create()->id));
    }

    public function test_sporting_role_alone_never_grants_business_access_and_organization_managers_need_explicit_rights(): void
    {
        [$org,$owner,$team,$subject,$person] = $this->setupTeam();
        TeamMembership::factory()->create(['team_id' => $team->id, 'person_id' => $person->id, 'role_key' => 'captain']);
        $auth = app(TenantAuthorization::class);
        $this->assertFalse($auth->allows($this->personContext($org, $subject), 'sports.read', 'team', $team->id));
        $this->assertFalse($auth->allows($this->personContext($org, $owner), 'sports.read', 'team', $team->id));
        $manager = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $manager, ['sports.read']);
        $this->assertTrue($auth->allows($this->personContext($org, $manager), 'sports.read', 'team', $team->id));
    }

    public function test_return_does_not_resurrect_grants_bound_to_a_closed_period(): void
    {
        $this->freezeSecond();
        [$org,$owner,$team,$subject,$person] = $this->setupTeam();
        $period = TeamMembership::factory()->create(['team_id' => $team->id, 'person_id' => $person->id, 'starts_at' => now()->subHour()]);
        $grant = $this->assign($org, $owner, $team, $subject, now()->subHour()->toIso8601String(), null);
        $auth = app(TenantAuthorization::class);
        $ctx = $this->personContext($org, $subject);
        $this->assertTrue($auth->allows($ctx, 'sports.read', 'team', $team->id));
        $period->forceFill(['ends_at' => now(), 'status' => 'closed'])->save();
        TeamMembership::factory()->create(['team_id' => $team->id, 'person_id' => $person->id, 'starts_at' => now()]);
        $this->assertFalse($auth->allows($ctx, 'sports.read', 'team', $team->id));
        $this->assertDatabaseHas('team_scope_grants', ['role_assignment_id' => $grant->id, 'team_membership_id' => $period->id]);
        $service = app(RoleManagementService::class);
        $ownerContext = $this->personContext($org, $owner);
        $service->saveAssignment($ownerContext, new RoleAssignmentData($grant->role_id, $subject->id, 'team', $team->id, 'revoked', $grant->starts_at->toIso8601String(), null, $grant->version), $grant->id);
        $replacement = $service->saveAssignment($ownerContext, new RoleAssignmentData($grant->role_id, $subject->id, 'team', $team->id, 'active', now()->toIso8601String(), null));
        $this->assertNotSame($grant->id, $replacement->id);
        $this->assertDatabaseCount('team_scope_grants', 2);
        $this->assertTrue($auth->allows($ctx, 'sports.read', 'team', $team->id));
    }

    public function test_cancelled_future_period_does_not_restore_a_future_grant_when_a_replacement_starts_at_the_same_date(): void
    {
        $this->freezeSecond();
        [$org,$owner,$team,$subject,$person] = $this->setupTeam();
        $start = now()->addDay();
        $period = TeamMembership::factory()->create(['team_id' => $team->id, 'person_id' => $person->id, 'starts_at' => $start]);
        $this->assign($org, $owner, $team, $subject, $start->toIso8601String(), null);
        $period->forceFill(['status' => 'cancelled'])->save();
        TeamMembership::factory()->create(['team_id' => $team->id, 'person_id' => $person->id, 'starts_at' => $start]);
        $this->travelTo($start);
        $this->assertFalse(app(TenantAuthorization::class)->allows($this->personContext($org, $subject), 'sports.read', 'team', $team->id));
    }

    public function test_grant_cannot_exceed_team_period_and_failed_binding_rolls_back_assignment(): void
    {
        $this->freezeSecond();
        [$org,$owner,$team,$subject,$person] = $this->setupTeam();
        TeamMembership::factory()->create(['team_id' => $team->id, 'person_id' => $person->id, 'ends_at' => now()->addDay()]);
        try {
            $this->assign($org, $owner, $team, $subject, now()->toIso8601String(), null);
            $this->fail('Grant beyond membership must fail.');
        } catch (DomainConflictException $error) {
            $this->assertSame('ROLE_PERIOD_OUTSIDE_TEAM_MEMBERSHIP', $error->errorCode());
        }
        $this->assertDatabaseCount('organization_role_assignments', 0);
        $this->assertDatabaseCount('team_scope_grants', 0);
    }

    public function test_person_account_unlink_and_membership_suspension_immediately_remove_team_access(): void
    {
        $this->freezeSecond();
        [$org,$owner,$team,$subject,$person] = $this->setupTeam();
        TeamMembership::factory()->create(['team_id' => $team->id, 'person_id' => $person->id]);
        $this->assign($org, $owner, $team, $subject, now()->toIso8601String(), null);
        $ctx = $this->personContext($org, $subject);
        $auth = app(TenantAuthorization::class);
        $this->assertTrue($auth->allows($ctx, 'sports.read', 'team', $team->id));
        $person->forceFill(['user_id' => null])->save();
        $this->assertFalse($auth->allows($ctx, 'sports.read', 'team', $team->id));
        $person->forceFill(['user_id' => $subject->id])->save();
        OrganizationMembership::query()->where('organization_id', $org->id)->where('user_id', $subject->id)->update(['status' => 'suspended']);
        $this->assertFalse($auth->allows($ctx, 'sports.read', 'team', $team->id));
    }

    public function test_editing_a_grant_keeps_its_original_period_when_other_functions_cover_the_same_dates(): void
    {
        $this->freezeSecond();
        [$org,$owner,$team,$subject,$person] = $this->setupTeam();
        $period = TeamMembership::factory()->create(['team_id' => $team->id, 'person_id' => $person->id, 'starts_at' => now()->subDays(2)]);
        $grant = $this->assign($org, $owner, $team, $subject, now()->toIso8601String(), null);
        TeamMembership::factory()->create(['team_id' => $team->id, 'person_id' => $person->id, 'role_key' => 'coach', 'starts_at' => now()->subDays(3)]);
        app(RoleManagementService::class)->saveAssignment($this->personContext($org, $owner), new RoleAssignmentData($grant->role_id, $subject->id, 'team', $team->id, 'active',
            $grant->starts_at->toIso8601String(), now()->addDay()->toIso8601String(), $grant->version), $grant->id);
        $this->assertDatabaseCount('team_scope_grants', 1);
        $this->assertDatabaseHas('team_scope_grants', ['role_assignment_id' => $grant->id, 'team_membership_id' => $period->id]);
        $this->assertTrue(app(TenantAuthorization::class)->allows($this->personContext($org, $subject), 'sports.read', 'team', $team->id));
    }

    public function test_binding_quota_rolls_back_new_grant_and_its_audit(): void
    {
        $this->freezeSecond();
        [$org,$owner,$team,$subject,$person] = $this->setupTeam();
        $period = TeamMembership::factory()->create(['team_id' => $team->id, 'person_id' => $person->id]);
        $grant = $this->assign($org, $owner, $team, $subject, now()->toIso8601String(), null);
        $assignments = [];
        $bindings = [];
        for ($i = 1; $i < 1000; $i++) {
            $id = (string) Str::uuid();
            $assignments[] = [...$grant->getAttributes(), 'id' => $id, 'status' => 'revoked', 'starts_at' => now()->addSeconds($i)->format('Y-m-d H:i:s')];
            $bindings[] = ['id' => (string) Str::uuid(), 'organization_id' => $org->id, 'role_assignment_id' => $id, 'team_membership_id' => $period->id, 'created_at' => now()];
        }
        foreach (array_chunk($assignments, 100) as $chunk) {
            DB::table('organization_role_assignments')->insert($chunk);
        }
        foreach (array_chunk($bindings, 100) as $chunk) {
            DB::table('team_scope_grants')->insert($chunk);
        }
        try {
            $this->assign($org, $owner, $team, $subject, now()->toIso8601String(), null);
            $this->fail('The binding limit must be enforced.');
        } catch (DomainConflictException $error) {
            $this->assertSame('TEAM_SCOPE_GRANT_LIMIT_REACHED', $error->errorCode());
        }
        $this->assertDatabaseCount('team_scope_grants', 1000);
        $this->assertDatabaseCount('organization_role_assignments', 1000);
        $this->assertDatabaseCount('organization_access_events', 1);
    }

    /** @return array{Organization,User,Team,User,Person} */
    private function setupTeam(): array
    {
        $org = Organization::factory()->withMfaOwner()->create();
        $owner = User::findOrFail($org->owner_user_id);
        $subject = User::factory()->withMfa()->create();
        OrganizationMembership::factory()->create(['organization_id' => $org->id, 'user_id' => $subject->id, 'starts_at' => now()->subDays(2)]);

        return [$org, $owner, Team::factory()->create(['organization_id' => $org->id]), $subject, Person::factory()->create(['organization_id' => $org->id, 'user_id' => $subject->id, 'status' => 'active'])];
    }

    private function assign(Organization $org, User $owner, Team $team, User $subject, string $start, ?string $end): OrganizationRoleAssignment
    {
        $role = OrganizationRole::factory()->create(['organization_id' => $org->id, 'permissions' => ['sports.read'], 'privileged' => false]);

        return app(RoleManagementService::class)->saveAssignment($this->personContext($org, $owner), new RoleAssignmentData($role->id, $subject->id, 'team', $team->id, 'active', $start, $end));
    }
}
