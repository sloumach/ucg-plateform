<?php

namespace App\Modules\Contracts\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use App\Modules\Contracts\Domain\Models\ContractDocument;
use Illuminate\Http\Request;

final class ContractDocumentResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ContractDocument $document */
        $document = $this->resource;
        $signature = $document->signature;
        $approval = $document->latestApproval;
        $draft = $document->latestDraft;
        $financial = $document->financialTerms;
        $approved = $approval !== null && $draft !== null && $approval->document_version_id === $draft->id && $approval->financial_version === $financial?->version;

        return ['id' => $document->id, 'reference' => $document->reference, 'kind' => $document->kind,
            'title' => $document->title, 'original_document_id' => $document->original_document_id,
            'effective_on' => $document->effective_on?->format('Y-m-d'), 'created_by' => $document->created_by,
            'created_at' => $document->created_at->toIso8601String(), 'versions_count' => $document->versions_count,
            'latest_draft_id' => $draft?->id, 'latest_prepared_by' => $draft?->created_by,
            'financial_terms_present' => $financial !== null, 'financial_prepared_by' => $financial?->prepared_by,
            'approval' => $approved ? ['id' => $approval->id, 'document_version_id' => $approval->document_version_id,
                'approved_by' => $approval->approved_by, 'approved_at' => $approval->created_at->toIso8601String()] : null,
            'signed_version' => $signature === null ? null : ['id' => $signature->document_version_id, 'number' => $signature->version_number,
                'sha256' => $signature->sha256, 'signed_on' => $signature->signed_on->format('Y-m-d'),
                'recorded_by' => $signature->recorded_by, 'recorded_at' => $signature->created_at->toIso8601String()],
            'signature_evidence_status' => $signature === null ? 'not_declared' : 'declared_external',
            'publication_status' => 'private'];
    }
}
