<?php

namespace App\Modules\Competition\Application\Services;

use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Database\ConnectionInterface;

final readonly class MatchRealtimeService
{
    public function __construct(private MatchService $matches, private TenantAuthorization $authorization, private MatchScoreService $scores, private MatchResultService $results, private ConnectionInterface $database) {}

    /** @return array<string,mixed> */
    public function snapshot(TenantContext $context, string $matchId, bool $ordinaryAudience = false): array
    {
        $management = ! $ordinaryAudience && $this->authorization->allows($context, 'sports.write');

        return $this->database->transaction(function () use ($context, $matchId, $management): array {
            $score = $this->scores->index($context, $matchId, ['per_page' => 1, 'management' => $management]);
            $result = $this->results->index($context, $matchId, ['per_page' => 1, 'management' => $management]);

            return ['match_version' => $score['match_version'], 'score_revision' => $score['score_revision'], 'recalculation_pending' => $score['recalculation_pending'],
                'outcome' => $result['current']?->action === 'approve' ? $result['submission']?->outcome : null,
                'winner_ids' => $result['current']?->action === 'approve' ? ($result['submission']?->snapshot['winner_ids'] ?? []) : [],
                'series' => $score['series'], 'status' => $result['status'], 'observed_at' => now()->toISOString()];
        });
    }

    public function authorize(TenantContext $context, string $matchId): void
    {
        $this->matches->find($context, $matchId, ['management' => $this->authorization->allows($context, 'sports.write')]);
    }
}
