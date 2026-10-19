<?php

namespace App\Modules\Reporting\Presentation\Http\Resources\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \ArrayObject<string,mixed> */
final class DashboardResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }

    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->header('Cache-Control', 'private, no-store');
    }
}
