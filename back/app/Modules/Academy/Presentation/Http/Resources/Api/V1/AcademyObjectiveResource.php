<?php

namespace App\Modules\Academy\Presentation\Http\Resources\Api\V1;

use App\Modules\Academy\Domain\Models\AcademyObjective;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AcademyObjective */
final class AcademyObjectiveResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $self = str_starts_with((string) $request->route('coaching_kind'), 'self');

        return ['id' => $this->id, 'title' => $this->title, 'description' => $this->description,
            'due_on' => $this->due_on, 'status' => $this->status, 'scope' => $this->target_enrollment_id === null ? 'collective' : 'individual',
            ...($self ? [] : ['cycle_id' => $this->cycle_id, 'version' => $this->version, 'target_enrollment_id' => $this->target_enrollment_id,
                'responsible_enrollment_id' => $this->responsible_enrollment_id,
                'responsible_label' => $this->getAttribute('responsible_label'), 'target_label' => $this->getAttribute('target_label')])];
    }
}
