<?php

namespace Tests\Feature;

use App\Modules\Teams\Domain\Models\TeamEligibilityRuleset;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesLineups;
use Tests\TestCase;

class TeamEligibilityControllerTest extends TestCase
{
    use AuthenticatesWithMfa,LazilyRefreshDatabase,ProvidesLineups;

    public function test_deadline_with_timezone_is_persisted_as_utc(): void
    {
        [$org,$user,$team] = $this->lineupEditor();
        $this->actingAsWithMfa($user);
        $input = ['name' => 'Coupe UTC', 'game_id' => $team->game_id, 'min_starters' => 1, 'max_starters' => 5, 'max_substitutes' => 2, 'platform_key' => null,
            'allowed_regions' => [], 'allowed_ranks' => [], 'min_age' => null, 'max_age' => null, 'observation_max_days' => 30, 'lock_at' => '2100-01-01T12:00:00+02:00'];
        $this->postJson('/api/v1/tenants/'.$org->id.'/eligibility-rulesets', $input)->assertCreated()->assertJsonPath('data.lock_at', '2100-01-01T10:00:00+00:00');
        $this->assertDatabaseHas('team_eligibility_rulesets', ['name' => 'Coupe UTC', 'lock_at' => '2100-01-01 10:00:00']);
    }

    /** @return array<string,array{array<string,mixed>,string}> */
    public static function invalidRules(): array
    {
        return ['blank name' => [['name' => ' '], 'name'], 'minimum' => [['min_starters' => 0], 'min_starters'],
            'starter range' => [['min_starters' => 6, 'max_starters' => 5], 'max_starters'],
            'total' => [['max_starters' => 50, 'max_substitutes' => 1], 'max_starters'],
            'age range' => [['min_age' => 21, 'max_age' => 18], 'max_age'],
            'age cap' => [['max_age' => 121], 'max_age'], 'observation zero' => [['observation_max_days' => 0], 'observation_max_days'],
            'undated timezone' => [['lock_at' => '2026-10-14T12:00:00'], 'lock_at'],
            'regions need platform' => [['allowed_regions' => ['eu']], 'platform_key'],
            'ranks need platform' => [['allowed_ranks' => ['Diamond']], 'platform_key'],
            'rank blank' => [['platform_key' => 'pc', 'allowed_ranks' => [' ']], 'allowed_ranks.0'],
            'rank duplicate' => [['platform_key' => 'pc', 'allowed_ranks' => ['Diamond', 'diamond']], 'allowed_ranks.0'],
            'regions list' => [['allowed_regions' => ['named' => 'eu']], 'allowed_regions'],
            'rank maximum' => [['allowed_ranks' => array_fill(0, 33, 'Diamond')], 'allowed_ranks'],
            'server version' => [['version' => 'fake'], 'version']];
    }

    /** @param array<string,mixed> $changes */
    #[DataProvider('invalidRules')]
    public function test_invalid_rules_are_localized_and_atomic(array $changes, string $field): void
    {
        [$org,$user,$team] = $this->lineupEditor();
        $this->actingAsWithMfa($user);
        $input = ['name' => 'Coupe', 'game_id' => $team->game_id, 'min_starters' => 1, 'max_starters' => 5, 'max_substitutes' => 2, 'platform_key' => null,
            'allowed_regions' => [], 'allowed_ranks' => [], 'min_age' => null, 'max_age' => null, 'observation_max_days' => 30, 'lock_at' => null];
        $this->postJson('/api/v1/tenants/'.$org->id.'/eligibility-rulesets', [...$input, ...$changes])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('team_eligibility_rulesets', 0);
        $this->assertDatabaseCount('organization_audit_events', 0);
    }

    public function test_confirmed_rules_binding_evaluation_and_report_api(): void
    {
        [$org,$user,$team,$period] = $this->lineupEditor();
        $this->actingAsWithMfa($user);
        $path = '/api/v1/tenants/'.$org->id.'/eligibility-rulesets';
        $input = ['name' => 'Coupe pilote', 'game_id' => $team->game_id, 'min_starters' => 1, 'max_starters' => 5, 'max_substitutes' => 2, 'platform_key' => null,
            'allowed_regions' => [], 'allowed_ranks' => [], 'min_age' => null, 'max_age' => null, 'observation_max_days' => 30, 'lock_at' => null];
        $rules = $this->postJson($path, $input)->assertCreated()->assertJsonPath('notification.type', 'success')->assertJsonMissingPath('data.organization_id');
        $this->getJson($path.'?game_id='.$team->game_id)->assertOk()->assertJsonCount(1, 'data');
        $draft = $this->postJson($this->lineupPath($team), [...$this->lineupPayload($team, $period), 'ruleset_id' => $rules->json('data.id'), 'ruleset_version' => $rules->json('data.version')])->assertCreated();
        $lineupPath = $this->lineupPath($team).'/'.$draft->json('data.id');
        $this->postJson($lineupPath.'/eligibility', ['version' => $draft->json('data.version')])->assertCreated()->assertJsonPath('data.status', 'eligible')->assertJsonMissingPath('data.birth_date');
        $this->getJson($lineupPath.'/eligibility?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.checks.0.message', __('team_eligibility.checks.rules_active'));
        $final = $this->patchJson($lineupPath, ['version' => $draft->json('data.version'), 'team_version' => $team->version, 'action' => 'finalize'])->assertOk()->assertJsonPath('data.eligibility_status', 'eligible');
        $this->assertNotNull($final->json('data.eligibility_report_id'));
        $this->patchJson($path.'/'.$rules->json('data.id'), ['version' => $rules->json('data.version'), 'action' => 'archive'])->assertOk()->assertJsonPath('data.status', 'archived');
        $this->getJson($lineupPath)->assertOk()->assertJsonPath('data.eligibility_status', 'eligible');
    }

    public function test_reader_foreign_records_and_untrusted_report_fields_are_refused(): void
    {
        [$org,$user,$team,$period] = $this->lineupEditor(['sports.read']);
        $this->actingAsWithMfa($user);
        $this->postJson('/api/v1/tenants/'.$org->id.'/eligibility-rulesets', [])->assertForbidden();
        $this->getJson('/api/v1/tenants/'.$org->id.'/eligibility-rulesets')->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('team_eligibility_rulesets', 0);
    }

    public function test_invalid_range_unknown_fields_and_foreign_binding_never_write(): void
    {
        [$org,$user,$team,$period] = $this->lineupEditor();
        $this->actingAsWithMfa($user);
        $path = '/api/v1/tenants/'.$org->id.'/eligibility-rulesets';
        $this->postJson($path, ['organization_id' => $org->id])->assertUnprocessable()->assertJsonValidationErrors('organization_id');
        $draft = $this->postJson($this->lineupPath($team), $this->lineupPayload($team, $period))->assertCreated();
        $foreign = TeamEligibilityRuleset::factory()->create();
        $this->postJson($this->lineupPath($team).'/'.$draft->json('data.id').'/ruleset-binding', ['version' => $draft->json('data.version'), 'ruleset_id' => $foreign->id, 'ruleset_version' => $foreign->version])->assertNotFound();
        $this->assertDatabaseCount('team_lineup_rule_bindings', 0);
        $this->postJson($this->lineupPath($team).'/'.$draft->json('data.id').'/eligibility', ['version' => $draft->json('data.version'), 'status' => 'eligible'])->assertUnprocessable()->assertJsonValidationErrors('status');
    }
}
