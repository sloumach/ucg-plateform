<?php

namespace App\Modules\Competition\Domain\Scoring;

final class BestOfScore implements ScoreStrategy
{
    public function calculate(array $participants, array $games, int $limit): array
    {
        $scores = array_fill_keys($participants, 0);
        if ($scores === [] || count($scores) !== 2 || count($participants) !== 2 || ! in_array($limit, [1, 3, 5], true)) {
            throw new \InvalidArgumentException('Invalid best-of rules.');
        }
        $played = 0;
        $complete = false;
        foreach ($games as $game) {
            if ($complete) {
                throw new \DomainException('complete');
            }
            if ($game['outcome'] === 'remake') {
                continue;
            }
            $played++;
            if ($game['outcome'] !== 'draw') {
                $results = $game['results'];
                usort($results, fn (array $a, array $b): int => $b['score'] <=> $a['score']);
                $scores[$results[0]['participant_id']]++;
            }
            // A declared draw consumes a scheduled game; no fictitious win is awarded.
            $complete = max($scores) >= intdiv($limit, 2) + 1 || $played >= $limit;
        }
        $leaders = $complete ? array_keys($scores, max($scores), true) : [];

        return ['complete' => $complete, 'played' => $played, 'winner_ids' => $leaders,
            'standings' => array_map(fn (string $id): array => ['participant_id' => $id, 'score' => $scores[$id]], $participants),
            'draw' => $complete && count($leaders) > 1];
    }
}
