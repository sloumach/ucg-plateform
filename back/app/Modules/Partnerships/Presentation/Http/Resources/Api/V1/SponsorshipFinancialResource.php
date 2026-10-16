<?php

namespace App\Modules\Partnerships\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use Illuminate\Http\Request;

final class SponsorshipFinancialResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->resource;

        return array_intersect_key($data, array_fill_keys(['id', 'version', 'terms', 'agreement_version'], true));
    }
}
