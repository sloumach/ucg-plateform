<?php

namespace App\Modules\Partnerships\Application\Contracts;

use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Data\DashboardFeed;
use App\Support\Data\DashboardWindow;

interface PartnershipsDashboard
{
    /** @return array<string,int> */
    public function summarize(TenantContext $context, string $card, DashboardWindow $window): array;

    public function read(TenantContext $context, string $card, DashboardWindow $window): DashboardFeed;
}
