<?php

namespace App\Modules\Media\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use Illuminate\Http\Request;

final class CommunicationReceiptResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['read' => (bool) $this->resource['read']];
    }
}
