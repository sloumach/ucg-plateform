<?php

namespace App\Modules\Scheduling\Presentation\Http\Resources\Api\V1;

use App\Modules\Scheduling\Domain\Models\CalendarChange;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CalendarChange */
final class CalendarChangeResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        $data = ['id' => $this->id, 'activity_id' => $this->activity_id, 'kind' => $this->kind, 'title' => $this->title, 'reason' => $this->reason,
            'before' => ['starts_at' => $this->previous_starts_at->toIso8601String(), 'ends_at' => $this->previous_ends_at->toIso8601String(), 'origin_timezone' => $this->previous_timezone],
            'after' => ['starts_at' => $this->starts_at->toIso8601String(), 'ends_at' => $this->ends_at->toIso8601String(), 'origin_timezone' => $this->origin_timezone],
            'occurred_at' => $this->created_at->toIso8601String(), 'activity_version' => $this->activity_version];
        if (array_key_exists('recipients_count', $this->resource->getAttributes())) {
            $data += ['recipients_count' => (int) $this->resource->getAttribute('recipients_count'),
                'delivered_count' => (int) $this->resource->getAttribute('delivered_count'), 'blocked_count' => (int) $this->resource->getAttribute('blocked_count')];
        }

        return $data;
    }
}
