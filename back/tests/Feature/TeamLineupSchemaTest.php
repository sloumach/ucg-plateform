<?php

namespace Tests\Feature;

use App\Architecture\Database\TenantSchemaInspector;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Teams\Domain\Models\TeamLineup;
use App\Modules\Teams\Domain\Models\TeamLineupEntry;
use App\Modules\Teams\Domain\Models\TeamMembership;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TeamLineupSchemaTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_empty_round_trip_recreates_constraints_and_export_catalog(): void
    {
        $this->assertDatabaseCount('team_lineups', 0);
        $migration = require database_path('migrations/2026_10_13_204834_create_team_lineup_tables.php');
        $eligibility = require database_path('migrations/2026_10_13_215251_create_team_eligibility_rules_and_reports.php');
        $eligibility->down();
        $migration->down();
        $migration->up();
        $eligibility->up();
        app(PortabilityRepository::class)->assertCatalog();
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        $this->assertDatabaseCount('team_lineup_entries', 0);
    }

    public function test_snapshot_sql_blocks_all_parent_and_participant_rewrites_and_new_insertions(): void
    {
        $entry = TeamLineupEntry::factory()->create();
        $lineup = TeamLineup::findOrFail($entry->lineup_id);
        DB::table('team_lineups')->where('id', $lineup->id)->update(['status' => 'finalized', 'finalized_by' => $lineup->created_by, 'finalized_at' => now()]);
        $this->reject(fn () => DB::table('team_lineups')->where('id', $lineup->id)->update(['label' => 'Spoof']));
        $this->reject(fn () => DB::table('team_lineups')->where('id', $lineup->id)->delete());
        $this->reject(fn () => DB::table('team_lineup_entries')->where('id', $entry->id)->update(['pseudonym' => 'Spoof']));
        $this->reject(fn () => DB::table('team_lineup_entries')->where('id', $entry->id)->delete());
        $second = TeamMembership::factory()->create(['team_id' => $lineup->team_id]);
        $this->reject(fn () => TeamLineupEntry::factory()->create(['lineup_id' => $lineup->id, 'membership_id' => $second->id, 'position' => 2]));
        $this->assertDatabaseHas('team_lineup_entries', ['id' => $entry->id, 'pseudonym' => 'Camille']);
        $this->assertDatabaseCount('team_lineup_entries', 1);
    }

    public function test_sql_refuses_cross_tenant_and_cross_team_sources_and_duplicate_people_or_positions(): void
    {
        $entry = TeamLineupEntry::factory()->create();
        $lineup = TeamLineup::findOrFail($entry->lineup_id);
        $foreign = TeamMembership::factory()->create();
        $other = TeamMembership::factory()->create(['organization_id' => $lineup->organization_id, 'team_id' => Team::factory()->create(['organization_id' => $lineup->organization_id])->id]);
        $this->reject(fn () => TeamLineupEntry::factory()->create(['lineup_id' => $lineup->id, 'membership_id' => $foreign->id, 'position' => 2]));
        $this->reject(fn () => TeamLineupEntry::factory()->create(['lineup_id' => $lineup->id, 'membership_id' => $other->id, 'position' => 2]));
        $this->reject(fn () => TeamLineupEntry::factory()->create(['lineup_id' => $lineup->id, 'membership_id' => $entry->membership_id, 'position' => 2]));
        $second = TeamMembership::factory()->create(['team_id' => $lineup->team_id]);
        $this->reject(fn () => TeamLineupEntry::factory()->create(['lineup_id' => $lineup->id, 'membership_id' => $second->id, 'position' => 1]));
        $this->assertDatabaseCount('team_lineup_entries', 1);
    }

    public function test_sql_requires_a_starter_and_membership_covering_the_snapshot_date_before_freezing(): void
    {
        $entry = TeamLineupEntry::factory()->create(['slot_key' => 'substitute']);
        $lineup = TeamLineup::findOrFail($entry->lineup_id);
        $freeze = ['status' => 'finalized', 'finalized_by' => $lineup->created_by, 'finalized_at' => now()];
        $this->reject(fn () => DB::table('team_lineups')->where('id', $lineup->id)->update($freeze));
        DB::table('team_lineup_entries')->where('id', $entry->id)->delete();
        $player = TeamLineupEntry::factory()->create(['lineup_id' => $lineup->id, 'position' => 1]);
        $period = TeamMembership::findOrFail($player->membership_id);
        DB::table('team_memberships')->where('id', $period->id)->update(['ends_at' => $lineup->effective_at, 'version' => (string) Str::uuid()]);
        $this->reject(fn () => DB::table('team_lineups')->where('id', $lineup->id)->update($freeze));
        $this->assertDatabaseHas('team_lineups', ['id' => $lineup->id, 'status' => 'draft']);
    }

    public function test_sql_rejects_reparenting_terminal_cancellation_and_out_of_bound_positions(): void
    {
        $entry = TeamLineupEntry::factory()->create();
        $lineup = TeamLineup::findOrFail($entry->lineup_id);
        $this->reject(fn () => DB::table('team_lineups')->where('id', $lineup->id)->update(['purpose' => 'registration']));
        $second = TeamMembership::factory()->create(['team_id' => $lineup->team_id]);
        $this->reject(fn () => TeamLineupEntry::factory()->create(['lineup_id' => $lineup->id, 'membership_id' => $second->id, 'position' => 51]));
        DB::table('team_lineups')->where('id', $lineup->id)->update(['status' => 'cancelled']);
        $this->reject(fn () => DB::table('team_lineups')->where('id', $lineup->id)->update(['status' => 'draft']));
        $this->reject(fn () => DB::table('team_lineup_entries')->where('id', $entry->id)->delete());
        $this->assertDatabaseCount('team_lineup_entries', 1);
    }

    public function test_populated_rollback_is_refused_and_exports_are_scoped_to_the_requested_tenant(): void
    {
        $entry = TeamLineupEntry::factory()->create();
        TeamLineupEntry::factory()->create();
        $rows = iterator_to_array(app(PortabilityRepository::class)->rows($entry->organization_id, 100));
        $lineups = array_values(array_filter($rows, fn (array $row): bool => $row['table'] === 'team_lineups'));
        $entries = array_values(array_filter($rows, fn (array $row): bool => $row['table'] === 'team_lineup_entries'));
        $this->assertCount(1, $lineups);
        $this->assertCount(1, $entries);
        $this->assertSame($entry->lineup_id, $lineups[0]['data']['id']);
        $this->assertSame($entry->id, $entries[0]['data']['id']);
        $migration = require database_path('migrations/2026_10_13_204834_create_team_lineup_tables.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    /** @param \Closure():mixed $write */
    private function reject(\Closure $write): void
    {
        try {
            DB::transaction($write);
            $this->fail('SQL must reject this write.');
        } catch (QueryException $error) {
            $this->assertContains((string) $error->getCode(), ['23000', '23514', '23503', '23505']);
        }
    }
}
