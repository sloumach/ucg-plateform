<?php

namespace App\Modules\Tenancy\Application\Contracts;

/** Fresh worker state. A system task never impersonates the tenant owner. */
final readonly class TenantExecutionContext
{
    public function __construct(
        public string $organizationId,
        public string $requestId,
        public string $timezone,
        public string $language,
        public ?TenantContext $actorContext = null,
    ) {}
}
