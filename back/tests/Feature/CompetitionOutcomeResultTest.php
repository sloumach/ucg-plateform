<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesCompetitionOutcomes;
use Tests\Fixtures\ProvidesExternalCompetitions;
use Tests\Fixtures\ProvidesMatchResults;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class CompetitionOutcomeResultTest extends TestCase
{
    use AuthenticatesWithMfa,LazilyRefreshDatabase,ProvidesCompetitionOutcomes,ProvidesExternalCompetitions,ProvidesMatchResults,ProvidesPeopleAccess;

    public function test_only_currently_approved_match_results_are_exposed_without_evidence(): void
    {
        $s = $this->resultScenario();
        $this->outcomePermissions($s, ['sports.read', 'sports.write', 'sports.competitions.read', 'sports.competitions.write']);
        $competition = $this->postJson('/api/v1/tenants/'.$s['org']->id.'/external-competitions', $this->competitionPayload($s['game']->id))->assertCreated()->json('data');
        $path = '/api/v1/tenants/'.$s['org']->id.'/external-competitions/'.$competition['id'].'/outcomes/sport';
        $row = $this->patchJson($path, ['competition_version' => $competition['version'], 'expected_version' => null, 'reason' => 'Rattachement au rapport officiel de compétition.', 'source' => 'Rapport organisateur', 'source_url' => null, 'observed_on' => now()->format('Y-m-d'), 'notes' => null, 'place' => null, 'field_size' => null,
            'matches' => [['reference_id' => null, 'match_id' => $s['record']['id'], 'match_version' => $s['record']['version']]]])->assertOk()->json('data');
        $resultPath = $path.'/matches/'.$row['matches'][0]['reference_id'].'/result';
        $this->getJson($resultPath)->assertOk()->assertJsonPath('data.approved', false)->assertJsonPath('data.series', null);
        $submission = $this->postJson($s['root'].'/results', $this->resultPayload($s))->assertCreated()->json('data');
        $this->getJson($resultPath)->assertOk()->assertJsonPath('data.outcome', null);
        $this->actingAsWithMfa($s['reader']);
        $approved = $this->postJson($s['root'].'/results/approvals', $this->decisionPayload($submission))->assertOk()->json('data');
        $this->actingAsWithMfa($s['writer']);
        $this->getJson($resultPath)->assertOk()->assertJsonPath('data.approved', true)->assertJsonPath('data.outcome', 'played')->assertJsonPath('data.series.complete', true)->assertJsonMissingPath('data.proof_reference')->assertDontSee('R-2026-42');
        $this->actingAsWithMfa($s['reader']);
        $body = $this->decisionPayload($approved);
        unset($body['confirm_evidence']);
        $this->postJson($s['root'].'/results/disputes', $body)->assertOk();
        $this->actingAsWithMfa($s['writer']);
        $this->getJson($resultPath)->assertOk()->assertJsonPath('data.approved', false)->assertJsonPath('data.series', null)->assertJsonPath('data.outcome', null);
    }

    /** @return array<string,array{string}> */
    public static function exceptionalOutcomes(): array
    {
        return ['forfeit' => ['forfeit'], 'abandon' => ['abandon']];
    }

    #[DataProvider('exceptionalOutcomes')]
    public function test_exceptional_decisions_do_not_invent_game_scores(string $outcome): void
    {
        $s = $this->resultScenario(false);
        $this->outcomePermissions($s, ['sports.read', 'sports.write', 'sports.competitions.read', 'sports.competitions.write']);
        $competition = $this->postJson('/api/v1/tenants/'.$s['org']->id.'/external-competitions', $this->competitionPayload($s['game']->id))->assertCreated()->json('data');
        $path = '/api/v1/tenants/'.$s['org']->id.'/external-competitions/'.$competition['id'].'/outcomes/sport';
        $row = $this->patchJson($path, ['competition_version' => $competition['version'], 'expected_version' => null, 'reason' => 'Rapport de décision exceptionnelle conservé.', 'source' => 'Décision de l’organisateur', 'source_url' => null, 'observed_on' => now()->format('Y-m-d'), 'notes' => null, 'place' => null, 'field_size' => null, 'matches' => [['reference_id' => null, 'match_id' => $s['record']['id'], 'match_version' => $s['record']['version']]]])->assertOk()->json('data');
        $submission = $this->postJson($s['root'].'/results', $this->resultPayload($s, $outcome))->assertCreated()->json('data');
        $this->actingAsWithMfa($s['reader']);
        $this->postJson($s['root'].'/results/approvals', $this->decisionPayload($submission))->assertOk();
        $this->actingAsWithMfa($s['writer']);
        $this->getJson($path.'/matches/'.$row['matches'][0]['reference_id'].'/result')->assertOk()->assertJsonPath('data.approved', true)->assertJsonPath('data.outcome', $outcome)->assertJsonPath('data.series', null)->assertJsonPath('data.winners.0.participant_id', $s['record']['participants'][0]['id']);
    }
}
