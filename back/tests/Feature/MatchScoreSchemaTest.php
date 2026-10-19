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
use Tests\Fixtures\ProvidesMatchScores;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class MatchScoreSchemaTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesMatchScores, ProvidesPeopleAccess;

    public function test_empty_schema_roundtrip_inventory_and_export_are_complete(): void
    {
        $migration = require database_path('migrations/2026_10_18_135008_create_match_score_rules_and_game_instances.php');
        $matchResults = require database_path('migrations/2026_10_18_144624_create_match_result_events_and_guards.php');
        $notebook = require database_path('migrations/2026_10_18_155140_create_match_notebook_revisions.php');
        $observations = require database_path('migrations/2026_10_19_113618_create_statistical_observations.php');
        $statisticalExecution = require database_path('migrations/2026_10_19_175141_create_statistical_import_executions.php');
        $statisticalExecution->down();
        $observations->down();
        $notebook->down();
        $corrections = require database_path('migrations/2026_10_18_184341_create_match_score_corrections_and_recalculations.php');
        $corrections->down();
        $matchResults->down();
        $migration->down();
        $this->assertFalse(Schema::hasTable('match_game_instances'));
        $migration->up();
        $matchResults->up();
        $corrections->up();
        $notebook->up();
        $observations->up();
        $statisticalExecution->up();
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        app(PortabilityRepository::class)->assertCatalog();
    }

    public function test_history_is_sql_immutable_versioned_scoped_and_exported_as_ciphertext(): void
    {
        $s = $this->scoreScenario();
        $this->postJson($s['root'].'/games', $this->scorePayload($s))->assertCreated();
        foreach (['match_game_instances', 'match_score_rules'] as $table) {
            $this->reject(fn () => DB::table($table)->update(['reason' => 'overwrite']));
            $this->reject(fn () => DB::table($table)->delete());
        }
        $row = (array) DB::table('match_game_instances')->first();
        $this->reject(fn () => DB::table('match_game_instances')->insert([...$row, 'id' => (string) Str::uuid(), 'number' => 2, 'match_version' => (string) Str::uuid()]));
        $this->reject(fn () => DB::table('match_game_instances')->insert([...$row, 'id' => (string) Str::uuid(), 'number' => 3]));
        $this->reject(fn () => DB::table('match_game_instances')->insert([...$row, 'id' => (string) Str::uuid(), 'match_id' => (string) Str::uuid(), 'number' => 1]));
        $export = iterator_to_array(app(PortabilityRepository::class)->rows($s['org']->id, 100));
        $games = array_values(array_filter($export, fn ($r): bool => $r['table'] === 'match_game_instances'));
        $this->assertCount(1, $games);
        $this->assertSame($row['results'], $games[0]['data']['results']);
        $this->expectException(\RuntimeException::class);
        (require database_path('migrations/2026_10_18_135008_create_match_score_rules_and_game_instances.php'))->down();
    }

    public function test_sql_refuses_wrong_rules_format_limit_and_unstarted_match(): void
    {
        $s = $this->scoreScenario();
        $row = (array) DB::table('match_score_rules')->first();
        $s2 = $this->scoreScenario(configure: false);
        $base = [...$row, 'id' => (string) Str::uuid(), 'organization_id' => $s2['org']->id, 'match_id' => $s2['record']['id'], 'match_version' => $s2['record']['version']];
        $this->reject(fn () => DB::table('match_score_rules')->insert([...$base, 'game_limit' => 5]));
        $this->reject(fn () => DB::table('match_score_rules')->insert([...$base, 'format' => 'bo1', 'game_limit' => 1]));
        $this->assertDatabaseCount('match_score_rules', 1);
    }

    private function reject(\Closure $attempt): void
    {
        try {
            DB::transaction(fn () => $attempt());
            $this->fail('SQL must reject invalid or destructive score writes.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
