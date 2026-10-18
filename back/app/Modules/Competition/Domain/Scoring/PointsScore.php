<?php

namespace App\Modules\Competition\Domain\Scoring;

final class PointsScore implements ScoreStrategy
{
    public function calculate(array $participants, array $games, int $limit): array
    {
        $scores = array_fill_keys($participants, 0);
        if ($scores === [] || count($scores) !== count($participants) || count($scores) < 2 || $limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Invalid scoring rules.');
        }
        $played = 0;
        foreach ($games as $game) {
            if ($played >= $limit) {
                throw new \DomainException('complete');
            }
            if ($game['outcome'] === 'remake') {
                continue;
            }
            $played++;
            foreach ($game['results'] as $result) {
                $scores[$result['participant_id']] += $result['score'] ?? 0;
            }
        }
        $complete = $played === $limit;
        $leaders = $complete ? array_keys($scores, max($scores), true) : [];
        $standings = array_map(fn (string $id): array => ['participant_id' => $id, 'score' => $scores[$id]], $participants);
        usort($standings, fn (array $a, array $b): int => ($b['score'] <=> $a['score']) ?: strcmp($a['participant_id'], $b['participant_id']));

        return ['complete' => $complete, 'played' => $played, 'winner_ids' => $leaders, 'standings' => $standings, 'draw' => $complete && count($leaders) > 1];
    }
}
