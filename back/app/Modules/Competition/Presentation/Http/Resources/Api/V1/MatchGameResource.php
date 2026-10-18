<?php

namespace App\Modules\Competition\Presentation\Http\Resources\Api\V1;

use App\Modules\Competition\Domain\Models\MatchGameInstance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MatchGameInstance */
final class MatchGameResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'number' => $this->number, 'match_version' => $this->match_version, 'outcome' => $this->outcome,
            'map' => $this->map, 'mode' => $this->mode, 'game_patch' => $this->game_patch, 'reason' => $this->reason,
            'actor_user_id' => $this->actor_user_id, 'occurred_at' => $this->created_at->toIso8601String(), 'results' => $this->results];
    }
}
