<?php

namespace App\Modules\Partnerships\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use Illuminate\Http\Request;

final class SponsorshipExposureResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->resource;

        return array_intersect_key($data, array_fill_keys(['id', 'agreement_id', 'target_type', 'target_id', 'target_label', 'channel', 'description', 'authorization_reference', 'exclusivity_key', 'status', 'version', 'exposure_authorized', 'publication_status', 'agreement_version'], true));
    }
}
