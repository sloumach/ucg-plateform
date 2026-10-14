<?php

namespace App\Modules\Contracts\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use App\Modules\Contracts\Domain\DocumentSecurity;
use App\Modules\Contracts\Domain\Models\ContractDocumentVersion;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

final class ContractDocumentVersionResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ContractDocumentVersion $version */
        $version = $this->resource;
        $scan = $version->relationLoaded('latestScan') ? $version->latestScan : null;
        $now = CarbonImmutable::now();

        return ['id' => $version->id, 'number' => $version->number, 'purpose' => $version->purpose,
            'filename' => $version->filename, 'sha256' => $version->sha256, 'bytes' => $version->bytes,
            'created_by' => $version->created_by, 'created_at' => $version->created_at->toIso8601String(),
            'scan_status' => DocumentSecurity::state($scan, $now), 'scan_id' => $scan?->id,
            'scan_reason' => $scan?->invalidated_at !== null ? 'integrity' : $scan?->reason,
            'scan_finished_at' => $scan?->finished_at?->toIso8601String(),
            'download_available' => DocumentSecurity::downloadable($scan, $now), 'publication_status' => 'private'];
    }
}
