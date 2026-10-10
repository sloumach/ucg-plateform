<?php

namespace Tests\Fixtures\Jobs;

use App\Modules\Tenancy\Application\Contracts\TenantCache;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Contracts\TenantJob;
use App\Support\Queue\JobContext;
use Illuminate\Auth\Access\AuthorizationException;
use RuntimeException;

final class TenantContextProbe extends TenantJob
{
    /**
     * Create a new class instance.
     */
    public function __construct(JobContext $context, public readonly string $value = 'done',
        public readonly bool $failOnce = false, public readonly bool $requiresAdministration = false)
    {
        parent::__construct($context);
    }

    public function handle(TenantContext $context, TenantCache $cache): void
    {
        if ($this->requiresAdministration && ! $context->canAdminister()) {
            throw new AuthorizationException;
        }
        if ($this->failOnce && $cache->get($context, 'probe:attempt') === null) {
            $cache->put($context, 'probe:attempt', true, 60);
            throw new RuntimeException('Controlled transient failure.');
        }
        $cache->put($context, 'probe:123', $this->value, 60);
    }
}
