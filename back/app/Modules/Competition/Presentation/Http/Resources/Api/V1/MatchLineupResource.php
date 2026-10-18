<?php

namespace App\Modules\Competition\Presentation\Http\Resources\Api\V1;

use App\Modules\Competition\Domain\Models\MatchLineupRevision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MatchLineupRevision */
final class MatchLineupResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'participant_id' => $this->participant_id, 'match_version' => $this->match_version, 'number' => $this->number,
            'action' => $this->action, 'phase' => $this->phase, 'reason' => $this->reason, 'snapshot' => $this->snapshot,
            'assessment' => $this->assessment, 'actor_user_id' => $this->actor_user_id, 'occurred_at' => $this->created_at->toIso8601String()];
    }
}
