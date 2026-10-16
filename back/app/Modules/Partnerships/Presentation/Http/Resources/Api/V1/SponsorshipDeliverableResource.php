<?php

namespace App\Modules\Partnerships\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use Illuminate\Http\Request;

final class SponsorshipDeliverableResource extends ApiResource
{
    /**
     * @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string,mixed> $data */
        $data = $this->resource;

        return array_intersect_key($data, array_fill_keys(['id', 'sponsor_name', 'agreement_reference', 'campaign_title', 'agreement_id', 'sponsor_id', 'campaign_id', 'exposure_id', 'kind', 'title', 'responsible_user_id', 'responsible_label', 'due_at', 'status', 'version', 'prepared_by', 'proof_reference', 'delivered_at', 'delivered_by', 'validated_at', 'validated_by', 'validated_agreement_version', 'validated_exposure_version', 'validation_matches_agreement', 'exposure_authorized', 'overdue', 'delivered_late', 'publication_status', 'campaign_version', 'agreement_version'], true));
    }
}
