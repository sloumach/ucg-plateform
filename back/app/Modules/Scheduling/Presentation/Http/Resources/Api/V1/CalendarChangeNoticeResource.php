<?php

namespace App\Modules\Scheduling\Presentation\Http\Resources\Api\V1;

use App\Modules\Scheduling\Domain\Models\CalendarChangeRecipient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CalendarChangeRecipient */
final class CalendarChangeNoticeResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'change' => CalendarChangeResource::make($this->change),
            'delivered_at' => $this->delivered_at?->toIso8601String(), 'current' => $this->change->activity->version === $this->change->activity_version];
    }
}
