<?php

namespace App\Modules\Scheduling\Presentation\Http\Resources\Api\V1;

use App\Modules\Scheduling\Domain\Models\CalendarFeed;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CalendarFeed */
final class CalendarFeedResource extends JsonResource
{
    /** @return array<string,mixed>|null */
    public function toArray(Request $request): ?array
    {
        if ($this->resource === null) {
            return null;
        }

        return ['id' => $this->id, 'before_days' => $this->before_days, 'after_days' => $this->after_days,
            'expires_at' => $this->expires_at->toIso8601String(), 'revoked_at' => $this->revoked_at?->toIso8601String(),
            'status' => $this->revoked_at !== null ? 'revoked' : ($this->expires_at->lte(now()) ? 'expired' : 'active'), 'version' => $this->version];
    }
}
