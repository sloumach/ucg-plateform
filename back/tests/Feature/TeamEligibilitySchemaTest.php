<?php

namespace Tests\Feature;

use App\Modules\Teams\Application\Services\LineupEligibilityService;
use App\Modules\Teams\Application\Services\TeamLineupService;
use App\Modules\Teams\Domain\Models\TeamEligibilityRuleset;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Fixtures\ProvidesLineups;
use Tests\TestCase;

class TeamEligibilitySchemaTest extends TestCase
{
    use LazilyRefreshDatabase,ProvidesLineups;

    public function test_empty_roundtrip_and_export_inventory(): void
    {
        $migration = require database_path('migrations/2026_10_13_215251_create_team_eligibility_rules_and_reports.php');
        $migration->down();
        $migration->up();
        app(PortabilityRepository::class)->assertCatalog();
        $this->assertDatabaseCount('team_eligibility_rulesets', 0);
    }

    public function test_sql_requires_final_proof_and_prevents_history_rewrites(): void
    {
        [$org,$user,$team,$period] = $this->lineupEditor();
        $ctx = $this->personContext($org, $user);
        $rule = TeamEligibilityRuleset::factory()->create(['organization_id' => $org->id]);
        $draft = app(TeamLineupService::class)->create($ctx, $team->id, [...$this->lineupPayload($team, $period), 'ruleset_id' => $rule->id, 'ruleset_version' => $rule->version]);
        $this->reject(fn () => DB::table('team_lineups')->where('id', $draft->id)->update(['status' => 'finalized', 'version' => (string) Str::uuid(), 'finalized_at' => now(), 'finalized_by' => $user->id]));
        $this->reject(fn () => DB::table('team_eligibility_rulesets')->where('id', $rule->id)->update(['max_starters' => 4]));
        $this->reject(fn () => DB::table('team_eligibility_rulesets')->where('id', $rule->id)->delete());
        $this->reject(fn () => DB::table('team_lineup_rule_bindings')->where('lineup_id', $draft->id)->update(['ruleset_version' => (string) Str::uuid()]));
        $this->reject(fn () => DB::table('team_lineup_rule_bindings')->where('lineup_id', $draft->id)->delete());
        $report = app(LineupEligibilityService::class)->preview($ctx, $team->id, $draft->id, ['version' => $draft->version]);
        $this->reject(fn () => DB::table('team_lineup_eligibility_reports')->where('id', $report->id)->update(['status' => 'ineligible']));
        $this->reject(fn () => DB::table('team_lineup_eligibility_reports')->where('id', $report->id)->delete());
    }

    public function test_populated_rollback_refuses_before_any_ddl(): void
    {
        TeamEligibilityRuleset::factory()->create();
        $migration = require database_path('migrations/2026_10_13_215251_create_team_eligibility_rules_and_reports.php');
        try {
            $migration->down();
            $this->fail('Populated rollback must fail.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cannot discard', $e->getMessage());
        }
        $this->assertDatabaseCount('team_eligibility_rulesets', 1);
        app(PortabilityRepository::class)->assertCatalog();
    }

    /** @param \Closure():mixed $operation */
    private function reject(\Closure $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('SQL mutation must fail.');
        } catch (QueryException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }
}
