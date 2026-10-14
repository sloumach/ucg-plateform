<?php

namespace App\Modules\Contracts\Domain\Contracts;

use App\Modules\Contracts\Domain\Models\ContractAmendmentApproval;
use App\Modules\Contracts\Domain\Models\ContractDocumentVersion;
use App\Modules\Contracts\Domain\Models\ContractFinancialTerms;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ContractGovernanceRepository
{
    public function terms(string $organizationId, string $contractId, ?string $documentId = null): ?ContractFinancialTerms;

    public function hasFinancialTerms(string $organizationId, string $contractId): bool;

    /** @param array<string, mixed> $attributes */
    public function saveTerms(?ContractFinancialTerms $terms, array $attributes): ContractFinancialTerms;

    public function latestDraft(string $organizationId, string $contractId, string $documentId): ?ContractDocumentVersion;

    public function latestApproval(string $organizationId, string $contractId, string $documentId): ?ContractAmendmentApproval;

    /** @param array<string, mixed> $attributes */
    public function approve(array $attributes): ContractAmendmentApproval;

    public function bindCopy(ContractDocumentVersion $version, ContractAmendmentApproval $approval): void;

    public function copyApproval(string $organizationId, string $versionId): ?string;

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, ContractAmendmentApproval>
     */
    public function approvals(string $organizationId, string $contractId, string $documentId, array $filters): LengthAwarePaginator;
}
