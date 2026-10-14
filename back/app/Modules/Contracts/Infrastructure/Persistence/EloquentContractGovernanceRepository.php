<?php

namespace App\Modules\Contracts\Infrastructure\Persistence;

use App\Modules\Contracts\Domain\Contracts\ContractGovernanceRepository;
use App\Modules\Contracts\Domain\Models\ContractAmendmentApproval;
use App\Modules\Contracts\Domain\Models\ContractApprovedCopy;
use App\Modules\Contracts\Domain\Models\ContractDocumentVersion;
use App\Modules\Contracts\Domain\Models\ContractFinancialTerms;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class EloquentContractGovernanceRepository implements ContractGovernanceRepository
{
    public function terms(string $organizationId, string $contractId, ?string $documentId = null): ?ContractFinancialTerms
    {
        return ContractFinancialTerms::query()->where('organization_id', strtolower($organizationId))
            ->where('contract_id', strtolower($contractId))->where('scope_id', strtolower($documentId ?? $contractId))->first();
    }

    public function hasFinancialTerms(string $organizationId, string $contractId): bool
    {
        return ContractFinancialTerms::query()->where('organization_id', strtolower($organizationId))->where('contract_id', strtolower($contractId))->exists();
    }

    public function saveTerms(?ContractFinancialTerms $terms, array $attributes): ContractFinancialTerms
    {
        $terms ??= new ContractFinancialTerms;
        $terms->forceFill($attributes)->save();

        return $terms;
    }

    public function latestDraft(string $organizationId, string $contractId, string $documentId): ?ContractDocumentVersion
    {
        return ContractDocumentVersion::query()->where('organization_id', strtolower($organizationId))->where('contract_id', strtolower($contractId))
            ->where('document_id', strtolower($documentId))->where('purpose', 'draft')->orderByDesc('number')->first();
    }

    public function latestApproval(string $organizationId, string $contractId, string $documentId): ?ContractAmendmentApproval
    {
        return ContractAmendmentApproval::query()->where('organization_id', strtolower($organizationId))->where('contract_id', strtolower($contractId))
            ->where('document_id', strtolower($documentId))->orderByDesc('number')->first();
    }

    public function approve(array $attributes): ContractAmendmentApproval
    {
        $approval = new ContractAmendmentApproval;
        $approval->forceFill($attributes)->save();

        return $approval;
    }

    public function approvals(string $organizationId, string $contractId, string $documentId, array $filters): LengthAwarePaginator
    {
        return ContractAmendmentApproval::query()->where('organization_id', strtolower($organizationId))->where('contract_id', strtolower($contractId))
            ->where('document_id', strtolower($documentId))->orderByDesc('number')->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    public function bindCopy(ContractDocumentVersion $version, ContractAmendmentApproval $approval): void
    {
        (new ContractApprovedCopy)->forceFill(['organization_id' => $version->organization_id, 'contract_id' => $version->contract_id,
            'document_id' => $version->document_id, 'document_version_id' => $version->id, 'approval_id' => $approval->id])->save();
    }

    public function copyApproval(string $organizationId, string $versionId): ?string
    {
        return ContractApprovedCopy::query()->where('organization_id', strtolower($organizationId))->where('document_version_id', strtolower($versionId))->value('approval_id');
    }
}
