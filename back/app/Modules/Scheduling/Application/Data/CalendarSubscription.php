<?php

namespace App\Modules\Scheduling\Application\Data;

use App\Modules\Tenancy\Application\Contracts\TenantContext;

final readonly class CalendarSubscription
{
    public function __construct(public TenantContext $context, public string $feedId, public string $tokenHash) {}
}
