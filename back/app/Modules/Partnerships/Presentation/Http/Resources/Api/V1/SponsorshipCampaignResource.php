<?php

namespace App\Modules\Partnerships\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use Illuminate\Http\Request;

final class SponsorshipCampaignResource extends ApiResource
{
    /**
     * @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string,mixed> $data */
        $data = $this->resource;

        return array_intersect_key($data, array_fill_keys(['id', 'agreement_id', 'title', 'starts_on', 'ends_on', 'responsible_user_id', 'responsible_label', 'status', 'version', 'publication_status'], true));
    }
}
