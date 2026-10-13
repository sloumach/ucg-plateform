<?php

namespace Tests\Feature;

use App\Exceptions\DomainConflictException;
use App\Modules\People\Application\Data\PersonLifecycleData;
use App\Modules\People\Application\Services\PersonDepartureService;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Teams\Application\Contracts\HistoricalLineups;
use App\Modules\Teams\Application\Services\TeamLineupService;
use App\Modules\Teams\Application\Services\TeamMembershipService;
use App\Modules\Teams\Domain\Models\OrganizationGame;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Teams\Domain\Models\TeamLineup;
use App\Modules\Teams\Domain\Models\TeamMembership;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\ProvidesLineups;
use Tests\TestCase;

class TeamLineupServiceTest extends TestCase
{
    use LazilyRefreshDatabase, ProvidesLineups;

    public function test_final_snapshot_survives_rename_transfer_and_former_person_without_current_identity_lookup(): void
    {
        [$org,$user,$team,$period] = $this->lineupEditor();
        $ctx = $this->personContext($org, $user);
        $person = Person::findOrFail($period->person_id);
        $service = app(TeamLineupService::class);
        $draft = $service->create($ctx, $team->id, $this->lineupPayload($team, $period));
        $final = $service->change($ctx, $team->id, $draft->id, ['action' => 'finalize', 'version' => $draft->version, 'team_version' => $team->version]);
        $snapshot = app(HistoricalLineups::class)->snapshot($ctx, $team->id, $final->id);

        $target = Team::factory()->create(['organization_id' => $org->id, 'game_id' => $team->game_id]);
        app(TeamMembershipService::class)->change($ctx, $team->id, $period->id, ['action' => 'transfer', 'version' => $period->version, 'reason_key' => 'transfer', 'role_key' => 'player',
            'destination_team_id' => $target->id, 'destination_version' => $target->version]);
        $person->forceFill(['pseudonym' => 'Nouveau pseudo', 'status' => 'former'])->save();
        $team->forceFill(['name' => 'Équipe renommée', 'version' => (string) Str::uuid()])->save();

        $this->assertSame($snapshot, app(HistoricalLineups::class)->snapshot($ctx, $team->id, $final->id));
        $this->assertSame($user->id, $final->finalized_by);
        $this->assertSame('2026-10-13T12:00:00+00:00', $final->finalized_at?->toIso8601String());
        $this->assertDatabaseCount('team_lineup_entries', 1);
    }

    public function test_replace_draft_keeps_identity_updates_order_and_role_then_cancel_retains_it(): void
    {
        [$org,$user,$team,$period] = $this->lineupEditor();
        $ctx = $this->personContext($org, $user);
        $service = app(TeamLineupService::class);
        $draft = $service->create($ctx, $team->id, $this->lineupPayload($team, $period));
        $second = TeamMembership::factory()->create(['team_id' => $team->id, 'role_key' => 'substitute']);
        $body = $this->lineupPayload($team, $period);
        unset($body['purpose']);
        $changed = $service->change($ctx, $team->id, $draft->id, [...$body, 'action' => 'replace', 'version' => $draft->version,
            'label' => 'Inscription préparée', 'entries' => [$this->lineupChoice($second), $this->lineupChoice($period, 'substitute')]]);

        $this->assertSame($draft->id, $changed->id);
        $this->assertSame('match', $changed->purpose);
        $this->assertSame([$second->person_id, $period->person_id], $changed->entries->pluck('person_id')->all());
        $this->assertSame(['starter', 'substitute'], $changed->entries->pluck('slot_key')->all());
        $this->assertNotSame($draft->version, $changed->version);
        $cancelled = $service->change($ctx, $team->id, $changed->id, ['action' => 'cancel', 'version' => $changed->version]);
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertDatabaseCount('team_lineup_entries', 2);
        $this->assertDatabaseCount('organization_audit_events', 3);
    }

    /** @return array<string,array{array<string,mixed>}> */
    public static function unavailablePeriods(): array
    {
        return ['future' => [['starts_at' => '2026-10-14 00:00:00']], 'ended at boundary' => [['ends_at' => '2026-10-13 11:00:00']],
            'cancelled' => [['status' => 'cancelled']], 'captain only' => [['role_key' => 'captain']], 'coach' => [['role_key' => 'coach']], 'analyst' => [['role_key' => 'analyst']]];
    }

