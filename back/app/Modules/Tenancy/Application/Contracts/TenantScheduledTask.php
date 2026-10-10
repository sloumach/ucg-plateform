<?php

namespace App\Modules\Tenancy\Application\Contracts;

interface TenantScheduledTask
{
    /** Implementations must scope every resource and make side effects idempotent for this run. */
    public function run(TenantExecutionContext $context, string $runId): void;
}
