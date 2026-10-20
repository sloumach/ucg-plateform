<?php

namespace App\Modules\Reporting\Infrastructure\Queue;

use App\Modules\Reporting\Application\Contracts\DashboardIndicatorQueue;
use App\Support\Queue\JobContext;
use Illuminate\Contracts\Bus\Dispatcher;

final readonly class LaravelDashboardIndicatorQueue implements DashboardIndicatorQueue
{
    public function __construct(private Dispatcher $bus) {}

    public function dispatch(JobContext $context, string $id, string $version): void
    {
        $this->bus->dispatch(new CalculateDashboardIndicator($context, $id, $version));
    }
}
