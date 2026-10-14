<?php

namespace App\Modules\Contracts\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use App\Modules\Contracts\Domain\Models\ContractAmendmentApproval;
use Illuminate\Http\Request;

final class ContractAmendmentApprovalResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ContractAmendmentApproval $approval */
        $approval = $this->resource;

        return ['id' => $approval->id, 'number' => $approval->number, 'document_version_id' => $approval->document_version_id,
            'approved_by' => $approval->approved_by, 'approved_at' => $approval->created_at->toIso8601String()];
    }
}
