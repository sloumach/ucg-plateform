<?php

namespace Tests\Feature;

use App\Exceptions\DomainConflictException;
use App\Modules\People\Domain\Models\GameProfile;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Teams\Application\Services\LineupEligibilityService;
use App\Modules\Teams\Application\Services\TeamEligibilityRulesService;
use App\Modules\Teams\Application\Services\TeamLineupService;
use App\Modules\Teams\Domain\Models\TeamEligibilityRuleset;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\Fixtures\ProvidesLineups;
use Tests\TestCase;

class TeamEligibilityServiceTest extends TestCase
{
    use LazilyRefreshDatabase, ProvidesLineups;

    public function test_expired_binding_refuses_edit_and_finalize_but_allows_cancellation(): void
    {
        [$org,$user,$team,$period] = $this->lineupEditor();
        $ctx = $this->personContext($org, $user);
        $rule = TeamEligibilityRuleset::factory()->create(['organization_id' => $org->id, 'lock_at' => '2100-01-01 00:00:00']);
        $service = app(TeamLineupService::class);
        $draft = $service->create($ctx, $team->id, [...$this->lineupPayload($team, $period), 'ruleset_id' => $rule->id, 'ruleset_version' => $rule->version]);
        $this->travelTo(CarbonImmutable::parse('2100-01-01T00:00:00Z'));
        $report = app(LineupEligibilityService::class)->preview($ctx, $team->id, $draft->id, ['version' => $draft->version]);
        $this->assertSame('ineligible', $report->status);
        try {
            $service->change($ctx, $team->id, $draft->id, ['action' => 'finalize', 'version' => $draft->version, 'team_version' => $team->version]);
            $this->fail('Deadline must fail.');
        } catch (DomainConflictException $e) {
            $this->assertSame('TEAM_LINEUP_LOCKED', $e->errorCode());
        }
        $this->assertSame('cancelled', $service->change($ctx, $team->id, $draft->id, ['action' => 'cancel', 'version' => $draft->version])->status);
        $this->assertDatabaseCount('team_lineup_entries', 1);
    }

    public function test_report_limit_reserves_one_final_proof(): void
    {
        [$org,$user,$team,$period] = $this->lineupEditor();
        $ctx = $this->personContext($org, $user);
        $rule = TeamEligibilityRuleset::factory()->create(['organization_id' => $org->id]);
        $lineups = app(TeamLineupService::class);
        $service = app(LineupEligibilityService::class);
        $draft = $lineups->create($ctx, $team->id, [...$this->lineupPayload($team, $period), 'ruleset_id' => $rule->id, 'ruleset_version' => $rule->version]);
        $report = $service->preview($ctx, $team->id, $draft->id, ['version' => $draft->version]);
        $raw = (array) DB::table('team_lineup_eligibility_reports')->where('id', $report->id)->first();
        for ($i = 0; $i < 98; $i++) {
            DB::table('team_lineup_eligibility_reports')->insert([...$raw, 'id' => (string) Str::uuid()]);
        }
        try {
            $service->preview($ctx, $team->id, $draft->id, ['version' => $draft->version]);
            $this->fail('Preview limit must fail.');
        } catch (DomainConflictException $e) {
            $this->assertSame('TEAM_ELIGIBILITY_LIMIT', $e->errorCode());
        }
        $final = $lineups->change($ctx, $team->id, $draft->id, ['action' => 'finalize', 'version' => $draft->version, 'team_version' => $team->version]);
        $this->assertSame('eligible', $final->finalEligibilityReport?->status);
        $this->assertDatabaseCount('team_lineup_eligibility_reports', 100);
    }

    public function test_archiving_a_ruleset_blocks_freezing_without_rewriting_preview(): void
    {
        [$org,$user,$team,$period] = $this->lineupEditor();
        $ctx = $this->personContext($org, $user);
        $rule = TeamEligibilityRuleset::factory()->create(['organization_id' => $org->id]);
        $lineups = app(TeamLineupService::class);
        $draft = $lineups->create($ctx, $team->id, [...$this->lineupPayload($team, $period), 'ruleset_id' => $rule->id, 'ruleset_version' => $rule->version]);
        $report = app(LineupEligibilityService::class)->preview($ctx, $team->id, $draft->id, ['version' => $draft->version]);
        app(TeamEligibilityRulesService::class)->archive($ctx, $rule->id, ['action' => 'archive', 'version' => $rule->version]);
        try {
            $lineups->change($ctx, $team->id, $draft->id, ['action' => 'finalize', 'version' => $draft->version, 'team_version' => $team->version]);
            $this->fail('Archived rules must fail.');
        } catch (DomainConflictException $e) {
            $this->assertSame('TEAM_RULESET_UNAVAILABLE', $e->errorCode());
        }
        $this->assertDatabaseHas('team_lineup_eligibility_reports', ['id' => $report->id, 'status' => 'eligible']);
        $this->assertDatabaseHas('team_lineups', ['id' => $draft->id, 'status' => 'draft', 'version' => $draft->version]);
    }

