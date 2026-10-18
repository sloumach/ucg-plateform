<?php

namespace App\Modules\Competition\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Competition\Domain\Contracts\MatchCorrectionRepository;
use App\Modules\Competition\Domain\Models\MatchGameInstance;

final readonly class MatchCorrectedScores
{
    public function __construct(private MatchCorrectionRepository $corrections) {}

    public function assertReady(string $org, string $match): void
    {
        $latest = $this->corrections->latest($org, $match);
        if ($latest !== null && $latest->recalculation === null) {
            throw new DomainConflictException(__('match_corrections.pending'), 'MATCH_RECALCULATION_PENDING');
        }
    }

    /** @param list<MatchGameInstance> $games
     * @return list<MatchGameInstance> */
    public function apply(string $org, string $match, array $games): array
    {
        $latest = $this->corrections->latest($org, $match);
        if ($latest === null) {
            return $games;
        }
        $changes = array_column(($latest->recalculation === null ? $latest->before : $latest->after), null, 'game_id');

        return array_map(function (MatchGameInstance $game) use ($changes, $latest): MatchGameInstance {
            if (! isset($changes[$game->id])) {
                return $game;
            }
            $copy = clone $game;
            $copy->forceFill(['outcome' => $changes[$game->id]['outcome'], 'results' => $changes[$game->id]['results'], 'correction_id' => $latest->recalculation === null ? $latest->base_correction_id : $latest->id]);

            return $copy;
        }, $games);
    }
}
