<?php

namespace Tests\Fixtures;

use App\Modules\People\Domain\Models\Person;

trait ProvidesMatchScores
{
    use ProvidesMatches;

    /** @return array<string,mixed> */
    private function scoreScenario(string $format = 'bo3', bool $configure = true, bool $draw = false, int $limit = 2, int $participantCount = 2): array
    {
        [$org, $writer, $reader, $person, $other, $game] = $this->matchScenario();
        $payload = [...$this->matchPayload($game, $person, $other), 'format' => $format];
        for ($i = 2; $i < $participantCount; $i++) {
            $extra = Person::factory()->create(['organization_id' => $org->id]);
            $payload['participants'][] = ['kind' => 'person', 'source_id' => $extra->id];
        }
        $record = $this->postJson($this->matchRoot($org), $payload)->assertCreated()->json('data');
        $root = $this->matchRoot($org).'/'.$record['id'];
        foreach (['confirmed', 'in_progress'] as $status) {
            $record = $this->postJson($root.'/transitions', ['version' => $record['version'], 'status' => $status, 'reason' => 'Transition sportive vérifiée pour le test.'])->assertOk()->json('data');
        }
        $limit = match ($format) {
            'bo1' => 1, 'bo3' => 3, 'bo5' => 5, default => $limit
        };
        if ($configure) {
            $rules = $this->postJson($root.'/score-rules', ['version' => $record['version'], 'game_limit' => $limit, 'allow_draw' => $draw, 'reason' => 'Règles confirmées avant la saisie.'])->assertCreated()->json('data');
            $record['version'] = $rules['match_version'];
        }

        return compact('org', 'writer', 'reader', 'person', 'other', 'game', 'record', 'root', 'limit');
    }

    /** @param array<string,mixed> $s
     * @return array<string,mixed>
     */
    private function scorePayload(array $s, string $outcome = 'played', ?int $a = 13, ?int $b = 7): array
    {
        return ['version' => $s['record']['version'], 'map' => '<script>Carte pilote</script>', 'game_patch' => '1.2.3', 'outcome' => $outcome,
            'reason' => 'Résultat relevé sur la feuille de match.', 'results' => [
                ['participant_id' => $s['record']['participants'][0]['id'], 'score' => $a],
                ['participant_id' => $s['record']['participants'][1]['id'], 'score' => $b]]];
    }
}
