<?php

namespace App\Modules\Tenancy\Infrastructure\Resources;

use App\Exceptions\DomainConflictException;
use App\Modules\Tenancy\Application\Contracts\TenantCache;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Services\TenantResolver;
use App\Support\Cache\TenantCacheStore;
use App\Support\Storage\TenantResourceNamespace;
use Closure;
use InvalidArgumentException;
use LogicException;

final readonly class LaravelTenantCache implements TenantCache
{
    private const int MAX_CACHE_SECONDS = 86400;

    private const int MAX_LOCK_SECONDS = 300;

    public function __construct(private TenantCacheStore $store, private TenantResolver $resolver) {}

    public function get(TenantContext $context, string $key): mixed
    {
        $this->authorize($context);

        return $this->store->repository()->get(TenantResourceNamespace::key($context->organizationId, 'cache', $key));
    }

    public function put(TenantContext $context, string $key, mixed $value, int $seconds): void
    {
        $this->authorize($context);
        if ($seconds < 1 || $seconds > self::MAX_CACHE_SECONDS) {
            throw new InvalidArgumentException('Tenant cache lifetime must be between one second and one day.');
        }
        if (! $this->store->repository()->put(TenantResourceNamespace::key($context->organizationId, 'cache', $key), $value, $seconds)) {
            throw new LogicException('Unable to persist tenant cache.');
        }
    }

    public function forget(TenantContext $context, string $key): void
    {
        $this->authorize($context);
        $this->store->repository()->forget(TenantResourceNamespace::key($context->organizationId, 'cache', $key));
    }

    public function synchronized(TenantContext $context, string $key, int $leaseSeconds, Closure $operation): mixed
    {
        $this->authorize($context);
        if ($leaseSeconds < 1 || $leaseSeconds > self::MAX_LOCK_SECONDS) {
            throw new InvalidArgumentException('Tenant lock lease must be between one and 300 seconds.');
        }
        $lock = $this->store->lock(TenantResourceNamespace::key($context->organizationId, 'lock', $key), $leaseSeconds);
        if (! $lock->get()) {
            throw new DomainConflictException(__('tenancy.errors.resource_busy'), 'TENANT_RESOURCE_BUSY');
        }
        try {
            $this->authorize($context);

            return $operation();
        } finally {
            $lock->release();
        }
    }

    private function authorize(TenantContext $context): void
    {
        $this->resolver->resolve($context->organizationId, '', $context->actorUserId, $context->requestId);
    }
}
