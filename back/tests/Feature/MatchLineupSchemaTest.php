<?php

namespace Tests\Feature;

use App\Architecture\Database\TenantSchemaInspector;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesMatchLineups;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class MatchLineupSchemaTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesMatchLineups, ProvidesPeopleAccess;

    public function test_empty_schema_roundtrip_inventory_and_export_are_complete(): void
    {
        $migration = require database_path('migrations/2026_10_18_101019_create_match_lineup_revisions.php');
        $matchScores = require database_path('migrations/2026_10_18_135008_create_match_score_rules_and_game_instances.php');
        $matchResults = require database_path('migrations/2026_10_18_144624_create_match_result_events_and_guards.php');
        $notebook = require database_path('migrations/2026_10_18_155140_create_match_notebook_revisions.php');
        $observations = require database_path('migrations/2026_10_19_113618_create_statistical_observations.php');
        $observations->down();
        $notebook->down();
        $corrections = require database_path('migrations/2026_10_18_184341_create_match_score_corrections_and_recalculations.php');
        $corrections->down();
        $matchResults->down();
        $matchScores->down();
        $migration->down();
        $this->assertFalse(Schema::hasTable('match_lineup_revisions'));
        $migration->up();
        $matchScores->up();
        $matchResults->up();
        $corrections->up();
        $notebook->up();
        $observations->up();
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        app(PortabilityRepository::class)->assertCatalog();
    }

    public function test_sql_rejects_history_rewrites_foreign_participants_and_stale_versions(): void
    {
        $s = $this->matchLineupScenario();
        $this->postJson($s['path'], ['version' => $s['match']->version, 'lineup_id' => $s['lineup']->id, 'reason' => 'Sélection retenue avant la rencontre.'])->assertCreated();
        $row = (array) DB::table('match_lineup_revisions')->first();
        $this->reject(fn () => DB::table('match_lineup_revisions')->update(['reason' => 'overwrite']));
        $this->reject(fn () => DB::table('match_lineup_revisions')->delete());
        $this->reject(fn () => DB::table('match_lineup_revisions')->insert([...$row, 'id' => (string) Str::uuid(), 'number' => 2, 'match_version' => (string) Str::uuid()]));
        $this->reject(fn () => DB::table('match_lineup_revisions')->insert([...$row, 'id' => (string) Str::uuid(), 'participant_id' => (string) Str::uuid(), 'number' => 2]));
        $this->reject(fn () => DB::table('match_participants')->where('id', $row['participant_id'])->delete());
        $export = iterator_to_array(app(PortabilityRepository::class)->rows($s['org']->id, 100));
        $rows = array_values(array_filter($export, fn ($r): bool => $r['table'] === 'match_lineup_revisions'));
        $this->assertCount(1, $rows);
        $this->assertSame($row['snapshot'], $rows[0]['data']['snapshot']);
        $this->expectException(\RuntimeException::class);
        (require database_path('migrations/2026_10_18_101019_create_match_lineup_revisions.php'))->down();
    }

    private function reject(\Closure $attempt): void
    {
        try {
            DB::transaction(function () use ($attempt): void {
                $attempt();
            });
            $this->fail('SQL must reject this invalid or destructive lineup write.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
