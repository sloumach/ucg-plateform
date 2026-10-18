<?php

namespace App\Modules\Competition\Presentation\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class MatchLiveScoreResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        $data = $this->resource;

        return ['match_version' => $data['match_version'], 'score_revision' => $data['score_revision'], 'recalculation_pending' => $data['recalculation_pending'],
            'outcome' => $data['outcome'], 'winner_ids' => $data['winner_ids'], 'status' => $data['status'], 'series' => $data['series'], 'observed_at' => $data['observed_at']];
    }
}
