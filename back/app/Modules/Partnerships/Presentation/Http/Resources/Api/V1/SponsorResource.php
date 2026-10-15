<?php

namespace App\Modules\Partnerships\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use App\Modules\Partnerships\Domain\Models\Sponsor;
use Illuminate\Http\Request;

final class SponsorResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Sponsor $record */
        $record = $this->resource;

        return ['id' => $record->id,
            'name' => $record->name,
            'industry' => $record->industry,
            'status' => $record->status,
            'version' => $record->version, 'publication_status' => 'private'];
    }
}
