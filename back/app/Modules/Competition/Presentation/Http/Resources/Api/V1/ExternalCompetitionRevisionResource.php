<?php

namespace App\Modules\Competition\Presentation\Http\Resources\Api\V1;

use App\Modules\Competition\Domain\Models\ExternalCompetitionRevision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ExternalCompetitionRevision */
final class ExternalCompetitionRevisionResource extends JsonResource
{
    /**
     * @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'number' => $this->number, 'version' => $this->version, 'snapshot' => $this->snapshot, 'changed_fields' => $this->changed_fields, 'reason' => $this->reason, 'actor_user_id' => $this->actor_user_id, 'occurred_at' => $this->created_at->toIso8601String()];
    }
}
