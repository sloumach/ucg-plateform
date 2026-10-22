<?php

namespace App\Modules\Academy\Presentation\Http\Resources\Api\V1;

use App\Modules\Academy\Domain\Models\AcademyCycle;
use App\Modules\Academy\Domain\Models\AcademyEnrollment;
use App\Modules\Academy\Domain\Models\AcademyRevision;
use App\Modules\Academy\Domain\Models\Cohort;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Cohort */
final class AcademyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $record = $this->resource;
        if ($record instanceof AcademyRevision) {
            // The encrypted snapshot is retained for maintenance, never returned as an unfiltered historical dossier.
            return ['id' => $record->id, 'kind' => $record->kind, 'resource_id' => $record->resource_id,
                'actor_user_id' => $record->actor_user_id, 'recorded_at' => $record->recorded_at->toIso8601String()];
        }
        $fields = ['id' => $record->id, 'status' => $record->status, 'version' => $record->version];
        if ($record instanceof AcademyEnrollment) {
            return [...$fields, 'cohort_id' => $record->cohort_id, 'cycle_id' => $record->cycle_id,
                'person_id' => $record->person_id, 'person_label' => $record->getAttribute('person_label'), 'role' => $record->role, 'starts_on' => $record->starts_on, 'ends_on' => $record->ends_on];
        }
        if ($record instanceof AcademyCycle) {
            return [...$fields, 'name' => $record->name, 'cohort_id' => $record->cohort_id, 'starts_on' => $record->starts_on,
                'ends_on' => $record->ends_on, 'timezone' => $record->timezone, 'timetable' => $record->timetable];
        }

        return [...$fields, 'name' => $record->name];
    }
}
