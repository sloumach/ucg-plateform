<?php

namespace Tests\Feature;

use App\Exceptions\DomainConflictException;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Application\Data\PersonLifecycleData;
use App\Modules\People\Application\Services\PersonDepartureService;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Teams\Application\Services\TeamMembershipService;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Teams\Domain\Models\TeamMembership;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Domain\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Mockery;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class TeamMembershipServiceTest extends TestCase
{
    use LazilyRefreshDatabase,ProvidesPeopleAccess;

    public function test_adjacent_periods_are_allowed_captain_is_additive_and_playing_overlap_is_refused(): void
    {
        $this->travelTo(now()->startOfSecond());
        [$org,$user,$team,$person] = $this->editor();
        $service = app(TeamMembershipService::class);
        $ctx = $this->personContext($org, $user);
        $service->create($ctx, $team->id, $this->payload($person));
        $service->create($ctx, $team->id, [...$this->payload($person), 'role_key' => 'captain']);
        try {
            $service->create($ctx, $team->id, [...$this->payload($person), 'role_key' => 'substitute']);
            $this->fail('Overlap must fail.');
        } catch (DomainConflictException $error) {
            $this->assertSame('TEAM_MEMBERSHIP_OVERLAP', $error->errorCode());
        }
        $this->assertDatabaseCount('team_memberships', 2);
        $other = Person::factory()->create(['organization_id' => $org->id, 'status' => 'active']);
        $service->create($ctx, $team->id, [...$this->payload($other), 'ends_at' => now()->toIso8601String()]);
        $service->create($ctx, $team->id, [...$this->payload($other), 'role_key' => 'substitute', 'starts_at' => now()->toIso8601String()]);
        $this->assertDatabaseCount('team_memberships', 4);
    }

    public function test_promotion_closes_all_source_functions_and_preserves_author_and_reason(): void
    {
        $this->travelTo(now()->startOfSecond());
        [$org,$user,$team,$person] = $this->editor();
        $team->forceFill(['kind' => 'academy'])->save();
        $target = Team::factory()->create(['organization_id' => $org->id, 'game_id' => $team->game_id, 'kind' => 'first']);
        $source = TeamMembership::factory()->create(['team_id' => $team->id, 'person_id' => $person->id]);
        $captain = TeamMembership::factory()->create(['team_id' => $team->id, 'person_id' => $person->id, 'role_key' => 'captain']);
        $future = TeamMembership::factory()->create(['team_id' => $team->id, 'person_id' => $person->id, 'role_key' => 'coach', 'starts_at' => now()->addDay()]);
        $saved = app(TeamMembershipService::class)->change($this->personContext($org, $user), $team->id, $source->id, [
            'version' => $source->version, 'action' => 'promotion', 'destination_team_id' => $target->id, 'destination_version' => $target->version, 'role_key' => 'player', 'reason_key' => 'promotion']);
        $this->assertSame($target->id, $saved->team_id);
        $this->assertSame(now()->toIso8601String(), $saved->starts_at->toIso8601String());
        $this->assertDatabaseHas('team_memberships', ['id' => $source->id, 'status' => 'closed']);
        $this->assertDatabaseHas('team_memberships', ['id' => $captain->id, 'status' => 'closed']);
        $this->assertDatabaseHas('team_memberships', ['id' => $future->id, 'status' => 'cancelled']);
        $this->assertDatabaseCount('team_movements', 3);
        $this->assertDatabaseHas('team_movements', ['membership_id' => $source->id, 'destination_membership_id' => $saved->id, 'action' => 'promotion', 'actor_user_id' => $user->id]);
    }

    public function test_failed_movement_and_audit_roll_back_source_destination_and_journal(): void
    {
        $this->travelTo(now()->startOfSecond());
        [$org,$user,$team,$person] = $this->editor();
        $target = Team::factory()->create(['organization_id' => $org->id, 'game_id' => $team->game_id]);
        $source = TeamMembership::factory()->create(['team_id' => $team->id, 'person_id' => $person->id]);
        $audit = Mockery::mock(TenantAuditJournal::class);
        $audit->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $this->app->instance(TenantAuditJournal::class, $audit);
        try {
            app(TeamMembershipService::class)->change($this->personContext($org, $user), $team->id, $source->id, ['version' => $source->version, 'action' => 'transfer', 'reason_key' => 'transfer',
                'destination_team_id' => $target->id, 'destination_version' => $target->version, 'role_key' => 'player']);
            $this->fail('Audit failure must roll back.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
        }
        $this->assertDatabaseCount('team_memberships', 1);
        $this->assertDatabaseCount('team_movements', 0);
        $this->assertDatabaseHas('team_memberships', ['id' => $source->id, 'status' => 'active', 'version' => $source->version]);
    }

    public function test_departure_closes_running_and_cancels_future_memberships_without_team_manage_grant(): void
    {
        $this->travelTo(now()->startOfSecond());
        [$org,$user,$team,$person] = $this->editor(['sports.read', 'sports.write', 'administrative.write', 'memberships.manage', 'invitations.manage']);
        $current = TeamMembership::factory()->create(['team_id' => $team->id, 'person_id' => $person->id]);
        $future = TeamMembership::factory()->create(['team_id' => $team->id, 'person_id' => $person->id, 'role_key' => 'coach', 'starts_at' => now()->addDay()]);
        $body = new PersonLifecycleData(['reason_key' => 'other', 'confirm' => true], $person->version);
        $service = app(PersonDepartureService::class);
        $ctx = $this->personContext($org, $user);
        $service->depart($ctx, $person->id, $body);
        $service->depart($ctx, $person->id, $body);
        $this->assertDatabaseHas('team_memberships', ['id' => $current->id, 'status' => 'closed']);
        $this->assertDatabaseHas('team_memberships', ['id' => $future->id, 'status' => 'cancelled']);
        $this->assertDatabaseCount('team_movements', 2);
        $this->assertDatabaseHas('team_movements', ['membership_id' => $current->id, 'reason_key' => 'person_departure']);
    }

    public function test_scheduled_closure_remains_effective_until_its_end_and_departure_can_shorten_it(): void
    {
        $this->travelTo(now()->startOfSecond());
        [$org,$user,$team,$person] = $this->editor(['sports.read', 'teams.manage', 'sports.write', 'administrative.write', 'memberships.manage', 'invitations.manage']);
        $period = TeamMembership::factory()->create(['team_id' => $team->id, 'person_id' => $person->id]);
        $service = app(TeamMembershipService::class);
        $ctx = $this->personContext($org, $user);
        $scheduled = $service->change($ctx, $team->id, $period->id, ['version' => $period->version, 'action' => 'close', 'reason_key' => 'other', 'effective_at' => now()->addDay()->toIso8601String()]);
        $this->assertTrue($scheduled->effectiveAt(CarbonImmutable::now()));
        app(PersonDepartureService::class)->depart($ctx, $person->id, new PersonLifecycleData(['reason_key' => 'other', 'confirm' => true], $person->version));
        $this->assertFalse($scheduled->refresh()->effectiveAt(CarbonImmutable::now()));
        $this->assertSame('closed', $scheduled->status);
    }

    public function test_person_former_and_policy_without_explicit_permissions_are_denied(): void
    {
        [$org,$user,$team,$person] = $this->editor();
        $person->forceFill(['status' => 'former'])->save();
        try {
            app(TeamMembershipService::class)->create($this->personContext($org, $user), $team->id, $this->payload($person));
            $this->fail('Former person must fail.');
        } catch (DomainConflictException $error) {
            $this->assertSame('TEAM_PERSON_UNAVAILABLE', $error->errorCode());
        }
        $owner = User::findOrFail($org->owner_user_id);
        $this->assertFalse(Gate::forUser($owner)->allows('manage', [TeamMembership::class, $this->personContext($org, $owner)]));
        $this->assertTrue(Gate::forUser($user)->allows('manage', [TeamMembership::class, $this->personContext($org, $user)]));
        $this->assertFalse(Gate::forUser($owner)->allows('viewAny', [TeamMembership::class, $this->personContext($org, $user)]));
    }

    public function test_incompatible_destinations_or_overlaps_leave_the_source_unchanged(): void
    {
        $this->freezeSecond();
        [$org,$user,$team,$person] = $this->editor();
        $source = TeamMembership::factory()->create(['team_id' => $team->id, 'person_id' => $person->id]);
        $target = Team::factory()->create(['organization_id' => $org->id, 'game_id' => $team->game_id]);
        $service = app(TeamMembershipService::class);
        $ctx = $this->personContext($org, $user);
        $body = ['version' => $source->version, 'action' => 'promotion', 'reason_key' => 'promotion', 'destination_team_id' => $target->id, 'destination_version' => $target->version, 'role_key' => 'player'];
        try {
            $service->change($ctx, $team->id, $source->id, $body);
            $this->fail('Wrong promotion context must fail.');
        } catch (DomainConflictException $error) {
            $this->assertSame('TEAM_MOVEMENT_DESTINATION_INVALID', $error->errorCode());
        }
        TeamMembership::factory()->create(['team_id' => $target->id, 'person_id' => $person->id]);
        try {
            $service->change($ctx, $team->id, $source->id, [...$body, 'action' => 'transfer']);
            $this->fail('Destination overlap must fail.');
        } catch (DomainConflictException $error) {
            $this->assertSame('TEAM_MEMBERSHIP_OVERLAP', $error->errorCode());
        }
        $this->assertDatabaseHas('team_memberships', ['id' => $source->id, 'version' => $source->version, 'status' => 'active']);
        $this->assertDatabaseCount('team_movements', 0);
    }

    public function test_departure_audit_failure_restores_team_period_and_person_and_self_departure_closes_team_period(): void
    {
        $this->freezeSecond();
        [$org,$user,$team,$person] = $this->editor(['sports.read', 'sports.write', 'administrative.write', 'memberships.manage', 'invitations.manage']);
        $person->forceFill(['user_id' => $user->id])->save();
        $source = TeamMembership::factory()->create(['team_id' => $team->id, 'person_id' => $person->id]);
        $realAudit = app(TenantAuditJournal::class);
        $audit = Mockery::mock(TenantAuditJournal::class);
        $audit->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $this->app->instance(TenantAuditJournal::class, $audit);
        $ctx = $this->personContext($org, $user);
        $body = new PersonLifecycleData(['reason_key' => 'other', 'confirm' => true], $person->version);
        try {
            app(PersonDepartureService::class)->depart($ctx, $person->id, $body);
            $this->fail('Audit failure must roll back departure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
        }
        $this->assertDatabaseHas('team_memberships', ['id' => $source->id, 'status' => 'active', 'version' => $source->version]);
        $this->assertDatabaseHas('people', ['id' => $person->id, 'status' => 'active']);
        $this->assertDatabaseHas('organization_memberships', ['organization_id' => $org->id, 'user_id' => $user->id, 'status' => 'active']);
        $this->assertDatabaseCount('team_movements', 0);
        $this->app->instance(TenantAuditJournal::class, $realAudit);
        app(PersonDepartureService::class)->depart($ctx, $person->id, $body);
        $this->assertDatabaseHas('team_memberships', ['id' => $source->id, 'status' => 'closed']);
        $this->assertDatabaseCount('team_movements', 1);
    }

    public function test_person_period_cap_counts_closed_and_cancelled_history(): void
    {
        $this->freezeSecond();
        [$org,$user,$team,$person] = $this->editor();
        $rows = [];
        for ($i = 0; $i < 1000; $i++) {
            $start = now()->subDays(1001 - $i);
            $rows[] = ['id' => (string) Str::uuid(), 'organization_id' => $org->id, 'team_id' => $team->id, 'person_id' => $person->id,
                'role_key' => 'player', 'starts_at' => $start->format('Y-m-d H:i:s'), 'ends_at' => $start->addDay()->format('Y-m-d H:i:s'), 'status' => 'closed', 'version' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('team_memberships')->insert($chunk);
        }
        try {
            app(TeamMembershipService::class)->create($this->personContext($org, $user), $team->id, [...$this->payload($person), 'starts_at' => now()->toIso8601String()]);
            $this->fail('History must count toward the cap.');
        } catch (DomainConflictException $error) {
            $this->assertSame('TEAM_MEMBERSHIP_LIMIT_REACHED', $error->errorCode());
        }
        $this->assertDatabaseCount('team_memberships', 1000);
        $this->assertDatabaseCount('team_movements', 0);
    }

    /** @param list<string> $permissions
     * @return array{Organization,User,Team,Person}
     */
    private function editor(array $permissions = ['sports.read', 'teams.manage']): array
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, $permissions);

        return [$org, $user, Team::factory()->create(['organization_id' => $org->id]), Person::factory()->create(['organization_id' => $org->id, 'status' => 'active'])];
    }

    /** @return array<string,mixed> */
    private function payload(Person $person): array
    {
        return ['person_id' => $person->id, 'role_key' => 'player', 'starts_at' => now()->subDay()->startOfSecond()->toIso8601String(), 'reason_key' => 'initial'];
    }
}
