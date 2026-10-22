<?php

namespace App\Modules\Academy\Presentation\Http\Resources\Api\V1;

use App\Modules\Academy\Domain\Models\AcademyAttendance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AcademyAttendance */
final class AcademyAttendanceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'enrollment_id' => $this->enrollment_id, 'person_label' => $this->getAttribute('person_label'),
            'status' => $this->status, 'late_minutes' => $this->late_minutes, 'version' => $this->version];
    }
}
