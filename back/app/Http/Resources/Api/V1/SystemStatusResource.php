<?php

namespace App\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use Illuminate\Http\Request;

class SystemStatusResource extends ApiResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'api_version' => $this->resource['api_version'],
            'name' => $this->resource['name'],
            'status' => $this->resource['status'],
        ];
    }
}
