<?php

namespace Tests\Feature;

use App\Modules\Competition\Application\Services\MatchScoreService;
use App\Modules\Identity\Application\Services\MfaSession;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesMatchScores;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class MatchScoreControllerTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesMatchScores, ProvidesPeopleAccess;

    public function test_bo3_server_calculation_rejects_extra_games_and_preserves_immutable_encrypted_facts(): void
    {
        $s = $this->scoreScenario();
        $body = $this->scorePayload($s);
        $first = $this->postJson($s['root'].'/games', $body)->assertCreated()->assertJsonPath('data.series.complete', false)
            ->assertJsonPath('data.series.standings.0.score', 1)->json('data');
        $this->assertNotSame($body['version'], $first['match_version']);
        $this->postJson($s['root'].'/games', $body)->assertConflict()->assertJsonPath('code', 'MATCH_VERSION_STALE');
        $second = $this->postJson($s['root'].'/games', [...$body, 'version' => $first['match_version'], 'outcome' => 'forfeit'])
            ->assertCreated()->assertJsonPath('data.series.complete', true)->assertJsonPath('data.series.played', 2)
            ->assertJsonPath('data.series.standings.0.score', 2)->assertJsonPath('data.series.winner_ids.0', $body['results'][0]['participant_id'])->json('data');
        $this->postJson($s['root'].'/games', [...$body, 'version' => $second['match_version']])->assertConflict()->assertJsonPath('code', 'MATCH_SERIES_COMPLETE');
        $this->assertDatabaseCount('match_game_instances', 2);
        $this->assertDatabaseCount('organization_audit_events', 6);
        $this->assertDatabaseHas('matches', ['id' => $s['record']['id'], 'status' => 'in_progress', 'version' => $second['match_version']]);
        $this->assertStringNotContainsString('Carte pilote', DB::table('match_game_instances')->value('map'));
        $this->assertStringNotContainsString('participant_id', DB::table('match_game_instances')->value('results'));
        $this->actingAsWithMfa($s['reader']);
        $page = $this->getJson($s['root'].'/games?per_page=1')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.number', 2)
            ->assertJsonPath('scorecard.match_version', $second['match_version'])->assertJsonPath('scorecard.series.standings.0.score', 2);
        $this->assertArrayNotHasKey('organization_id', $page->json('data.0'));
        $this->getJson($s['root'].'/games?per_page=1&page=2')->assertOk()->assertJsonPath('data.0.number', 1);
    }

    public function test_remake_is_retained_without_counting_and_draw_requires_explicit_rules(): void
    {
        $s = $this->scoreScenario('bo1', draw: true);
        $first = $this->postJson($s['root'].'/games', $this->scorePayload($s, 'remake', null, null))->assertCreated()
            ->assertJsonPath('data.series.played', 0)->assertJsonPath('data.series.complete', false)->json('data');
        $draw = $this->postJson($s['root'].'/games', [...$this->scorePayload($s, 'draw', 10, 10), 'version' => $first['match_version']])
            ->assertCreated()->assertJsonPath('data.series.draw', true)->assertJsonCount(2, 'data.series.winner_ids')->json('data');
        $this->assertSame(2, $draw['game']['number']);
        $this->assertDatabaseCount('match_game_instances', 2);
    }

    public function test_multiple_participants_use_explicit_points_and_final_ties_are_refused_unless_allowed(): void
    {
        $s = $this->scoreScenario('lobby');
        $first = $this->postJson($s['root'].'/games', $this->scorePayload($s, 'abandon', 8, 2))->assertCreated()->json('data');
        $secondBody = [...$this->scorePayload($s, 'played', 2, 8), 'version' => $first['match_version']];
        $this->postJson($s['root'].'/games', $secondBody)->assertUnprocessable()->assertJsonValidationErrors('results');
        $this->assertDatabaseCount('match_game_instances', 1);
        $this->assertDatabaseHas('matches', ['id' => $s['record']['id'], 'version' => $first['match_version']]);
        $secondBody['results'][1]['score'] = 7;
        $this->postJson($s['root'].'/games', $secondBody)->assertCreated()->assertJsonPath('data.series.complete', true)
            ->assertJsonPath('data.series.standings.0.score', 10)->assertJsonPath('data.series.standings.1.score', 9);
    }

    public function test_configuration_is_required_and_immutable_and_obeys_the_match_format(): void
    {
        $s = $this->scoreScenario(configure: false);
        $this->postJson($s['root'].'/games', $this->scorePayload($s))->assertConflict()->assertJsonPath('code', 'MATCH_SCORE_RULES_REQUIRED');
        $body = ['version' => $s['record']['version'], 'allow_draw' => false, 'game_limit' => 5, 'reason' => 'Règles confirmées avant la saisie.'];
        $this->postJson($s['root'].'/score-rules', $body)->assertUnprocessable()->assertJsonValidationErrors('game_limit');
        $configured = $this->postJson($s['root'].'/score-rules', [...$body, 'game_limit' => 3])->assertCreated()->json('data');
        $this->postJson($s['root'].'/score-rules', [...$body, 'version' => $configured['match_version'], 'game_limit' => 3])
            ->assertConflict()->assertJsonPath('code', 'MATCH_SCORE_RULES_FROZEN');
        $this->assertDatabaseCount('match_score_rules', 1);
    }

    /** @return array<string,array{string}> */
    public static function invalidCases(): array
    {
        return array_combine(['unknown', 'nested_unknown', 'negative', 'decimal', 'too_large', 'duplicate', 'foreign', 'missing', 'draw', 'tie', 'remake', 'null', 'blank', 'map_mode', 'patch'],
            array_map(fn ($v) => [$v], ['unknown', 'nested_unknown', 'negative', 'decimal', 'too_large', 'duplicate', 'foreign', 'missing', 'draw', 'tie', 'remake', 'null', 'blank', 'map_mode', 'patch']));
    }

    #[DataProvider('invalidCases')]
    public function test_invalid_scores_return_422_without_changing_version_or_audit(string $case): void
    {
        $s = $this->scoreScenario();
        $body = $this->scorePayload($s);
        switch ($case) {
            case 'unknown': $body['series_score'] = 2;
                break;
            case 'nested_unknown': $body['results'][0]['winner'] = true;
                break;
            case 'negative': $body['results'][0]['score'] = -1;
                break;
            case 'decimal': $body['results'][0]['score'] = 2.5;
                break;
            case 'too_large': $body['results'][0]['score'] = 1000001;
                break;
            case 'duplicate': $body['results'][1]['participant_id'] = strtoupper($body['results'][0]['participant_id']);
                break;
            case 'foreign': $body['results'][0]['participant_id'] = (string) Str::uuid();
                break;
            case 'missing': array_pop($body['results']);
                break;
            case 'draw': $body['outcome'] = 'draw';
                $body['results'][1]['score'] = 13;
                break;
            case 'tie': $body['results'][1]['score'] = 13;
                break;
            case 'remake': $body['outcome'] = 'remake';
                break;
            case 'null': $body['results'][0]['score'] = null;
                break;
            case 'blank': $body['reason'] = '          x ';
                break;
            case 'map_mode': unset($body['map']);
                break;
            case 'patch': $body['game_patch'] = ' ';
                break;
        }
        $this->postJson($s['root'].'/games', $body)->assertUnprocessable();
        $this->assertDatabaseCount('match_game_instances', 0);
        $this->assertDatabaseCount('organization_audit_events', 4);
        $this->assertDatabaseHas('matches', ['id' => $s['record']['id'], 'version' => $s['record']['version']]);
    }

    public function test_audience_permissions_foreign_tenants_and_pagination_are_enforced(): void
    {
        $s = $this->scoreScenario();
        $body = $this->scorePayload($s);
        $this->getJson($s['root'].'/games?per_page=101')->assertUnprocessable();
        $foreign = Organization::factory()->create();
        $this->getJson($this->matchRoot($foreign).'/'.$s['record']['id'].'/games')->assertNotFound();
        $this->actingAsWithMfa($s['reader']);
        $this->postJson($s['root'].'/games', $body)->assertForbidden();
        $this->postJson($s['root'].'/score-rules', ['version' => $s['record']['version']])->assertForbidden();
        $this->getJson($s['root'].'/games?management=1')->assertForbidden();
        $s['person']->forceFill(['user_id' => null])->save();
        $this->getJson($s['root'].'/games')->assertNotFound();
        $this->assertDatabaseCount('match_game_instances', 0);
    }

    public function test_scores_can_be_entered_after_finish_but_not_after_cancellation(): void
    {
        $s = $this->scoreScenario();
        $record = $this->postJson($s['root'].'/transitions', ['version' => $s['record']['version'], 'status' => 'awaiting_validation', 'reason' => 'Rencontre terminée, saisie du résultat.'])->assertOk()->json('data');
        $this->postJson($s['root'].'/games', [...$this->scorePayload($s), 'version' => $record['version']])->assertCreated();
        $s = $this->scoreScenario();
        $record = $this->postJson($s['root'].'/transitions', ['version' => $s['record']['version'], 'status' => 'cancelled', 'reason' => 'Rencontre annulée pour indisponibilité.'])->assertOk()->json('data');
        $this->postJson($s['root'].'/games', [...$this->scorePayload($s), 'version' => $record['version']])->assertConflict()->assertJsonPath('code', 'MATCH_SCORE_FROZEN');
    }

    public function test_audit_failure_rolls_back_the_game_and_match_version(): void
    {
        $s = $this->scoreScenario();
        $this->mock(TenantAuditJournal::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $this->postJson($s['root'].'/games', $this->scorePayload($s))->assertStatus(500);
        $this->assertDatabaseCount('match_game_instances', 0);
        $this->assertDatabaseHas('matches', ['id' => $s['record']['id'], 'version' => $s['record']['version']]);
    }

    public function test_three_participant_free_for_all_preserves_each_score_and_shared_first_places(): void
    {
        $s = $this->scoreScenario('free_for_all', draw: true, limit: 1, participantCount: 3);
        $body = $this->scorePayload($s, 'draw', 10, 10);
        $body['results'][] = ['participant_id' => $s['record']['participants'][2]['id'], 'score' => 3];
        $this->postJson($s['root'].'/games', $body)->assertCreated()->assertJsonCount(3, 'data.game.results')
            ->assertJsonCount(2, 'data.series.winner_ids')->assertJsonPath('data.series.draw', true)->assertJsonPath('data.series.complete', true);
        $this->assertDatabaseCount('match_game_instances', 1);
    }

    public function test_shared_results_require_explicit_publish_permission_and_confirmation(): void
    {
        [$org, $writer, , $person, $other, $game] = $this->matchScenario();
        $role = OrganizationRole::query()->where('organization_id', $org->id)->whereJsonContains('permissions', 'sports.write')->sole();
        $role->forceFill(['permissions' => ['sports.read', 'sports.write', 'sports.publish']])->save();
        $record = $this->postJson($this->matchRoot($org), [...$this->matchPayload($game, $person, $other), 'visibility' => 'organization', 'confirm_sharing' => true])->assertCreated()->json('data');
        $root = $this->matchRoot($org).'/'.$record['id'];
        foreach (['confirmed', 'in_progress'] as $status) {
            $record = $this->postJson($root.'/transitions', ['version' => $record['version'], 'status' => $status, 'reason' => 'Transition sportive explicitement retenue.'])->assertOk()->json('data');
        }
        $config = ['version' => $record['version'], 'game_limit' => 3, 'allow_draw' => false, 'reason' => 'Règles du match partagées et confirmées.'];
        $this->postJson($root.'/score-rules', $config)->assertUnprocessable()->assertJsonValidationErrors('confirm_sharing');
        $configured = $this->postJson($root.'/score-rules', [...$config, 'confirm_sharing' => true])->assertCreated()->json('data');
        $record['version'] = $configured['match_version'];
        $body = $this->scorePayload(['record' => $record]);
        $this->postJson($root.'/games', $body)->assertUnprocessable()->assertJsonValidationErrors('confirm_sharing');
        $role->forceFill(['permissions' => ['sports.read', 'sports.write']])->save();
        $this->postJson($root.'/games', [...$body, 'confirm_sharing' => true])->assertForbidden();
        $this->assertDatabaseCount('match_game_instances', 0);
        $role->forceFill(['permissions' => ['sports.read', 'sports.write', 'sports.publish']])->save();
        $this->postJson($root.'/games', [...$body, 'confirm_sharing' => true])->assertCreated();
    }

    public function test_guests_mfa_revalidation_and_revoked_permissions_cannot_write_scores(): void
    {
        $s = $this->scoreScenario();
        $body = $this->scorePayload($s);
        $this->withSession([MfaSession::PROOF_KEY => null]);
        $this->postJson($s['root'].'/games', $body)->assertForbidden();
        $this->actingAsWithMfa($s['writer']);
        OrganizationRoleAssignment::query()->where('organization_id', $s['org']->id)->where('user_id', $s['writer']->id)->update(['status' => 'suspended']);
        $this->postJson($s['root'].'/games', $body)->assertForbidden();
        auth()->forgetGuards();
        $this->getJson($s['root'].'/games')->assertUnauthorized();
        $this->postJson($s['root'].'/games', $body)->assertUnauthorized();
        $this->assertDatabaseCount('match_game_instances', 0);
    }

    public function test_game_history_query_count_is_constant_with_pagination(): void
    {
        $s = $this->scoreScenario('lobby', limit: 3);
        foreach ([1, 2, 3] as $number) {
            $response = $this->postJson($s['root'].'/games', $this->scorePayload($s))->assertCreated()->json('data');
            $s['record']['version'] = $response['match_version'];
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson($s['root'].'/games?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 3);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(3, array_filter($queries, fn (array $q): bool => str_starts_with(strtolower($q['query']), 'select') && str_contains($q['query'], 'from "match_game_instances"')));
    }

    public function test_service_rejects_unknown_nested_input_without_http_validation(): void
    {
        $s = $this->scoreScenario();
        $context = new TenantContext($s['org']->id, $s['writer']->id, 'test', 'UTC', 'fr', (string) Str::uuid());
        $body = $this->scorePayload($s);
        $body['results'][0]['actor_user_id'] = 7;
        $this->expectException(ValidationException::class);
        app(MatchScoreService::class)->save($context, $s['record']['id'], $body, false, null);
    }
}
