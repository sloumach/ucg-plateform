<?php

namespace App\Modules\Partnerships\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use App\Modules\Partnerships\Domain\Models\SponsorshipOpportunity;
use Illuminate\Http\Request;

final class SponsorshipOpportunityResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SponsorshipOpportunity $record */
        $record = $this->resource;

        return ['id' => $record->id,
            'sponsor_id' => $record->sponsor_id,
            'title' => $record->title,
            'description' => $record->description,
            'status' => $record->status,
            'version' => $record->version, 'publication_status' => 'private'];
    }
}
