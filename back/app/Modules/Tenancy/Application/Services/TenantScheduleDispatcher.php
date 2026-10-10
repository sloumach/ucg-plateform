<?php

namespace App\Modules\Tenancy\Application\Services;

use App\Modules\Tenancy\Application\Contracts\TenantScheduleQueue;
use App\Modules\Tenancy\Domain\Contracts\TenantWorkRepository;
use App\Support\Cache\TenantCacheStore;
use App\Support\Queue\JobContext;
use App\Support\Storage\TenantResourceNamespace;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final readonly class TenantScheduleDispatcher
{
    public const int PAGE_SIZE = 200;

    private const int CHECKPOINT_SECONDS = 86400;

    public function __construct(
        private TenantWorkRepository $organizations, private TenantScheduleQueue $queue,
        private TenantScheduleRegistry $registry, private LoggerInterface $logger,
        private TenantCacheStore $store,
    ) {}

    public function page(string $task, string $runId, ?string $after): void
    {
        $this->registry->task($task);
        if (! Str::isUuid($runId) || ($after !== null && ! Str::isUuid($after))) {
            throw new InvalidArgumentException('Scheduled dispatch requires valid run and cursor identifiers.');
        }
        $pageKey = 'tenant-schedule-page:'.hash('sha256', $task.'|'.$runId.'|'.$after);
        $lock = $this->store->lock($pageKey, 90);
        if (! $lock->get()) {
            throw new RuntimeException('This schedule page is already being distributed.');
        }
        try {
            $this->distribute($task, $runId, $after, $pageKey);
        } finally {
            $lock->release();
        }
    }

    private function distribute(string $task, string $runId, ?string $after, string $pageKey): void
    {
        $cache = $this->store->repository();
        if ($cache->get($pageKey.':done') === true) {
            return;
        }
        $ids = $this->organizations->activeIdsAfter($after, self::PAGE_SIZE + 1);
        $page = array_slice($ids, 0, self::PAGE_SIZE);
        $failures = 0;
        foreach ($page as $id) {
            try {
                $key = TenantResourceNamespace::key($id, 'cache', 'schedule-dispatched:'.$task.':'.$runId);
                if ($cache->get($key) === true) {
                    continue;
                }
                $this->queue->tenant(new JobContext($runId, $id), $task, $runId);
                if (! $cache->put($key, true, self::CHECKPOINT_SECONDS)) {
                    throw new RuntimeException('Unable to checkpoint scheduled tenant dispatch.');
                }
            } catch (Throwable $exception) {
                $failures++;
                $this->logger->error('tenant.schedule.dispatch_failed', ['tenant_id' => $id,
                    'request_id' => $runId, 'task' => $task, 'exception_type' => $exception::class]);
            }
        }
        if (count($ids) > self::PAGE_SIZE && $cache->get($pageKey.':next') !== true) {
            $cursor = end($page);
            if ($cursor === false) {
                throw new RuntimeException('A continuing schedule page requires a cursor.');
            }
            $this->queue->page($task, $runId, $cursor);
            if (! $cache->put($pageKey.':next', true, self::CHECKPOINT_SECONDS)) {
                throw new RuntimeException('Unable to checkpoint schedule continuation.');
            }
        }
        $this->logger->info('tenant.schedule.page_dispatched', ['request_id' => $runId,
            'task' => $task, 'tenants' => count($page), 'failures' => $failures]);
        if ($failures > 0) {
            // Retry the bounded page without holding back other tenants or later pages.
            throw new RuntimeException('Some tenant tasks could not be queued; retry the page.');
        }
        if (! $cache->put($pageKey.':done', true, self::CHECKPOINT_SECONDS)) {
            throw new RuntimeException('Unable to checkpoint schedule page completion.');
        }
    }
}
