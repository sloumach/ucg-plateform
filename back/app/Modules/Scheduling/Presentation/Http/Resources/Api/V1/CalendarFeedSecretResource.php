<?php

namespace App\Modules\Scheduling\Presentation\Http\Resources\Api\V1;

use App\Modules\Scheduling\Application\Data\CalendarFeedSecret;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CalendarFeedSecret */
final class CalendarFeedSecretResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return ['feed' => CalendarFeedResource::make($this->feed), 'url' => $this->url];
    }
}
