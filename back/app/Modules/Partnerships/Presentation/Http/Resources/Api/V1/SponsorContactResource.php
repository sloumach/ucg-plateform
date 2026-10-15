<?php

namespace App\Modules\Partnerships\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use App\Modules\Partnerships\Domain\Models\SponsorContact;
use Illuminate\Http\Request;

final class SponsorContactResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SponsorContact $record */
        $record = $this->resource;

        return ['id' => $record->id,
            'sponsor_id' => $record->sponsor_id,
            'name' => $record->name,
            'position' => $record->position,
            'email' => $record->email,
            'phone' => $record->phone,
            'status' => $record->status,
            'version' => $record->version, 'publication_status' => 'private'];
    }
}
