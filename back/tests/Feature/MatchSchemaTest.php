<?php

namespace Tests\Feature;

use App\Architecture\Database\TenantSchemaInspector;
use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesMatches;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class MatchSchemaTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesMatches, ProvidesPeopleAccess;

    public function test_composite_foreign_keys_and_slot_or_identity_uniqueness_are_enforced(): void
    {
        [$org, , , $person, $other, $game] = $this->matchScenario();
        $data = $this->postJson($this->matchRoot($org), $this->matchPayload($game, $person, $other))->assertCreated()->json('data');
        $row = (array) DB::table('match_participants')->where('match_id', $data['id'])->first();
        $foreign = MatchRecord::factory()->create();
        $this->reject(fn () => DB::table('match_participants')->insert([...$row, 'id' => (string) Str::uuid(), 'match_id' => $foreign->id]));
        $this->reject(fn () => DB::table('match_participants')->insert([...$row, 'id' => (string) Str::uuid(), 'slot' => 3]));
        $this->reject(fn () => DB::table('matches')->where('id', $data['id'])->update(['organization_id' => $foreign->organization_id, 'version' => (string) Str::uuid()]));
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
    }

    public function test_confirmed_core_and_participants_and_status_history_are_sql_protected(): void
    {
        [$org, , , $person, $other, $game] = $this->matchScenario();
        $data = $this->postJson($this->matchRoot($org), $this->matchPayload($game, $person, $other))->assertCreated()->json('data');
        $this->postJson($this->matchRoot($org).'/'.$data['id'].'/transitions', ['version' => $data['version'], 'status' => 'confirmed', 'reason' => 'Vérification complète avant confirmation.'])->assertOk();
        $this->reject(fn () => DB::table('matches')->where('id', $data['id'])->update(['title' => 'overwrite', 'version' => (string) Str::uuid()]));
        $this->reject(fn () => DB::table('match_participants')->where('match_id', $data['id'])->delete());
        $this->reject(fn () => DB::table('match_participants')->where('match_id', $data['id'])->update(['label' => 'overwrite']));
        $this->reject(fn () => DB::table('matches')->where('id', $data['id'])->update(['status' => 'validated', 'version' => (string) Str::uuid()]));
        $this->reject(fn () => DB::table('match_status_events')->where('match_id', $data['id'])->update(['reason' => 'overwrite']));
        $this->reject(fn () => DB::table('match_status_events')->where('match_id', $data['id'])->delete());
        $this->reject(fn () => DB::table('matches')->where('id', $data['id'])->delete());
        $this->assertDatabaseCount('match_status_events', 2);
    }

    public function test_schema_round_trip_export_inventory_and_retained_history_rollback_guard(): void
    {
        $migration = require database_path('migrations/2026_10_17_225945_create_matches_participants_and_status_events.php');
        $lineups = require database_path('migrations/2026_10_18_101019_create_match_lineup_revisions.php');
        $matchScores = require database_path('migrations/2026_10_18_135008_create_match_score_rules_and_game_instances.php');
        $matchResults = require database_path('migrations/2026_10_18_144624_create_match_result_events_and_guards.php');
        $notebook = require database_path('migrations/2026_10_18_155140_create_match_notebook_revisions.php');
        $notebook->down();
        $matchResults->down();
        $matchScores->down();
        $lineups->down();
        $migration->down();
        $this->assertFalse(Schema::hasTable('matches'));
        $this->assertFalse(Schema::hasTable('match_participants'));
        $this->assertFalse(Schema::hasTable('match_status_events'));
        $migration->up();
        $lineups->up();
        $matchScores->up();
        $matchResults->up();
        $notebook->up();
        [$org, , , $person, $other, $game] = $this->matchScenario();
        $this->postJson($this->matchRoot($org), $this->matchPayload($game, $person, $other))->assertCreated();
        $repository = app(PortabilityRepository::class);
        $repository->assertCatalog();
        $rows = iterator_to_array($repository->rows($org->id, 100));
        $matches = array_values(array_filter($rows, fn (array $r): bool => $r['table'] === 'matches'));
        $this->assertCount(1, $matches);
        $this->assertNotSame('Scrim de préparation', $matches[0]['data']['title']);
        $this->expectException(\RuntimeException::class);
        $lineups = require database_path('migrations/2026_10_18_101019_create_match_lineup_revisions.php');
        $matchScores = require database_path('migrations/2026_10_18_135008_create_match_score_rules_and_game_instances.php');
        $matchResults = require database_path('migrations/2026_10_18_144624_create_match_result_events_and_guards.php');
        $notebook = require database_path('migrations/2026_10_18_155140_create_match_notebook_revisions.php');
        $notebook->down();
        $matchResults->down();
        $matchScores->down();
        $lineups->down();
        $migration->down();
    }

    private function reject(\Closure $attempt): void
    {
        try {
            DB::transaction(function () use ($attempt): void {
                $attempt();
            });
            $this->fail('SQL must reject this invalid or destructive match write.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
