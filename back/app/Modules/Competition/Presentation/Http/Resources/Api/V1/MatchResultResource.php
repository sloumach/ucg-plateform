<?php

namespace App\Modules\Competition\Presentation\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class MatchResultResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        $data = $this->resource;

        return ['score_revision' => $data['score_revision'] ?? null, 'recalculation_pending' => $data['recalculation_pending'] ?? false, 'match_version' => $data['match_version'], 'status' => $data['status'], 'locked' => $data['locked'], 'allowed_actions' => $data['allowed_actions'],
            'current' => $data['current'] === null ? null : MatchResultEventResource::make($data['current']),
            'submission' => $data['submission'] === null ? null : MatchResultEventResource::make($data['submission'])];
    }
}
