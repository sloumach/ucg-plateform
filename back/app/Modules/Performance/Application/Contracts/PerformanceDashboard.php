<?php

namespace App\Modules\Performance\Application\Contracts;

use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Data\DashboardFeed;
use App\Support\Data\DashboardWindow;

interface PerformanceDashboard
{
    public function read(TenantContext $context, string $card, DashboardWindow $window): DashboardFeed;
}
