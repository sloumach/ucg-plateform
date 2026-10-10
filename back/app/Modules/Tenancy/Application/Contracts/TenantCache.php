<?php

namespace App\Modules\Tenancy\Application\Contracts;

use Closure;

interface TenantCache
{
    public function get(TenantContext $context, string $key): mixed;

    public function put(TenantContext $context, string $key, mixed $value, int $seconds): void;

    public function forget(TenantContext $context, string $key): void;

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function synchronized(TenantContext $context, string $key, int $leaseSeconds, Closure $operation): mixed;
}
