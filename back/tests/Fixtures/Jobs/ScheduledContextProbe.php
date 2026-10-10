<?php

namespace Tests\Fixtures\Jobs;

use App\Modules\Tenancy\Application\Contracts\TenantExecutionContext;
use App\Modules\Tenancy\Application\Contracts\TenantScheduledTask;
use App\Support\Cache\TenantCacheStore;
use App\Support\Storage\TenantResourceNamespace;
use RuntimeException;

final class ScheduledContextProbe implements TenantScheduledTask
{
    /**
     * Create a new class instance.
     */
    public function __construct(private readonly TenantCacheStore $store) {}

    public function run(TenantExecutionContext $context, string $runId): void
    {
        $cache = $this->store->repository();
        if ($cache->get(TenantResourceNamespace::key($context->organizationId, 'cache', 'schedule-probe:fail')) === true) {
            throw new RuntimeException('Controlled scheduled failure.');
        }
        $key = TenantResourceNamespace::key($context->organizationId, 'cache', 'schedule-probe:123');
        $cache->put($key, ['runs' => (($cache->get($key)['runs'] ?? 0) + 1),
            'tenant' => $context->organizationId, 'actor' => $context->actorContext?->actorUserId,
            'run' => $runId, 'timezone' => $context->timezone], 300);
    }
}