    /** @param array<string,mixed> $changes */
    #[DataProvider('unavailablePeriods')]
    public function test_unavailable_membership_never_creates_a_draft(array $changes): void
    {
        [$org,$user,$team] = $this->lineupEditor();
        $period = TeamMembership::factory()->create(['team_id' => $team->id, ...$changes]);
        try {
            app(TeamLineupService::class)->create($this->personContext($org, $user), $team->id, $this->lineupPayload($team, $period));
            $this->fail('Unavailable selection must fail.');
        } catch (ValidationException $error) {
            $this->assertSame(__('team_lineups.unavailable'), $error->errors()['entries'][0]);
        }
        $this->assertDatabaseCount('team_lineups', 0);
        $this->assertDatabaseCount('team_lineup_entries', 0);
        $this->assertDatabaseCount('organization_audit_events', 0);
    }

    public function test_historical_closed_period_and_former_person_are_allowed_when_date_is_covered(): void
    {
        [$org,$user,$team] = $this->lineupEditor();
        $period = TeamMembership::factory()->create(['team_id' => $team->id, 'status' => 'closed', 'ends_at' => '2026-10-13 12:00:00']);
        Person::findOrFail($period->person_id)->forceFill(['status' => 'former'])->save();
        $body = [...$this->lineupPayload($team, $period), 'effective_at' => '2026-10-13T13:00:00+02:00'];
        $draft = app(TeamLineupService::class)->create($this->personContext($org, $user), $team->id, $body);
        $this->assertSame('2026-10-13T11:00:00+00:00', $draft->effective_at->toIso8601String());
        $this->assertDatabaseCount('team_lineup_entries', 1);
    }

    public function test_stale_membership_prevents_freezing_and_preserves_the_existing_draft_and_entries(): void
    {
        [$org,$user,$team,$period] = $this->lineupEditor();
        $ctx = $this->personContext($org, $user);
        $service = app(TeamLineupService::class);
        $draft = $service->create($ctx, $team->id, $this->lineupPayload($team, $period));
        $entryId = $draft->entries->sole()->id;
        app(TeamMembershipService::class)->change($ctx, $team->id, $period->id, ['action' => 'close', 'version' => $period->version, 'reason_key' => 'other', 'effective_at' => '2026-10-13T12:00:00+00:00']);
        try {
            $service->change($ctx, $team->id, $draft->id, ['action' => 'finalize', 'version' => $draft->version, 'team_version' => $team->version]);
            $this->fail('Stale source must fail.');
        } catch (DomainConflictException $error) {
            $this->assertSame('TEAM_LINEUP_MEMBERSHIP_STALE', $error->errorCode());
        }
        $this->assertDatabaseHas('team_lineups', ['id' => $draft->id, 'status' => 'draft', 'version' => $draft->version, 'finalized_at' => null]);
        $this->assertDatabaseHas('team_lineup_entries', ['id' => $entryId, 'membership_version' => $period->version]);
    }

