<?php

namespace App\Modules\Reporting\Application\Contracts;

use App\Support\Queue\JobContext;

interface DashboardIndicatorQueue
{
    public function dispatch(JobContext $context, string $id, string $version): void;
}
