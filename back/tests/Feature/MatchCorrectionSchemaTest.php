<?php

namespace Tests\Feature;

use App\Architecture\Database\TenantSchemaInspector;
use App\Modules\Competition\Application\Services\MatchCorrectionService;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesMatchCorrections;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class MatchCorrectionSchemaTest extends TestCase
{
    use AuthenticatesWithMfa,LazilyRefreshDatabase,ProvidesMatchCorrections,ProvidesPeopleAccess;

    public function test_empty_roundtrip_preserves_existing_guards_and_tenant_inventory(): void
    {
        $migration = require database_path('migrations/2026_10_18_184341_create_match_score_corrections_and_recalculations.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('match_score_corrections'));
        $migration->up();
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        app(PortabilityRepository::class)->assertCatalog();
    }

    public function test_sql_retains_audit_and_refuses_unscoped_or_duplicate_completions(): void
    {
        $s = $this->correctionScenario();
        $correction = $this->postJson($s['root'].'/corrections', $this->correctionPayload($s))->assertStatus(202)->json('data');
        $row = (array) DB::table('match_score_corrections')->first();
        $this->reject(fn () => DB::table('match_score_corrections')->update(['reason' => 'overwrite']));
        $this->reject(fn () => DB::table('match_score_corrections')->delete());
        $this->reject(fn () => DB::table('match_score_corrections')->insert([...$row, 'id' => (string) Str::uuid(), 'number' => 2, 'base_correction_id' => $row['id'], 'idempotency_key' => (string) Str::uuid()]));
        $this->reject(fn () => DB::table('matches')->where('id', $s['record']['id'])->update(['version' => (string) Str::uuid()]));
        app(MatchCorrectionService::class)->process($this->personContext($s['org'], $s['writer']), $s['record']['id'], $correction['id']);
        $done = (array) DB::table('match_score_recalculations')->first();
        $this->reject(fn () => DB::table('match_score_recalculations')->update(['series' => 'overwrite']));
        $this->reject(fn () => DB::table('match_score_recalculations')->delete());
        $this->reject(fn () => DB::table('match_score_recalculations')->insert([...$done, 'id' => (string) Str::uuid()]));
        $this->reject(fn () => DB::table('match_score_recalculations')->insert([...$done, 'id' => (string) Str::uuid(), 'organization_id' => (string) Str::uuid()]));
        $rows = iterator_to_array(app(PortabilityRepository::class)->rows($s['org']->id, 100));
        $export = array_values(array_filter($rows, fn ($r) => $r['table'] === 'match_score_corrections'));
        $this->assertCount(1, $export);
        $this->assertSame($row['before'], $export[0]['data']['before']);
        $this->expectException(\RuntimeException::class);
        (require database_path('migrations/2026_10_18_184341_create_match_score_corrections_and_recalculations.php'))->down();
    }

    private function reject(\Closure $operation): void
    {
        try {
            DB::transaction(fn () => $operation());
            $this->fail('SQL must reject invalid correction mutation.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
