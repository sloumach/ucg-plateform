<?php

namespace App\Modules\Teams\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use App\Modules\Teams\Domain\Models\ExternalOpponentSnapshot;
use Illuminate\Http\Request;

final class ExternalOpponentSnapshotResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ExternalOpponentSnapshot $snapshot */
        $snapshot = $this->resource;

        return $snapshot->projection();
    }
}
