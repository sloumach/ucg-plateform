<?php

namespace App\Modules\Scheduling\Presentation\Http\Resources\Api\V1;

use App\Modules\Scheduling\Application\Data\CalendarConflictReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CalendarConflictReport */
final class CalendarConflictResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return ['conflicts' => $this->conflicts, 'truncated' => $this->truncated, 'availability_checked' => $this->availabilityChecked,
            'can_override' => $this->canOverride, 'review_token' => $this->reviewToken, 'expires_in' => 300];
    }
}
