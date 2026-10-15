<?php

namespace App\Modules\Contracts\Application\Services;

use App\Modules\Contracts\Domain\ContractReminderSchedule;
use App\Modules\Contracts\Domain\Contracts\ContractReminderMailer;
use App\Modules\Contracts\Domain\Contracts\ContractReminderRepository;
use App\Modules\Contracts\Domain\Contracts\ContractRepository;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\People\Application\Contracts\LinkedPersonAccountDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantExecutionContext;
use App\Modules\Tenancy\Application\Contracts\TenantNotificationRecipientDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantScheduledTask;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class ProcessContractReminders implements TenantScheduledTask
{
    public function __construct(private ContractReminderRepository $reminders, private ContractRepository $contracts,
        private LinkedPersonAccountDirectory $people, private TenantNotificationRecipientDirectory $recipients,
        private ContractReminderMailer $mailer, private ConnectionInterface $database, private LoggerInterface $logger) {}

    public function run(TenantExecutionContext $context, string $runId): void
    {
        foreach ($this->reminders->sweep($context->organizationId, 25) as $candidate) {
            $this->database->transaction(function () use ($context, $runId, $candidate): void {
                $contract = $this->contracts->find($context->organizationId, $candidate->contract_id, true);
                $plan = $this->reminders->plan($context->organizationId, $contract->id, true);
                if ($plan === null) {
                    return;
                }
                $account = $this->people->accountId($context->organizationId, $contract->responsible_person_id);
                $eligible = $account !== null && $this->email($context, $contract, $account) !== null;
                foreach (ContractReminderSchedule::occurrences($plan, $contract, $context->timezone) as $kind => $occurrence) {
                    $inApp = in_array('in_app', $plan->channels, true);
                    $email = in_array('email', $plan->channels, true);
                    $record = $this->reminders->record(['organization_id' => $context->organizationId, 'contract_id' => $contract->id,
                        'plan_id' => $plan->id, 'person_id' => $contract->responsible_person_id, 'recipient_id' => $account,
                        'occurrence' => hash('sha256', implode('|', [$contract->id, $kind, $occurrence['due_on'], $occurrence['offset_days'], $contract->responsible_person_id, $account ?? 'none'])),
                        'kind' => $kind, ...$occurrence, 'in_app_status' => $inApp ? ($eligible ? 'sent' : 'blocked') : 'not_requested',
                        'email_status' => $email ? ($eligible ? 'pending' : 'blocked') : 'not_requested',
                        'reason' => $eligible ? null : 'recipient_unavailable', 'attempts' => 0, 'version' => (string) Str::uuid(), 'run_id' => $runId]);
                    // Restored rights or a newly enabled channel can recover an unsent occurrence.
                    $changes = [];
                    if ($eligible && $inApp && in_array($record->in_app_status, ['blocked', 'not_requested'], true)) {
                        $changes['in_app_status'] = 'sent';
                    }
                    if ($eligible && $email && in_array($record->email_status, ['blocked', 'not_requested'], true)) {
                        $changes['email_status'] = 'pending';
                        $changes['reason'] = null;
                    }
                    if ($changes !== []) {
                        $this->reminders->updateDelivery($record, $changes);
                    }
                }
                $this->reminders->savePlan($plan, ['checked_at' => now()]);
            });
        }
        // SMTP is already off the HTTP path. Bound the batch to fit the tenant worker's 60s budget.
        foreach ($this->reminders->pendingEmails($context->organizationId, 3) as $candidate) {
            $this->deliverEmail($context, $candidate->id);
        }
    }

    private function deliverEmail(TenantExecutionContext $context, string $id): void
    {
        $prepared = $this->database->transaction(function () use ($context, $id): ?array {
            $snapshot = $this->reminders->delivery($context->organizationId, $id);
            $contract = $this->contracts->find($context->organizationId, $snapshot->contract_id, true);
            $plan = $this->reminders->plan($context->organizationId, $contract->id, true);
            $delivery = $this->reminders->delivery($context->organizationId, $id, true);
            if ($delivery->email_status === 'sending') {
                if ($delivery->email_started_at?->lte(now()->subMinutes(5))) {
                    $this->reminders->updateDelivery($delivery, ['email_status' => 'uncertain', 'reason' => 'delivery_uncertain']);
                }

                return null;
            }
            if ($delivery->email_status !== 'pending') {
                return null;
            }
            $expected = $plan === null ? [] : ContractReminderSchedule::occurrences($plan, $contract, $context->timezone);
            $current = $expected[$delivery->kind] ?? null;
            if ($plan === null || $current === null || $current['due_on'] !== $delivery->due_on->format('Y-m-d')
                || $current['offset_days'] !== $delivery->offset_days || ! in_array('email', $plan->channels, true)
                || $contract->responsible_person_id !== $delivery->person_id
                || $this->people->accountId($context->organizationId, $delivery->person_id) !== $delivery->recipient_id) {
                $this->reminders->updateDelivery($delivery, ['email_status' => 'blocked', 'reason' => 'obsolete']);

                return null;
            }
            $email = $delivery->recipient_id === null ? null : $this->email($context, $contract, $delivery->recipient_id);
            if ($email === null) {
                $this->reminders->updateDelivery($delivery, ['email_status' => 'blocked', 'reason' => 'recipient_unavailable']);

                return null;
            }
            if (! $this->mailer->available()) {
                $this->reminders->updateDelivery($delivery, ['reason' => 'smtp_unavailable']);

                return null;
            }
            $this->reminders->updateDelivery($delivery, ['email_status' => 'sending', 'reason' => null,
                'email_started_at' => now(), 'attempts' => $delivery->attempts + 1]);

            return [$delivery, $email];
        });
        if ($prepared === null) {
            return;
        }
        [$delivery, $email] = $prepared;
        try {
            $this->mailer->send($email, $delivery->occurrence);
        } catch (Throwable $exception) {
            $this->finish($context->organizationId, $id, 'uncertain');
            $this->logger->warning('contract_reminder.email_uncertain', ['organization_id' => $context->organizationId,
                'reminder_id' => $id, 'exception_class' => $exception::class]);

            return;
        }
        $this->finish($context->organizationId, $id, 'sent');
    }

    private function finish(string $organizationId, string $id, string $status): void
    {
        $this->database->transaction(function () use ($organizationId, $id, $status): void {
            $delivery = $this->reminders->delivery($organizationId, $id, true);
            if ($delivery->email_status === 'sending') {
                $this->reminders->updateDelivery($delivery, ['email_status' => $status,
                    'reason' => $status === 'uncertain' ? 'delivery_uncertain' : null,
                    'email_sent_at' => $status === 'sent' ? now() : null]);
            }
        });
    }

    private function email(TenantExecutionContext $context, ContractRecord $contract, int $account): ?string
    {
        return $this->recipients->email($context->organizationId, $account, $context->requestId,
            ['administrative.read', ...($contract->financial_documents_restricted ? ['financial.read'] : [])]);
    }
}
