<?php

namespace App\Modules\Contracts\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Contracts\Application\Validation\ContractDocumentInput;
use App\Modules\Contracts\Application\Validation\ContractGovernanceInput;
use App\Modules\Contracts\Application\Validation\ContractInput;
use App\Modules\Contracts\Domain\Contracts\ContractDocumentRepository;
use App\Modules\Contracts\Domain\Contracts\ContractGovernanceRepository;
use App\Modules\Contracts\Domain\Contracts\ContractRepository;
use App\Modules\Contracts\Domain\Models\ContractAmendmentApproval;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

final readonly class ContractAmendmentApprovalService
{
    public function __construct(private ContractGovernanceRepository $records, private ContractRepository $contracts,
        private ContractDocumentRepository $documents, private ContractGovernance $governance, private TenantAuthorization $authorization,
        private TenantBusinessGuard $guard, private TenantAuditJournal $audit, private Factory $validator, private ConnectionInterface $database) {}

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, ContractAmendmentApproval>
     */
    public function history(TenantContext $context, string $contractId, string $documentId, array $filters): LengthAwarePaginator
    {
        $this->authorization->authorize($context, 'administrative.read');
        $this->contracts->find($context->organizationId, $contractId);
        $this->governance->documentAccess($context, $contractId);
        $this->documents->find($context->organizationId, $contractId, $documentId);
        $values = ContractInput::validate($this->validator, $filters, ContractInput::pageRules(true));

        return $this->records->approvals($context->organizationId, $contractId, $documentId, $values);
    }

    /** @param array<string, mixed> $input
     * @return array{approval: ContractAmendmentApproval, contract_version: string}
     */
    public function approve(TenantContext $context, string $contractId, string $documentId, array $input, ?string $ipAddress = null): array
    {
        $this->authorize($context);
        $values = ContractInput::validate($this->validator, $input, ContractGovernanceInput::approvalRules());

        return $this->database->transaction(function () use ($context, $contractId, $documentId, $values, $ipAddress): array {
            $this->guard->lock($context);
            $this->authorize($context);
            $contract = $this->contracts->find($context->organizationId, $contractId, true);
            $this->governance->documentAccess($context, $contractId);
            if ($contract->version !== $values['contract_version']) {
                $this->conflict('stale', 'CONTRACT_VERSION_STALE');
            }
            $document = $this->documents->find($context->organizationId, $contractId, $documentId);
            if ($document->kind !== 'amendment' || $document->signature !== null || ! in_array($contract->status, ContractDocumentInput::SIGNED_STATES, true)) {
                $this->conflict('frozen', 'CONTRACT_TERMS_FROZEN');
            }
            $draft = $this->records->latestDraft($context->organizationId, $contractId, $documentId);
            if ($draft === null || $draft->id !== strtolower($values['document_version_id'])) {
                $this->conflict('amendment_draft', 'CONTRACT_AMENDMENT_DRAFT_REQUIRED');
            }
            $terms = $this->records->terms($context->organizationId, $contractId, $documentId);
            if ($this->records->hasFinancialTerms($context->organizationId, $contractId)) {
                $this->authorization->authorize($context, 'financial.read');
                $this->authorization->authorize($context, 'financial.validate');
            }
            if (in_array($context->actorUserId, [$document->created_by, $draft->created_by, $terms?->prepared_by], true)) {
                $this->conflict('self_approval', 'CONTRACT_SELF_APPROVAL_FORBIDDEN');
            }
            $previous = $this->records->latestApproval($context->organizationId, $contractId, $documentId);
            if ($previous !== null && $previous->document_version_id === $draft->id && $previous->financial_version === $terms?->version) {
                $this->conflict('already_approved', 'CONTRACT_AMENDMENT_ALREADY_APPROVED');
            }
            if (($previous->number ?? 0) >= ContractInput::MAX_TRANSITIONS) {
                $this->conflict('transition_limit', 'CONTRACT_TRANSITION_LIMIT');
            }
            $approval = $this->records->approve(['organization_id' => $context->organizationId, 'contract_id' => $contract->id,
                'document_id' => $document->id, 'document_version_id' => $draft->id, 'financial_version' => $terms?->version,
                'number' => ($previous->number ?? 0) + 1, 'approved_by' => $context->actorUserId, 'created_at' => CarbonImmutable::now()]);
            $saved = $this->contracts->save($contract, ['version' => (string) Str::uuid()]);
            $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId, AuditAction::DomainChanged,
                AuditTarget::Organization, $context->organizationId, AuditReason::Domain, $context->requestId, $ipAddress, [],
                ['contract_id' => $saved->id, 'contract_document_id' => $document->id, 'contract_document_version_id' => $draft->id,
                    'version' => $saved->version, 'contract_governance_action' => 'amendment_approved']));

            return ['approval' => $approval, 'contract_version' => $saved->version];
        });
    }

    private function authorize(TenantContext $context): void
    {
        foreach (['administrative.read', 'administrative.write', 'administrative.validate'] as $permission) {
            $this->authorization->authorize($context, $permission);
        }
    }

    private function conflict(string $key, string $code): never
    {
        throw new DomainConflictException(__('managed_contracts.'.$key), $code);
    }
}
