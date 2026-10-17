<?php

namespace App\Modules\Scheduling\Presentation\Http\Resources\Api\V1;

use App\Modules\Scheduling\Domain\Models\CalendarConflictOverride;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CalendarConflictOverride */
final class CalendarConflictOverrideResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        $snapshot = $this->snapshot;

        return ['id' => $this->id, 'activity_version' => $this->activity_version, 'reason' => $this->reason, 'conflict_count' => $this->conflict_count,
            'conflicts' => $snapshot['conflicts'] ?? [], 'availability_checked' => $snapshot['availability_checked'] ?? false, 'created_at' => $this->created_at->toIso8601String()];
    }
}
