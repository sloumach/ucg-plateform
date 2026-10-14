<?php

namespace App\Modules\Contracts\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use App\Modules\Contracts\Domain\Models\ContractDocumentScan;
use Illuminate\Http\Request;

final class ContractDocumentScanResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ContractDocumentScan $scan */
        $scan = $this->resource;

        return ['id' => $scan->id, 'attempt' => $scan->attempt, 'status' => $scan->status, 'reason' => $scan->reason,
            'policy' => $scan->policy, 'engine' => $scan->engine, 'requested_by' => $scan->requested_by,
            'requested_at' => $scan->requested_at->toIso8601String(), 'started_at' => $scan->started_at?->toIso8601String(),
            'finished_at' => $scan->finished_at?->toIso8601String(), 'invalidated_at' => $scan->invalidated_at?->toIso8601String()];
    }
}
