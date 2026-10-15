<?php

namespace App\Modules\Contracts\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Contracts\Application\Validation\ContractInput;
use App\Modules\Contracts\Domain\ContractReminderSchedule;
use App\Modules\Contracts\Domain\Contracts\ContractReminderRepository;
use App\Modules\Contracts\Domain\Contracts\ContractRepository;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\Contracts\Domain\Models\ContractReminderDelivery;
use App\Modules\Contracts\Domain\Models\ContractReminderPlan;
use App\Modules\People\Application\Contracts\LinkedPersonAccountDirectory;
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
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

final readonly class ContractReminderService
{
    public function __construct(private ContractReminderRepository $reminders, private ContractRepository $contracts,
        private LinkedPersonAccountDirectory $people, private TenantAuthorization $authorization, private TenantBusinessGuard $guard,
        private TenantAuditJournal $audit, private Factory $validator, private ConnectionInterface $database) {}

    public function read(TenantContext $context, string $contractId): ?ContractReminderPlan
    {
        $this->access($context, $contractId);

        return $this->reminders->plan($context->organizationId, $contractId);
    }

    /** @param array<string,mixed> $input
     * @return array{plan:ContractReminderPlan, contract_version:string}
     */
    public function save(TenantContext $context, string $contractId, array $input, ?string $ipAddress = null): array
    {
        $values = ContractInput::validate($this->validator, $input, ContractReminderSchedule::rules(), ContractReminderSchedule::messages());

        return $this->database->transaction(function () use ($context, $contractId, $values, $ipAddress): array {
            $this->guard->lock($context);
            $contract = $this->access($context, $contractId, true);
            if ($contract->status === 'archived') {
                throw new DomainConflictException(__('managed_contracts.reminder_archived'), 'CONTRACT_ARCHIVED');
            }
            if ($contract->version !== $values['contract_version']) {
                throw new DomainConflictException(__('managed_contracts.stale'), 'CONTRACT_VERSION_STALE');
            }
            $plan = $this->reminders->plan($context->organizationId, $contractId, true);
            if ($plan?->version !== $values['expected_version']) {
                throw new DomainConflictException(__('managed_contracts.stale'), 'CONTRACT_REMINDER_VERSION_STALE');
            }
            $offsets = array_map('intval', $values['offsets']);
            sort($offsets);
            $plan = $this->reminders->savePlan($plan, ['organization_id' => $context->organizationId, 'contract_id' => $contract->id,
                'enabled' => $values['enabled'], 'notice_on' => $values['notice_on'], 'renewal_on' => $values['renewal_on'],
                'offsets' => $offsets, 'channels' => $values['channels'], 'version' => (string) Str::uuid(),
                'updated_by' => $context->actorUserId, 'checked_at' => null]);
            $contract = $this->contracts->save($contract, ['version' => (string) Str::uuid()]);
            $this->audit($context, $contract->id, 'configured', $ipAddress);

            return ['plan' => $plan, 'contract_version' => $contract->version];
        });
    }

    /** @param array<string,mixed> $filters
     * @return LengthAwarePaginator<int,ContractReminderDelivery>
     */
    public function history(TenantContext $context, string $contractId, array $filters): LengthAwarePaginator
    {
        $this->access($context, $contractId);

        return $this->reminders->history($context->organizationId, $contractId, $filters);
    }

    /** @param array<string,mixed> $filters
     * @return LengthAwarePaginator<int,ContractReminderDelivery>
     */
    public function inbox(TenantContext $context, array $filters): LengthAwarePaginator
    {
        $this->authorization->authorize($context, 'administrative.read');

        return $this->reminders->inbox($context->organizationId, $context->actorUserId,
            $this->people->personId($context->organizationId, $context->actorUserId),
            $this->authorization->allows($context, 'financial.read'), $filters);
    }

    /** @param array<string,mixed> $input */
    public function resolve(TenantContext $context, string $contractId, string $deliveryId, array $input, ?string $ipAddress = null): ContractReminderDelivery
    {
        $values = ContractInput::validate($this->validator, $input, self::resolutionRules(), ContractReminderSchedule::messages());

        return $this->database->transaction(function () use ($context, $contractId, $deliveryId, $values, $ipAddress): ContractReminderDelivery {
            $this->guard->lock($context);
            $contract = $this->access($context, $contractId, true);
            $delivery = $this->reminders->delivery($context->organizationId, $deliveryId, true);
            if ($delivery->contract_id !== $contract->id) {
                throw new ModelNotFoundException;
            }
            if ($delivery->version !== $values['expected_version'] || $delivery->email_status !== 'uncertain' || $delivery->attempts >= 20) {
                throw new DomainConflictException(__('managed_contracts.stale'), 'CONTRACT_REMINDER_VERSION_STALE');
            }
            $retry = $values['action'] === 'retry';
            $delivery = $this->reminders->updateDelivery($delivery, ['email_status' => $retry ? 'pending' : 'sent',
                'reason' => null, 'email_sent_at' => $retry ? null : now()]);
            $this->audit($context, $contract->id, $retry ? 'retry_authorized' : 'delivery_confirmed', $ipAddress, $delivery->id);

            return $delivery;
        });
    }

    /** @return array<string,array<mixed>> */
    public static function resolutionRules(): array
    {
        return ['expected_version' => ['required', 'uuid'], 'action' => ['required', 'in:retry,confirm_sent'],
            'confirm_possible_duplicate' => ['exclude_unless:action,retry', 'required', 'accepted']];
    }

    private function access(TenantContext $context, string $id, bool $write = false): ContractRecord
    {
        $this->authorization->authorize($context, 'administrative.read');
        if ($write) {
            $this->authorization->authorize($context, 'administrative.write');
        }
        $contract = $this->contracts->find($context->organizationId, $id, $write);
        if ($contract->financial_documents_restricted) {
            $this->authorization->authorize($context, 'financial.read');
        }

        return $contract;
    }

    private function audit(TenantContext $context, string $contractId, string $action, ?string $ipAddress, ?string $reminderId = null): void
    {
        $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId, AuditAction::DomainChanged,
            AuditTarget::Organization, $context->organizationId, AuditReason::Domain, $context->requestId, $ipAddress, [],
            ['contract_id' => $contractId, 'contract_reminder_action' => $action, ...($reminderId === null ? [] : ['contract_reminder_id' => $reminderId])]));
    }
}
