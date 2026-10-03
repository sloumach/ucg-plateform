<?php

namespace App\Http\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

abstract class ApiResource extends JsonResource
{
    /** @return array<string, array<string, string>> */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'request_id' => RequestId::for($request),
            ],
        ];
    }
}
