<?php

namespace App\Modules\Academy\Presentation\Http\Resources\Api\V1;

use App\Modules\Scheduling\Application\Contracts\AcademyActivity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AcademyActivity */
final class AcademyActivityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'version' => $this->version, 'label' => $this->label,
            'starts_at' => $this->startsAt->toIso8601String(), 'ends_at' => $this->endsAt->toIso8601String(), 'timezone' => $this->timezone];
    }
}
