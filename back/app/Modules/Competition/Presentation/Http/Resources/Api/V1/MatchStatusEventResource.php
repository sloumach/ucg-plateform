<?php

namespace App\Modules\Competition\Presentation\Http\Resources\Api\V1;

use App\Modules\Competition\Domain\Models\MatchStatusEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MatchStatusEvent */
final class MatchStatusEventResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'from_status' => $this->from_status, 'to_status' => $this->to_status,
            'match_version' => $this->match_version, 'reason' => $this->reason, 'occurred_at' => $this->created_at->toIso8601String()];
    }
}
