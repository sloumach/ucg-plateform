<?php

namespace App\Modules\Academy\Presentation\Http\Resources\Api\V1;

use App\Modules\Academy\Domain\Models\AcademyCoachingRevision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AcademyCoachingRevision */
final class AcademyCoachingRevisionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'kind' => $this->kind, 'resource_id' => $this->resource_id,
            'actor_user_id' => $this->actor_user_id, 'recorded_at' => $this->recorded_at];
    }
}
