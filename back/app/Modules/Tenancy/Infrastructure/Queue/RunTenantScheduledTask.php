<?php

namespace App\Modules\Tenancy\Infrastructure\Queue;

use App\Modules\Tenancy\Application\Contracts\TenantExecutionContext;
use App\Modules\Tenancy\Application\Contracts\TenantJob;
use App\Modules\Tenancy\Application\Services\TenantScheduleRegistry;
use App\Support\Cache\TenantCacheStore;
use App\Support\Queue\JobContext;
use App\Support\Storage\TenantResourceNamespace;
use LogicException;
use Psr\Log\LoggerInterface;

final class RunTenantScheduledTask extends TenantJob
{
    private const int LOCK_SECONDS = 90;

    private const int COMPLETION_SECONDS = 86400;

    public function __construct(JobContext $context, public readonly string $task, public readonly string $runId)
    {
        parent::__construct($context);
        $this->tries = 10;
    }

    public function handle(TenantExecutionContext $context, TenantScheduleRegistry $registry,
        TenantCacheStore $store, LoggerInterface $logger): void
    {
        $task = $registry->task($this->task);
        if ($this->runId !== $context->requestId) {
            throw new LogicException('Scheduled run identity does not match its context.');
        }
        $key = TenantResourceNamespace::key($context->organizationId, 'cache', 'schedule:'.$this->task.':'.$this->runId);
        $lock = $store->lock(TenantResourceNamespace::key($context->organizationId, 'lock', 'schedule:'.$this->task), self::LOCK_SECONDS);
        if (! $lock->get()) {
            $logger->info('tenant.schedule.busy', ['task' => $this->task]);
            $this->release(5);

            return;
        }
        try {
            if ($store->repository()->get($key) === true) {
                $logger->info('tenant.schedule.duplicate_skipped', ['task' => $this->task]);

                return;
            }
            $task->run($context, $this->runId);
            if (! $store->repository()->put($key, true, self::COMPLETION_SECONDS)) {
                throw new LogicException('Unable to record scheduled task completion.');
            }
            $logger->info('tenant.schedule.completed', ['task' => $this->task]);
        } finally {
            $lock->release();
        }
    }
}