    public function test_bound_finalization_reevaluates_and_retains_private_proof_after_data_changes(): void
    {
        [$org,$user,$team,$period] = $this->lineupEditor(['sports.read', 'teams.manage', 'administrative.read']);
        $ctx = $this->personContext($org, $user);
        $person = Person::findOrFail($period->person_id);
        $person->forceFill(['birth_date' => '2008-10-13'])->save();
        $profile = GameProfile::factory()->create(['person_id' => $person->id, 'rank' => 'Diamond', 'observed_at' => '2026-10-12 12:00:00']);
        $rule = TeamEligibilityRuleset::factory()->create(['organization_id' => $org->id, 'platform_key' => 'pc', 'min_age' => 18, 'allowed_regions' => ['eu'], 'allowed_ranks' => ['diamond']]);
        $lineups = app(TeamLineupService::class);
        $eligibility = app(LineupEligibilityService::class);
        $draft = $lineups->create($ctx, $team->id, [...$this->lineupPayload($team, $period), 'ruleset_id' => $rule->id, 'ruleset_version' => $rule->version]);
        $preview = $eligibility->preview($ctx, $team->id, $draft->id, ['version' => $draft->version]);
        $this->assertSame('eligible', $preview->status);
        $final = $lineups->change($ctx, $team->id, $draft->id, ['action' => 'finalize', 'version' => $draft->version, 'team_version' => $team->version]);
        $proof = $final->finalEligibilityReport;
        $this->assertNotNull($proof);
        $this->assertSame('eligible', $proof->status);
        $this->assertSame($final->version, $proof->lineup_version);
        $this->assertNotSame($preview->id, $proof->id);
        $saved = $proof->toArray();
        $person->forceFill(['birth_date' => '2020-01-01'])->save();
        $profile->forceFill(['rank' => 'Bronze'])->save();
        app(TeamEligibilityRulesService::class)->archive($ctx, $rule->id, ['action' => 'archive', 'version' => $rule->version]);
        $retained = $proof->fresh();
        $this->assertNotNull($retained);
        $this->assertSame($saved, $retained->toArray());
        $raw = json_encode(DB::table('team_lineup_eligibility_reports')->get(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('2008-10-13', $raw);
        $this->assertStringNotContainsString('birth_date', $raw);
        $this->assertDatabaseCount('team_lineup_eligibility_reports', 2);
    }

    public function test_missing_profile_is_incomplete_and_cannot_freeze_or_refresh_entries(): void
    {
        [$org,$user,$team,$period] = $this->lineupEditor();
        $ctx = $this->personContext($org, $user);
        $rule = TeamEligibilityRuleset::factory()->create(['organization_id' => $org->id, 'platform_key' => 'pc']);
        $service = app(TeamLineupService::class);
        $draft = $service->create($ctx, $team->id, [...$this->lineupPayload($team, $period), 'ruleset_id' => $rule->id, 'ruleset_version' => $rule->version]);
        $entry = $draft->entries->sole();
        $report = app(LineupEligibilityService::class)->preview($ctx, $team->id, $draft->id, ['version' => $draft->version]);
        $this->assertSame('incomplete', $report->status);
        try {
            $service->change($ctx, $team->id, $draft->id, ['action' => 'finalize', 'version' => $draft->version, 'team_version' => $team->version]);
            $this->fail('Missing data must fail closed.');
        } catch (DomainConflictException $e) {
            $this->assertSame('TEAM_LINEUP_ELIGIBILITY_INCOMPLETE', $e->errorCode());
        }
        $this->assertModelExists($entry);
        $this->assertDatabaseHas('team_lineups', ['id' => $draft->id, 'status' => 'draft', 'version' => $draft->version]);
        $this->assertDatabaseCount('team_lineup_eligibility_reports', 1);
    }

    public function test_preview_does_not_authorize_a_later_changed_profile(): void
    {
        [$org,$user,$team,$period] = $this->lineupEditor();
        $ctx = $this->personContext($org, $user);
        $profile = GameProfile::factory()->create(['person_id' => $period->person_id, 'rank' => 'Diamond', 'observed_at' => '2026-10-12 12:00:00']);
        $rule = TeamEligibilityRuleset::factory()->create(['organization_id' => $org->id, 'platform_key' => 'pc', 'allowed_ranks' => ['Diamond']]);
        $service = app(TeamLineupService::class);
        $draft = $service->create($ctx, $team->id, [...$this->lineupPayload($team, $period), 'ruleset_id' => $rule->id, 'ruleset_version' => $rule->version]);
        $this->assertSame('eligible', app(LineupEligibilityService::class)->preview($ctx, $team->id, $draft->id, ['version' => $draft->version])->status);
        $profile->forceFill(['rank' => 'Bronze'])->save();
        $this->expectException(DomainConflictException::class);
        $service->change($ctx, $team->id, $draft->id, ['action' => 'finalize', 'version' => $draft->version, 'team_version' => $team->version]);
    }

    public function test_binding_updates_version_and_cannot_be_replaced(): void
    {
        [$org,$user,$team,$period] = $this->lineupEditor();
        $ctx = $this->personContext($org, $user);
        $draft = app(TeamLineupService::class)->create($ctx, $team->id, $this->lineupPayload($team, $period));
        $rule = TeamEligibilityRuleset::factory()->create(['organization_id' => $org->id]);
        $service = app(LineupEligibilityService::class);
        $bound = $service->bind($ctx, $team->id, $draft->id, ['version' => $draft->version, 'ruleset_id' => $rule->id, 'ruleset_version' => $rule->version]);
        $this->assertNotSame($draft->version, $bound->version);
        $this->assertSame($rule->id, $bound->ruleBinding?->ruleset_id);
        $this->expectException(DomainConflictException::class);
        $service->bind($ctx, $team->id, $draft->id, ['version' => $bound->version, 'ruleset_id' => $rule->id, 'ruleset_version' => $rule->version]);
    }

    public function test_age_without_administrative_permission_does_not_read_private_column(): void
    {
        [$org,$user,$team,$period] = $this->lineupEditor();
        $ctx = $this->personContext($org, $user);
        Person::findOrFail($period->person_id)->forceFill(['birth_date' => '2000-01-01'])->save();
        $rule = TeamEligibilityRuleset::factory()->create(['organization_id' => $org->id, 'min_age' => 18]);
        $draft = app(TeamLineupService::class)->create($ctx, $team->id, [...$this->lineupPayload($team, $period), 'ruleset_id' => $rule->id, 'ruleset_version' => $rule->version]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $report = app(LineupEligibilityService::class)->preview($ctx, $team->id, $draft->id, ['version' => $draft->version]);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame('incomplete', $report->status);
        $this->assertStringNotContainsString('birth_date', implode(' ', array_column($queries, 'query')));
    }

    public function test_final_report_and_snapshot_roll_back_when_journal_fails(): void
    {
        [$org,$user,$team,$period] = $this->lineupEditor();
        $ctx = $this->personContext($org, $user);
        $rule = TeamEligibilityRuleset::factory()->create(['organization_id' => $org->id]);
        $draft = app(TeamLineupService::class)->create($ctx, $team->id, [...$this->lineupPayload($team, $period), 'ruleset_id' => $rule->id, 'ruleset_version' => $rule->version]);
        $audit = Mockery::mock(TenantAuditJournal::class);
        $audit->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $this->app->instance(TenantAuditJournal::class, $audit);
        try {
            app(TeamLineupService::class)->change($ctx, $team->id, $draft->id, ['action' => 'finalize', 'version' => $draft->version, 'team_version' => $team->version]);
            $this->fail('Audit failure expected.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit unavailable', $e->getMessage());
        }
        $this->assertDatabaseCount('team_lineup_eligibility_reports', 0);
        $this->assertDatabaseHas('team_lineups', ['id' => $draft->id, 'status' => 'draft', 'version' => $draft->version]);
    }
}
