<?php

namespace App\Modules\Contracts\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use Illuminate\Http\Request;

final class ContractPersonResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->resource['id'], 'label' => $this->resource['label']];
    }
}
