<?php

namespace App\Modules\Scheduling\Presentation\Http\Resources\Api\V1;

use App\Modules\Scheduling\Domain\Models\Availability;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Availability */
final class AvailabilityResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'person_id' => $this->person_id, 'person_label' => $this->getAttribute('person_label'), 'status' => $this->status,
            'comment' => $this->when((bool) $this->getAttribute('comment_visible'), fn () => $this->comment),
            'starts_at' => $this->starts_at->utc()->toIso8601String(), 'ends_at' => $this->ends_at->utc()->toIso8601String(), 'origin_timezone' => $this->origin_timezone,
            'starts_at_origin' => $this->starts_at->setTimezone($this->origin_timezone)->toIso8601String(),
            'ends_at_origin' => $this->ends_at->setTimezone($this->origin_timezone)->toIso8601String(), 'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'version' => $this->version, 'number' => $this->number, 'can_edit' => (bool) $this->getAttribute('can_edit'),
            'can_cancel' => (bool) $this->getAttribute('can_cancel'), 'can_history' => (bool) $this->getAttribute('can_history')];
    }
}