    public function test_failure_of_final_audit_rolls_back_snapshot_refresh_and_status(): void
    {
        [$org,$user,$team,$period] = $this->lineupEditor();
        $ctx = $this->personContext($org, $user);
        $draft = app(TeamLineupService::class)->create($ctx, $team->id, $this->lineupPayload($team, $period));
        $entry = $draft->entries->sole();
        $audit = Mockery::mock(TenantAuditJournal::class);
        $audit->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $this->app->instance(TenantAuditJournal::class, $audit);
        try {
            app(TeamLineupService::class)->change($ctx, $team->id, $draft->id, ['action' => 'finalize', 'version' => $draft->version, 'team_version' => $team->version]);
            $this->fail('Audit failure must roll back.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
        }
        $this->assertDatabaseHas('team_lineups', ['id' => $draft->id, 'status' => 'draft', 'version' => $draft->version]);
        $this->assertModelExists($entry);
        $this->assertDatabaseCount('organization_audit_events', 1);
    }

    public function test_draft_snapshot_port_is_refused_and_final_or_cancelled_records_cannot_change(): void
    {
        [$org,$user,$team,$period] = $this->lineupEditor();
        $ctx = $this->personContext($org, $user);
        $service = app(TeamLineupService::class);
        $draft = $service->create($ctx, $team->id, $this->lineupPayload($team, $period));
        try {
            app(HistoricalLineups::class)->snapshot($ctx, $team->id, $draft->id);
            $this->fail('Draft is not history.');
        } catch (DomainConflictException $error) {
            $this->assertSame('TEAM_LINEUP_NOT_FINALIZED', $error->errorCode());
        }
        $cancelled = $service->change($ctx, $team->id, $draft->id, ['action' => 'cancel', 'version' => $draft->version]);
        $this->expectException(DomainConflictException::class);
        $service->change($ctx, $team->id, $draft->id, ['action' => 'cancel', 'version' => $cancelled->version]);
    }

    public function test_archived_team_and_removed_game_reject_new_drafts_but_allow_cancellation(): void
    {
        [$org,$user,$team,$period] = $this->lineupEditor();
        $ctx = $this->personContext($org, $user);
        $service = app(TeamLineupService::class);
        $draft = $service->create($ctx, $team->id, $this->lineupPayload($team, $period));
        OrganizationGame::factory()->create(['organization_id' => $org->id, 'game_id' => $team->game_id, 'enabled' => false]);
        try {
            $service->change($ctx, $team->id, $draft->id, ['action' => 'finalize', 'version' => $draft->version, 'team_version' => $team->version]);
            $this->fail('Removed game must fail.');
        } catch (DomainConflictException $error) {
            $this->assertSame('GAME_CONTEXT_UNAVAILABLE', $error->errorCode());
        }
        $this->assertSame('cancelled', $service->change($ctx, $team->id, $draft->id, ['action' => 'cancel', 'version' => $draft->version])->status);
        $team->forceFill(['status' => 'archived'])->save();
        $this->expectException(DomainConflictException::class);
        $service->create($ctx, $team->id, $this->lineupPayload($team, $period));
    }

    public function test_finalization_captures_fresh_labels_and_audit_never_copies_them(): void
    {
        [$org,$user,$team,$period] = $this->lineupEditor();
        $ctx = $this->personContext($org, $user);
        $service = app(TeamLineupService::class);
        $draft = $service->create($ctx, $team->id, $this->lineupPayload($team, $period));
        Person::findOrFail($period->person_id)->forceFill(['pseudonym' => '<script>SPORT_BAD</script>'])->save();
        $final = $service->change($ctx, $team->id, $draft->id, ['action' => 'finalize', 'version' => $draft->version, 'team_version' => $team->version]);
        $this->assertSame('<script>SPORT_BAD</script>', $final->entries->sole()->pseudonym);
        $audit = DB::table('organization_audit_events')->where('organization_id', $org->id)->get()->toJson();
        $this->assertStringNotContainsString('SPORT_BAD', $audit);
        $this->assertStringNotContainsString('Scrim du mardi', $audit);
    }

    public function test_cancelled_history_counts_toward_the_team_lineup_limit(): void
    {
        [$org, $user, $team, $period] = $this->lineupEditor();
        $template = TeamLineup::factory()->make(['team_id' => $team->id])->getAttributes();
        $rows = [];
        for ($index = 0; $index < 1000; $index++) {
            $rows[] = [...$template, 'id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('team_lineups')->insert($chunk);
        }
        DB::table('team_lineups')->where('organization_id', $org->id)->update(['status' => 'cancelled']);
        try {
            app(TeamLineupService::class)->create($this->personContext($org, $user), $team->id, $this->lineupPayload($team, $period));
            $this->fail('Retained history must count toward the cap.');
        } catch (DomainConflictException $error) {
            $this->assertSame('TEAM_LINEUP_LIMIT_REACHED', $error->errorCode());
        }
        $this->assertDatabaseCount('team_lineups', 1000);
        $this->assertDatabaseCount('team_lineup_entries', 0);
        $this->assertDatabaseCount('organization_audit_events', 0);
    }

    public function test_organization_departure_closes_the_roster_without_rewriting_a_frozen_match_composition(): void
    {
        [$org, $user, $team, $period] = $this->lineupEditor(['sports.read', 'teams.manage', 'sports.write', 'administrative.write', 'memberships.manage', 'invitations.manage']);
        $context = $this->personContext($org, $user);
        $service = app(TeamLineupService::class);
        $draft = $service->create($context, $team->id, $this->lineupPayload($team, $period));
        $final = $service->change($context, $team->id, $draft->id, ['action' => 'finalize', 'version' => $draft->version, 'team_version' => $team->version]);
        $snapshot = $service->snapshot($context, $team->id, $final->id);
        $person = Person::findOrFail($period->person_id);

        app(PersonDepartureService::class)->depart($context, $person->id, new PersonLifecycleData(['reason_key' => 'other', 'confirm' => true], $person->version));

        $this->assertDatabaseHas('team_memberships', ['id' => $period->id, 'status' => 'closed']);
        $this->assertDatabaseCount('person_departures', 1);
        $this->assertSame($snapshot, $service->snapshot($context, $team->id, $final->id));
        $this->assertDatabaseCount('team_lineup_entries', 1);
    }
}
