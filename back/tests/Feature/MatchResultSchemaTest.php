<?php

namespace Tests\Feature;

use App\Architecture\Database\TenantSchemaInspector;
use App\Modules\Competition\Domain\Contracts\MatchScoreRepository;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesMatchResults;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class MatchResultSchemaTest extends TestCase
{
    use AuthenticatesWithMfa,LazilyRefreshDatabase,ProvidesMatchResults,ProvidesPeopleAccess;

    public function test_empty_migration_roundtrip_restores_parent_guards_and_inventory(): void
    {
        $migration = require database_path('migrations/2026_10_18_144624_create_match_result_events_and_guards.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('match_result_events'));
        $migration->up();
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        app(PortabilityRepository::class)->assertCatalog();
    }

    public function test_sql_refuses_unbacked_validation_and_immutable_result_rewrites(): void
    {
        $s = $this->resultScenario(false);
        $this->reject(fn () => DB::table('matches')->where('id', $s['record']['id'])->update(['status' => 'forfeited', 'version' => (string) Str::uuid()]));
        $result = $this->postJson($s['root'].'/results', $this->resultPayload($s, 'forfeit'))->assertCreated()->json('data');
        $this->reject(fn () => DB::table('matches')->where('id', $s['record']['id'])->update(['status' => 'validated', 'version' => (string) Str::uuid()]));
        $this->reject(fn () => DB::table('matches')->where('id', $s['record']['id'])->update(['version' => (string) Str::uuid()]));
        $this->reject(fn () => DB::table('match_result_events')->update(['reason' => 'overwrite']));
        $this->reject(fn () => DB::table('match_result_events')->delete());
        $row = (array) DB::table('match_result_events')->first();
        $this->reject(fn () => DB::table('match_result_events')->insert([...$row, 'id' => (string) Str::uuid(), 'number' => 3]));
        // A first valid game would otherwise fit this still-incomplete BO1.
        $this->reject(fn () => app(MatchScoreRepository::class)->append([
            'organization_id' => $s['org']->id, 'match_id' => $s['record']['id'], 'match_version' => $result['match_version'],
            'number' => 1, 'outcome' => 'played', 'map' => 'Carte pilote', 'mode' => null, 'game_patch' => '1.0',
            'results' => $this->scorePayload($s)['results'], 'reason' => 'Partie valide mais gelée par le résultat.', 'actor_user_id' => $s['writer']->id,
        ]));
        $export = iterator_to_array(app(PortabilityRepository::class)->rows($s['org']->id, 100));
        $rows = array_values(array_filter($export, fn ($r): bool => $r['table'] === 'match_result_events'));
        $this->assertCount(1, $rows);
        $this->assertSame($row['proof_reference'], $rows[0]['data']['proof_reference']);
        $this->expectException(\RuntimeException::class);
        (require database_path('migrations/2026_10_18_144624_create_match_result_events_and_guards.php'))->down();
    }

    public function test_sql_refuses_self_approval_and_a_foreign_or_old_proposal(): void
    {
        $s = $this->resultScenario();
        $this->postJson($s['root'].'/results', $this->resultPayload($s))->assertCreated();
        $row = (array) DB::table('match_result_events')->first();
        $approval = [...$row, 'id' => (string) Str::uuid(), 'number' => 2, 'base_version' => $row['match_version'], 'match_version' => (string) Str::uuid(),
            'action' => 'approve', 'from_status' => 'awaiting_validation', 'to_status' => 'validated', 'submission_id' => $row['id'], 'proof_reference' => null, 'snapshot' => null, 'contributors' => null];
        $this->reject(fn () => DB::table('match_result_events')->insert($approval));
        $this->reject(fn () => DB::table('match_result_events')->insert([...$approval, 'actor_user_id' => $s['reader']->id, 'submission_id' => (string) Str::uuid()]));
        $this->assertDatabaseCount('match_result_events', 1);
    }

    private function reject(\Closure $operation): void
    {
        try {
            DB::transaction(fn () => $operation());
            $this->fail('SQL must reject invalid result changes.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
