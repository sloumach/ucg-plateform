<?php

namespace App\Modules\Contracts\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Contracts\Application\Validation\ContractGovernanceInput;
use App\Modules\Contracts\Application\Validation\ContractInput;
use App\Modules\Contracts\Domain\ContractLifecycle;
use App\Modules\Contracts\Domain\Contracts\ContractDocumentRepository;
use App\Modules\Contracts\Domain\Contracts\ContractGovernanceRepository;
use App\Modules\Contracts\Domain\Contracts\ContractRepository;
use App\Modules\Contracts\Domain\Models\ContractFinancialTerms;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class ContractFinancialService
{
    public function __construct(private ContractGovernanceRepository $records, private ContractRepository $contracts,
        private ContractDocumentRepository $documents, private TenantAuthorization $authorization, private TenantBusinessGuard $guard,
        private TenantAuditJournal $audit, private Factory $validator, private ConnectionInterface $database) {}

    public function read(TenantContext $context, string $contractId, ?string $documentId = null): ?ContractFinancialTerms
    {
        $this->authorize($context);
        $this->contracts->find($context->organizationId, $contractId);
        if ($documentId !== null) {
            $this->documents->find($context->organizationId, $contractId, $documentId);
        }

        return $this->records->terms($context->organizationId, $contractId, $documentId);
    }

    /** @param array<string, mixed> $input
     * @return array{terms: ContractFinancialTerms, contract_version: string}
     */
    public function save(TenantContext $context, string $contractId, array $input, ?string $documentId = null, ?string $ipAddress = null): array
    {
        $this->authorize($context, true);
        $values = ContractInput::validate($this->validator, $input, ContractGovernanceInput::financialRules());
        if ($values['terms']['remuneration'] === null && ($values['terms']['remuneration_period'] ?? null) !== null) {
            throw ValidationException::withMessages(['terms.remuneration_period' => __('managed_contracts.financial_period')]);
        }

        return $this->database->transaction(function () use ($context, $contractId, $values, $documentId, $ipAddress): array {
            $this->guard->lock($context);
            $this->authorize($context, true);
            $contract = $this->contracts->find($context->organizationId, $contractId, true);
            if ($contract->version !== $values['contract_version']) {
                throw new DomainConflictException(__('managed_contracts.stale'), 'CONTRACT_VERSION_STALE');
            }
            if ($contract->status === 'archived' || ($documentId === null && ! ContractLifecycle::editable($contract->status))) {
                throw new DomainConflictException(__('managed_contracts.frozen'), 'CONTRACT_TERMS_FROZEN');
            }
            if ($documentId !== null) {
                $document = $this->documents->find($context->organizationId, $contractId, $documentId);
                if ($document->kind !== 'amendment' || $document->signature !== null) {
                    throw new DomainConflictException(__('managed_contracts.frozen'), 'CONTRACT_TERMS_FROZEN');
                }
            }
            $previous = $this->records->terms($context->organizationId, $contractId, $documentId);
            if ($previous?->version !== $values['expected_version']) {
                throw new DomainConflictException(__('managed_contracts.stale'), 'CONTRACT_FINANCIAL_VERSION_STALE');
            }
            $terms = $this->records->saveTerms($previous, ['organization_id' => $context->organizationId, 'contract_id' => $contract->id,
                'document_id' => $documentId === null ? null : strtolower($documentId), 'scope_id' => strtolower($documentId ?? $contractId),
                'terms' => array_replace(array_fill_keys(ContractGovernanceInput::FIELDS, null), $values['terms']),
                'version' => (string) Str::uuid(), 'prepared_by' => $context->actorUserId]);
            $saved = $this->contracts->save($contract, ['version' => (string) Str::uuid(),
                ...($documentId === null ? ['prepared_by' => $context->actorUserId] : [])]);
            $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId, AuditAction::DomainChanged,
                AuditTarget::Organization, $context->organizationId, AuditReason::Domain, $context->requestId, $ipAddress, [],
                ['contract_id' => $saved->id, 'contract_status' => $saved->status, 'version' => $saved->version,
                    'contract_governance_action' => 'financial_terms_saved', ...($documentId === null ? [] : ['contract_document_id' => strtolower($documentId)])]));

            return ['terms' => $terms, 'contract_version' => $saved->version];
        });
    }

    private function authorize(TenantContext $context, bool $write = false): void
    {
        $this->authorization->authorize($context, 'administrative.read');
        $this->authorization->authorize($context, 'financial.read');
        if ($write) {
            $this->authorization->authorize($context, 'administrative.write');
            $this->authorization->authorize($context, 'financial.write');
        }
    }
}
