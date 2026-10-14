<?php

namespace App\Modules\Contracts\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Contracts\Domain\Contracts\ContractGovernanceRepository;
use App\Modules\Contracts\Domain\Models\ContractAmendmentApproval;
use App\Modules\Contracts\Domain\Models\ContractDocument;
use App\Modules\Contracts\Domain\Models\ContractDocumentVersion;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;

final readonly class ContractGovernance
{
    public function __construct(private ContractGovernanceRepository $records, private TenantAuthorization $authorization) {}

    public function documentAccess(TenantContext $context, string $contractId): void
    {
        if ($this->records->hasFinancialTerms($context->organizationId, $contractId)) {
            $this->authorization->authorize($context, 'financial.read');
        }
    }

    public function approveContract(TenantContext $context, ContractRecord $contract): void
    {
        $terms = $this->records->terms($context->organizationId, $contract->id);
        if ($terms !== null) {
            $this->authorization->authorize($context, 'financial.read');
            $this->authorization->authorize($context, 'financial.validate');
            if ($terms->prepared_by === $context->actorUserId) {
                $this->conflict('self_approval', 'CONTRACT_SELF_APPROVAL_FORBIDDEN');
            }
        }
    }

    public function documentWrite(TenantContext $context, string $contractId): void
    {
        if ($this->records->hasFinancialTerms($context->organizationId, $contractId)) {
            $this->authorization->authorize($context, 'financial.read');
            $this->authorization->authorize($context, 'financial.write');
        }
    }

    public function signature(TenantContext $context, ContractRecord $contract, ?ContractDocument $document = null): ?ContractAmendmentApproval
    {
        $this->documentAccess($context, $contract->id);
        $financial = $this->records->terms($context->organizationId, $contract->id);
        $preparers = [$contract->prepared_by, $financial?->prepared_by];
        $approver = $contract->approved_by;
        $approval = null;
        if ($document?->kind === 'amendment') {
            $approval = $this->currentApproval($context, $contract, $document);
            $draft = $this->records->latestDraft($context->organizationId, $contract->id, $document->id);
            $terms = $this->records->terms($context->organizationId, $contract->id, $document->id);
            $preparers = [$document->created_by, $draft?->created_by, $terms?->prepared_by];
            $approver = $approval->approved_by;
        }
        if ($contract->signature_separation_required && in_array($context->actorUserId, [...$preparers, $approver], true)) {
            $this->conflict('signature_separation', 'CONTRACT_SIGNATURE_SEPARATION_REQUIRED');
        }

        return $approval;
    }

    public function currentApproval(TenantContext $context, ContractRecord $contract, ContractDocument $document): ContractAmendmentApproval
    {
        $approval = $this->records->latestApproval($context->organizationId, $contract->id, $document->id);
        $draft = $this->records->latestDraft($context->organizationId, $contract->id, $document->id);
        $terms = $this->records->terms($context->organizationId, $contract->id, $document->id);
        if ($approval === null || $draft === null || $approval->document_version_id !== $draft->id || $approval->financial_version !== $terms?->version) {
            $this->conflict('amendment_approval', 'CONTRACT_AMENDMENT_APPROVAL_REQUIRED');
        }

        return $approval;
    }

    public function bindCopy(ContractDocumentVersion $version, ContractAmendmentApproval $approval): void
    {
        $this->records->bindCopy($version, $approval);
    }

    public function assertCopy(TenantContext $context, string $versionId, ContractAmendmentApproval $approval): void
    {
        if ($this->records->copyApproval($context->organizationId, $versionId) !== $approval->id) {
            throw new DomainConflictException(__('contract_documents.approval_copy'), 'CONTRACT_AMENDMENT_COPY_STALE');
        }
    }

    private function conflict(string $key, string $code): never
    {
        throw new DomainConflictException(__('managed_contracts.'.$key), $code);
    }
}
