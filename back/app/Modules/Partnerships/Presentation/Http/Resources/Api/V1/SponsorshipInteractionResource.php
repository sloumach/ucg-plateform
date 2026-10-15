<?php

namespace App\Modules\Partnerships\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use App\Modules\Partnerships\Domain\Models\SponsorshipInteraction;
use Illuminate\Http\Request;

final class SponsorshipInteractionResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SponsorshipInteraction $record */
        $record = $this->resource;

        return ['id' => $record->id,
            'sponsor_id' => $record->sponsor_id,
            'contact_id' => $record->contact_id,
            'opportunity_id' => $record->opportunity_id,
            'channel' => $record->channel,
            'summary' => $record->summary,
            'occurred_at' => $record->occurred_at->toIso8601String(),
            'created_by' => $record->created_by,
            'created_at' => $record->created_at->toIso8601String(), 'publication_status' => 'private'];
    }
}
