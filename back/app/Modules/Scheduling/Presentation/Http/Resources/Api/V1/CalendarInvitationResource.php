<?php

namespace App\Modules\Scheduling\Presentation\Http\Resources\Api\V1;

use App\Modules\Scheduling\Domain\Models\CalendarInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CalendarInvitation */
final class CalendarInvitationResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'activity_id' => $this->activity_id, 'activity_version' => $this->activity_version,
            'title' => $this->activity->title, 'starts_at' => $this->activity->starts_at->toIso8601String(),
            'origin_timezone' => $this->activity->origin_timezone, 'person_id' => $this->person_id,
            'person_label' => $this->resource->getAttribute('person_label'), 'response' => $this->response,
            'response_deadline' => $this->response_deadline->toIso8601String(), 'reminder_offsets' => $this->reminder_offsets,
            'responded_at' => $this->responded_at?->toIso8601String(), 'sent_at' => $this->sent_at?->toIso8601String(),
            'current' => $this->resource->isCurrent(), 'expired' => $this->response_deadline->lte(now()),
            'blocked_reason' => $this->blocked_reason, 'delivery_count' => (int) $this->resource->getAttribute('deliveries_count'),
            'can_respond' => (bool) $this->resource->getAttribute('can_respond'), 'version' => $this->version];
    }
}
