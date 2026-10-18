<?php

namespace App\Modules\Competition\Presentation\Http\Resources\Api\V1;

use App\Modules\Competition\Domain\Models\MatchScoreCorrection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MatchScoreCorrection */
final class MatchCorrectionResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        $filter = fn (array $rows): array => array_values(array_filter($rows, fn ($row): bool => in_array($row['game_id'], $this->changed_ids, true)));

        return ['id' => $this->id, 'number' => $this->number, 'match_version' => $this->match_version, 'state' => $this->recalculation === null ? 'pending' : 'applied',
            'before' => $filter($this->before), 'after' => $filter($this->after), 'reason' => $this->reason, 'actor_user_id' => $this->actor_user_id,
            'occurred_at' => $this->created_at->toIso8601String(), 'applied_at' => $this->recalculation?->created_at->toIso8601String()];
    }
}
