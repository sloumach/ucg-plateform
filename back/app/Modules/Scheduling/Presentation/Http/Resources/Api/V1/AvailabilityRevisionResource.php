<?php

namespace App\Modules\Scheduling\Presentation\Http\Resources\Api\V1;

use App\Modules\Scheduling\Domain\Models\AvailabilityRevision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AvailabilityRevision */
final class AvailabilityRevisionResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'number' => $this->number, 'action' => $this->action, 'status' => $this->status, 'comment' => $this->comment,
            'starts_at' => $this->starts_at->utc()->toIso8601String(), 'ends_at' => $this->ends_at->utc()->toIso8601String(), 'origin_timezone' => $this->origin_timezone,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(), 'version' => $this->version, 'created_at' => $this->created_at->toIso8601String()];
    }
}
