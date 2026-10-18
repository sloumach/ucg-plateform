<?php

namespace App\Modules\Competition\Presentation\Http\Resources\Api\V1;

use App\Modules\Competition\Domain\Models\MatchResultEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MatchResultEvent */
final class MatchResultEventResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'number' => $this->number, 'action' => $this->action, 'outcome' => $this->outcome, 'from_status' => $this->from_status,
            'to_status' => $this->to_status, 'match_version' => $this->match_version, 'submission_id' => $this->submission_id,
            'proof_reference' => $this->proof_reference, 'snapshot' => $this->snapshot, 'reason' => $this->reason,
            'actor_user_id' => $this->actor_user_id, 'occurred_at' => $this->created_at->toIso8601String()];
    }
}
