<?php

namespace App\Modules\Scheduling\Presentation\Http\Resources\Api\V1;

use App\Modules\Scheduling\Domain\Models\CalendarResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CalendarResource */
final class CalendarResourceResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'key' => $this->key, 'label' => $this->label, 'kind' => $this->kind, 'status' => $this->status, 'version' => $this->version];
    }
}
