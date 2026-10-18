<?php

namespace Tests\Feature;

use App\Modules\Competition\Application\Services\MatchCorrectionService;
use App\Modules\Competition\Domain\Models\MatchGameInstance;
use App\Modules\Competition\Infrastructure\Queue\RecalculateMatchScore;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesMatchCorrections;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class MatchCorrectionControllerTest extends TestCase
{
    use AuthenticatesWithMfa,LazilyRefreshDatabase,ProvidesMatchCorrections,ProvidesPeopleAccess;

    public function test_correction_disputes_approval_preserves_originals_and_waits_for_recalculation(): void
    {
        $s = $this->correctionScenario();
        $body = $this->correctionPayload($s);
        $correction = $this->postJson($s['root'].'/corrections', $body)->assertStatus(202)->assertJsonPath('data.state', 'pending')->json('data');
        $this->assertDatabaseHas('matches', ['id' => $s['record']['id'], 'status' => 'disputed']);
        $this->assertDatabaseCount('match_score_corrections', 1);
        $this->assertDatabaseCount('match_score_recalculations', 0);
        $this->assertDatabaseCount('match_game_instances', 1);
        $this->assertDatabaseCount('match_result_events', 3);
        $this->assertStringNotContainsString('rapport', DB::table('match_score_corrections')->value('reason'));
        $this->getJson($s['root'].'/games')->assertOk()->assertJsonPath('scorecard.recalculation_pending', true)->assertJsonPath('scorecard.series', null);
        $this->getJson($s['root'].'/results')->assertOk()->assertJsonPath('result.allowed_actions', []);
        $this->postJson($s['root'].'/corrections', $body)->assertStatus(202)->assertJsonPath('data.id', $correction['id']);
        $this->assertDatabaseCount('match_score_corrections', 1);
        Bus::assertDispatchedTimes(RecalculateMatchScore::class, 1);
        $this->postJson($s['root'].'/corrections', [...$body, 'reason' => 'Autre motif sous la même clé.'])->assertConflict()->assertJsonPath('code', 'MATCH_CORRECTION_KEY_REUSED');
        $this->postJson($s['root'].'/corrections', [...$body, 'idempotency_key' => (string) Str::uuid()])->assertConflict();
    }

    public function test_completed_correction_requires_a_fresh_proof_and_an_independent_approver(): void
    {
        $s = $this->correctionScenario();
        $correction = $this->postJson($s['root'].'/corrections', $this->correctionPayload($s))->assertStatus(202)->json('data');
        $context = $this->personContext($s['org'], $s['writer']);
        app(MatchCorrectionService::class)->process($context, $s['record']['id'], $correction['id']);
        $card = $this->getJson($s['root'].'/games')->assertOk()->assertJsonPath('scorecard.series.winner_ids.0', $s['record']['participants'][1]['id'])->assertJsonPath('data.0.results.0.score', 0)->json('scorecard');
        $this->getJson($s['root'].'/corrections?per_page=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.before.0.results.0.score', 13)->assertJsonPath('data.0.after.0.results.0.score', 0);
        $this->postJson($s['root'].'/results', [...$this->resultPayload($s), 'version' => $card['match_version']])->assertConflict()->assertJsonPath('code', 'MATCH_SCORE_REVISION_STALE');
        $proposal = $this->postJson($s['root'].'/results', [...$this->resultPayload($s), 'version' => $card['match_version'], 'score_revision' => $correction['id']])->assertCreated()->json('data');
        $this->postJson($s['root'].'/results/approvals', $this->decisionPayload($proposal))->assertConflict()->assertJsonPath('code', 'MATCH_RESULT_INDEPENDENT_REQUIRED');
        $this->actingAsWithMfa($s['reader']);
        $this->postJson($s['root'].'/results/approvals', $this->decisionPayload($proposal))->assertOk()->assertJsonPath('data.status', 'validated');
        $this->assertDatabaseCount('match_score_recalculations', 1);
        $this->assertSame(13, MatchGameInstance::query()->firstOrFail()->results[0]['score']);
    }

    public function test_unsubmitted_score_can_be_corrected_and_then_scored_normally(): void
    {
        $s = $this->correctionScenario(false);
        $correction = $this->postJson($s['root'].'/corrections', $this->correctionPayload($s))->assertStatus(202)->json('data');
        $this->assertDatabaseCount('match_result_events', 0);
        $this->assertNotSame($s['record']['version'], $correction['match_version']);
        app(MatchCorrectionService::class)->process($this->personContext($s['org'], $s['writer']), $s['record']['id'], $correction['id']);
        $this->getJson($s['root'].'/games')->assertOk()->assertJsonPath('scorecard.result_locked', false)->assertJsonPath('scorecard.series.winner_ids.0', $s['record']['participants'][1]['id']);
    }

    public function test_a_second_correction_of_a_disputed_match_uses_a_separate_score_revision(): void
    {
        $s = $this->correctionScenario();
        $body = $this->correctionPayload($s);
        $first = $this->postJson($s['root'].'/corrections', $body)->assertStatus(202)->json('data');
        app(MatchCorrectionService::class)->process($this->personContext($s['org'], $s['writer']), $s['record']['id'], $first['id']);
        $next = [...$body, 'version' => $first['match_version'], 'score_revision' => $first['id'], 'idempotency_key' => (string) Str::uuid()];
        $next['games'][0]['results'][1]['score'] = 14;
        $second = $this->postJson($s['root'].'/corrections', $next)->assertStatus(202)->assertJsonPath('data.number', 2)->json('data');
        $this->assertSame($first['match_version'], $second['match_version']);
        $this->postJson($s['root'].'/corrections', [...$next, 'idempotency_key' => (string) Str::uuid()])->assertConflict();
        app(MatchCorrectionService::class)->process($this->personContext($s['org'], $s['writer']), $s['record']['id'], $second['id']);
        $this->postJson($s['root'].'/corrections', [...$next, 'idempotency_key' => (string) Str::uuid()])->assertConflict()->assertJsonPath('code', 'MATCH_SCORE_REVISION_STALE');
    }

    /** @return array<string,array{array<string,mixed>}> */
    public static function invalid(): array
    {
        return ['unknown' => [['tenant_id' => 'forged']], 'short reason' => [['reason' => 'Court']], 'unconfirmed' => [['confirm_correction' => false]],
            'missing games' => [['games' => []]], 'imposed aggregate' => [['series' => []]], 'unknown game' => [['games' => [['game_id' => '11111111-1111-4111-8111-111111111111', 'outcome' => 'played', 'results' => []]]]]];
    }

    /** @param array<string,mixed> $override */
    #[DataProvider('invalid')]
    public function test_invalid_correction_is_refused_without_touching_the_approval(array $override): void
    {
        $s = $this->correctionScenario();
        $this->postJson($s['root'].'/corrections', [...$this->correctionPayload($s), ...$override])->assertUnprocessable();
        $this->assertDatabaseCount('match_score_corrections', 0);
        $this->assertDatabaseHas('matches', ['id' => $s['record']['id'], 'status' => 'validated']);
    }

    public function test_writer_and_validator_do_not_implicitly_have_correction_rights(): void
    {
        $s = $this->correctionScenario();
        $this->actingAsWithMfa($s['reader']);
        $this->postJson($s['root'].'/corrections', $this->correctionPayload($s))->assertForbidden();
        $this->actingAsWithMfa($s['writer']);
        $role = OrganizationRoleAssignment::query()->where('organization_id', $s['org']->id)->where('user_id', $s['writer']->id)->value('role_id');
        OrganizationRole::query()->where('id', $role)->firstOrFail()->forceFill(['permissions' => ['sports.read', 'sports.write']])->save();
        $this->postJson($s['root'].'/corrections', $this->correctionPayload($s))->assertForbidden();
    }

    public function test_async_transport_is_required_before_any_result_is_invalidated(): void
    {
        $s = $this->correctionScenario();
        config(['queue.default' => 'sync']);
        $this->postJson($s['root'].'/corrections', $this->correctionPayload($s))->assertConflict()->assertJsonPath('code', 'MATCH_CORRECTION_ASYNC_REQUIRED');
        $this->assertDatabaseCount('match_score_corrections', 0);
    }

    /** @return array<string,array{string}> */
    public static function invalidGames(): array
    {
        return array_combine(['negative', 'too large', 'null score', 'duplicate participant', 'foreign participant', 'duplicate game', 'unchanged', 'tie', 'draw forbidden', 'incomplete remake', 'nested field'],
            array_map(fn ($case) => [$case], ['negative', 'too large', 'null score', 'duplicate participant', 'foreign participant', 'duplicate game', 'unchanged', 'tie', 'draw forbidden', 'incomplete remake', 'nested field']));
    }

    #[DataProvider('invalidGames')]
    public function test_invalid_game_correction_does_not_invalidate_a_valid_result(string $case): void
    {
        $s = $this->correctionScenario();
        $body = $this->correctionPayload($s);
        switch ($case) {
            case 'negative':$body['games'][0]['results'][0]['score'] = -1;
                break;
            case 'too large':$body['games'][0]['results'][0]['score'] = 1000001;
                break;
            case 'null score':$body['games'][0]['results'][0]['score'] = null;
                break;
            case 'duplicate participant':$body['games'][0]['results'][1]['participant_id'] = $body['games'][0]['results'][0]['participant_id'];
                break;
            case 'foreign participant':$body['games'][0]['results'][0]['participant_id'] = (string) Str::uuid();
                break;
            case 'duplicate game':$body['games'][] = $body['games'][0];
                break;
            case 'unchanged':$body['games'][0]['results'] = array_map(fn ($row) => ['participant_id' => $row['participant_id'], 'score' => $row['score']], $s['game']['results']);
                break;
            case 'tie':$body['games'][0]['results'][1]['score'] = 0;
                break;
            case 'draw forbidden':$body['games'][0]['outcome'] = 'draw';
                $body['games'][0]['results'][1]['score'] = 0;
                break;
            case 'incomplete remake':$body['games'][0]['outcome'] = 'remake';
                $body['games'][0]['results'][0]['score'] = null;
                $body['games'][0]['results'][1]['score'] = null;
                break;
            case 'nested field':$body['games'][0]['results'][0]['label'] = 'imposed';
                break;
        }
        $this->postJson($s['root'].'/corrections', $body)->assertUnprocessable();
        $this->assertDatabaseCount('match_score_corrections', 0);
        $this->assertDatabaseHas('matches', ['id' => $s['record']['id'], 'status' => 'validated', 'version' => $s['record']['version']]);
    }

    public function test_request_audit_failure_rolls_back_correction_and_result_dispute_together(): void
    {
        $s = $this->correctionScenario();
        $journal = app(TenantAuditJournal::class);
        $calls = 0;
        $this->mock(TenantAuditJournal::class)->shouldReceive('record')->twice()->andReturnUsing(function (...$args) use ($journal, &$calls): void {
            if (++$calls === 2) {
                throw new \RuntimeException('correction audit unavailable');
            }
            $journal->record(...$args);
        });
        $this->postJson($s['root'].'/corrections', $this->correctionPayload($s))->assertStatus(500);
        $this->assertDatabaseCount('match_score_corrections', 0);
        $this->assertDatabaseCount('match_result_events', 2);
        $this->assertDatabaseHas('matches', ['id' => $s['record']['id'], 'status' => 'validated', 'version' => $s['record']['version']]);
        Bus::assertNotDispatched(RecalculateMatchScore::class);
    }

    public function test_shared_correction_requires_current_publish_rights_and_confirmation(): void
    {
        Bus::fake();
        config(['queue.default' => 'database']);
        [$org,$writer,,$person,$other,$game] = $this->matchScenario();
        $roleId = OrganizationRoleAssignment::query()->where('organization_id', $org->id)->where('user_id', $writer->id)->value('role_id');
        $role = OrganizationRole::query()->where('id', $roleId)->firstOrFail();
        $permissions = ['sports.read', 'sports.write', 'sports.correct', 'sports.publish'];
        $role->forceFill(['permissions' => $permissions])->save();
        $record = $this->postJson($this->matchRoot($org), [...$this->matchPayload($game, $person, $other), 'format' => 'bo1', 'visibility' => 'organization', 'confirm_sharing' => true])->assertCreated()->json('data');
        $root = $this->matchRoot($org).'/'.$record['id'];
        foreach (['confirmed', 'in_progress'] as $status) {
            $record = $this->postJson($root.'/transitions', ['version' => $record['version'], 'status' => $status, 'reason' => 'Transition du match partagé confirmée.'])->assertOk()->json('data');
        }
        $record['version'] = $this->postJson($root.'/score-rules', ['version' => $record['version'], 'game_limit' => 1, 'allow_draw' => false, 'reason' => 'Règles du match partagées confirmées.', 'confirm_sharing' => true])->assertCreated()->json('data.match_version');
        $scenario = ['org' => $org, 'record' => $record];
        $record['version'] = $this->postJson($root.'/games', [...$this->scorePayload($scenario), 'confirm_sharing' => true])->assertCreated()->json('data.match_version');
        $scenario['record'] = $record;
        $scenario['game'] = $this->getJson($root.'/games')->assertOk()->json('data.0');
        $body = $this->correctionPayload($scenario);
        $this->postJson($root.'/corrections', $body)->assertUnprocessable()->assertJsonValidationErrors('confirm_sharing');
        $role->forceFill(['permissions' => ['sports.read', 'sports.write', 'sports.correct']])->save();
        $this->postJson($root.'/corrections', [...$body, 'confirm_sharing' => true])->assertForbidden();
        $role->forceFill(['permissions' => $permissions])->save();
        $accepted = $this->postJson($root.'/corrections', [...$body, 'confirm_sharing' => true])->assertStatus(202)->json('data');
        $role->forceFill(['permissions' => ['sports.read', 'sports.write', 'sports.correct']])->save();
        $this->postJson($root.'/corrections/'.$accepted['id'].'/retries')->assertForbidden();
    }

    public function test_points_aggregate_for_three_participants_uses_the_corrected_game_once(): void
    {
        Bus::fake();
        config(['queue.default' => 'database']);
        $s = $this->scoreScenario('lobby', true, false, 2, 3);
        $roleId = OrganizationRoleAssignment::query()->where('organization_id', $s['org']->id)->where('user_id', $s['writer']->id)->value('role_id');
        OrganizationRole::query()->where('id', $roleId)->firstOrFail()->forceFill(['permissions' => ['sports.read', 'sports.write', 'sports.correct']])->save();
        foreach ([[10, 5, 2], [2, 8, 3]] as $values) {
            $results = [];
            foreach ($s['record']['participants'] as $i => $participant) {
                $results[] = ['participant_id' => $participant['id'], 'score' => $values[$i]];
            }
            $s['record']['version'] = $this->postJson($s['root'].'/games', [...$this->scorePayload($s), 'results' => $results])->assertCreated()->json('data.match_version');
        }
        $games = $this->getJson($s['root'].'/games')->assertOk()->json('data');
        $s['game'] = $games[1];
        $body = $this->correctionPayload($s);
        $body['games'][0]['results'] = array_map(fn ($r) => ['participant_id' => $r['participant_id'], 'score' => $r['score']], $s['game']['results']);
        $body['games'][0]['results'][0]['score'] = 20;
        $correction = $this->postJson($s['root'].'/corrections', $body)->assertStatus(202)->json('data');
        app(MatchCorrectionService::class)->process($this->personContext($s['org'], $s['writer']), $s['record']['id'], $correction['id']);
        $this->getJson($s['root'].'/games')->assertOk()->assertJsonPath('scorecard.series.standings.0.score', 22)->assertJsonPath('scorecard.series.winner_ids.0', $s['record']['participants'][0]['id']);
        $this->assertDatabaseCount('match_game_instances', 2);
    }

    public function test_best_of_rejects_an_early_finish_with_later_games_and_recalculates_a_coherent_change(): void
    {
        Bus::fake();
        config(['queue.default' => 'database']);
        $s = $this->scoreScenario('bo3');
        $roleId = OrganizationRoleAssignment::query()->where('organization_id', $s['org']->id)->where('user_id', $s['writer']->id)->value('role_id');
        OrganizationRole::query()->where('id', $roleId)->firstOrFail()->forceFill(['permissions' => ['sports.read', 'sports.write', 'sports.correct']])->save();
        foreach ([[13, 7], [7, 13], [13, 7]] as [$a,$b]) {
            $s['record']['version'] = $this->postJson($s['root'].'/games', $this->scorePayload($s, 'played', $a, $b))->assertCreated()->json('data.match_version');
        }
        $games = $this->getJson($s['root'].'/games')->assertOk()->json('data');
        $s['game'] = $games[1];
        $bad = $this->correctionPayload($s);
        $bad['games'][0]['results'][0]['score'] = 13;
        $bad['games'][0]['results'][1]['score'] = 7;
        $this->postJson($s['root'].'/corrections', $bad)->assertUnprocessable();
        $s['game'] = $games[0];
        $good = $this->correctionPayload($s);
        $correction = $this->postJson($s['root'].'/corrections', $good)->assertStatus(202)->json('data');
        app(MatchCorrectionService::class)->process($this->personContext($s['org'], $s['writer']), $s['record']['id'], $correction['id']);
        $this->getJson($s['root'].'/games')->assertOk()->assertJsonPath('scorecard.series.played', 3)->assertJsonPath('scorecard.series.winner_ids.0', $s['record']['participants'][1]['id']);
    }

    public function test_correction_cannot_reclassify_a_counted_game_as_remake_before_series_completion(): void
    {
        Bus::fake();
        config(['queue.default' => 'database']);
        $s = $this->scoreScenario('bo3');
        $roleId = OrganizationRoleAssignment::query()->where('organization_id', $s['org']->id)->where('user_id', $s['writer']->id)->value('role_id');
        OrganizationRole::query()->where('id', $roleId)->firstOrFail()->forceFill(['permissions' => ['sports.read', 'sports.write', 'sports.correct']])->save();
        $s['record']['version'] = $this->postJson($s['root'].'/games', $this->scorePayload($s))->assertCreated()->json('data.match_version');
        $s['game'] = $this->getJson($s['root'].'/games')->assertOk()->json('data.0');
        $body = $this->correctionPayload($s);
        $body['games'][0]['outcome'] = 'remake';
        $body['games'][0]['results'][0]['score'] = null;
        $body['games'][0]['results'][1]['score'] = null;
        $this->postJson($s['root'].'/corrections', $body)->assertUnprocessable();
        $this->assertDatabaseCount('match_score_corrections', 0);
    }
}
