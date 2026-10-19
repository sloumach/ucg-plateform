<?php

namespace App\Modules\Competition\Application\Services;

use App\Modules\Competition\Domain\Contracts\CompetitionRegistrationRepository;
use App\Modules\Competition\Domain\Contracts\ExternalCompetitionRepository;
use App\Modules\Competition\Domain\RegistrationDeadline;
use App\Modules\People\Application\Contracts\CompetitionRegistrationPeople;
use App\Modules\Tenancy\Application\Contracts\TenantExecutionContext;
use App\Modules\Tenancy\Application\Contracts\TenantNotificationRecipientDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantScheduledTask;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class ProcessCompetitionRegistrationReminders implements TenantScheduledTask
{
    public function __construct(private CompetitionRegistrationRepository $records, private ExternalCompetitionRepository $competitions, private CompetitionRegistrationPeople $people, private TenantNotificationRecipientDirectory $recipients, private ConnectionInterface $database, private LoggerInterface $logger) {}

    public function run(TenantExecutionContext $context, string $runId): void
    {
        $now = CarbonImmutable::now();
        $deadline = microtime(true) + 45;
        foreach ($this->records->sweep($context->organizationId, $now->addDays(7)) as $candidate) {
            if (microtime(true) >= $deadline) {
                break;
            }
            try {
                $this->database->transaction(function () use ($context, $runId, $candidate, $now): void {
                    $parent = $this->competitions->find($context->organizationId, $candidate->competition_id, true);
                    $revision = $this->records->latest($context->organizationId, $parent->id);
                    if ($parent->status !== 'active' || $revision === null || $revision->id !== $candidate->id) {
                        return;
                    }
                    $ids = array_values(array_unique(array_filter(array_column($revision->payload['items'], 'responsible_person_id'))));
                    $accounts = $this->people->accounts($context->organizationId, $ids);
                    $eligible = [];
                    foreach ($revision->payload['items'] as $item) {
                        $stage = RegistrationDeadline::stage($item, $now);
                        $account = $accounts[$item['responsible_person_id'] ?? ''] ?? null;
                        if ($stage === null || $account === null) {
                            continue;
                        }
                        $eligible[$account] ??= $this->recipients->email($context->organizationId, $account, $context->requestId, [...CompetitionRegistrationService::PERMISSIONS, 'sports.registrations.write']) !== null;
                        if (! $eligible[$account]) {
                            continue;
                        }
                        $this->records->remind(['id' => (string) Str::uuid(), 'organization_id' => $context->organizationId, 'competition_id' => $parent->id, 'revision_id' => $revision->id, 'item_id' => $item['id'], 'recipient_id' => $account,
                            'occurrence' => RegistrationDeadline::occurrence($parent->id, $item, $account, $stage), 'stage' => $stage, 'payload' => ['title' => $item['title'], 'due_at' => $item['due_at'], 'responsible_person_id' => $item['responsible_person_id']], 'run_id' => $runId]);
                    }
                    $this->records->checked($revision);
                });
            } catch (Throwable $e) {
                $this->logger->warning('competition_registration.reminder_failed', ['organization_id' => $context->organizationId, 'competition_id' => $candidate->competition_id, 'run_id' => $runId, 'exception_class' => $e::class]);
                throw $e;
            }
        }
    }
}
