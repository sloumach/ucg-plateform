<?php

namespace App\Modules\Scheduling\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use Illuminate\Http\Request;

final class CalendarActivityResource extends ApiResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        /** @var CalendarActivity $item */ $item = $this->resource;
        $labels = $item->getAttribute('participant_labels') ?? [];

        return ['id' => $item->id, 'title' => $item->title, 'description' => $item->description, 'kind' => $item->kind,
            'starts_at' => $item->starts_at->toIso8601String(), 'ends_at' => $item->ends_at->toIso8601String(), 'origin_timezone' => $item->origin_timezone,
            'starts_at_origin' => $item->starts_at->setTimezone($item->origin_timezone)->toIso8601String(),
            'ends_at_origin' => $item->ends_at->setTimezone($item->origin_timezone)->toIso8601String(),
            'team_id' => $item->team_id, 'team_label' => $item->getAttribute('team_label'), 'location' => $item->location, 'url' => $item->url,
            'visibility' => $item->visibility, 'participants' => $item->participants->map(fn ($participant): array => ['id' => $participant->person_id, 'label' => $labels[$participant->person_id] ?? null])->all(),
            'resources' => $item->reservations->map(fn ($reservation): array => ['id' => $reservation->resource_id,
                'label' => $reservation->resource->label, 'archived' => $reservation->resource->status === 'archived'])->all(),
            'archived_at' => $item->archived_at?->toIso8601String(), 'version' => $item->version];
    }
}
