<?php

namespace App\Modules\Academy\Presentation\Http\Resources\Api\V1;

use App\Modules\Academy\Domain\Models\AcademySession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AcademySession */
final class AcademySessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $activity = $this->getAttribute('schedule');

        return ['id' => $this->id, 'cycle_id' => $this->cycle_id, 'title' => $this->title, 'exercises' => $this->exercises,
            'report' => $this->report, 'status' => $this->status, 'version' => $this->version,
            'responsible_enrollment_id' => $this->responsible_enrollment_id, 'responsible_label' => $this->getAttribute('responsible_label'),
            'schedule_state' => $this->getAttribute('schedule_state'), 'activity' => $activity === null ? null : AcademyActivityResource::make($activity),
            'starts_at' => $activity === null ? null : $this->starts_at->toIso8601String(), 'ends_at' => $activity === null ? null : $this->ends_at->toIso8601String(),
            'attendance_count' => $this->attendances_count];
    }
}
