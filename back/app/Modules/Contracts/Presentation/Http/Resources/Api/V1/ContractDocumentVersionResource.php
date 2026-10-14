<?php

namespace App\Modules\Contracts\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use App\Modules\Contracts\Domain\Models\ContractDocumentVersion;
use Illuminate\Http\Request;

final class ContractDocumentVersionResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ContractDocumentVersion $version */
        $version = $this->resource;

        return ['id' => $version->id, 'number' => $version->number, 'purpose' => $version->purpose,
            'filename' => $version->filename, 'sha256' => $version->sha256, 'bytes' => $version->bytes,
            'created_by' => $version->created_by, 'created_at' => $version->created_at->toIso8601String(),
            'scan_status' => 'pending', 'download_available' => false, 'publication_status' => 'private'];
    }
}
