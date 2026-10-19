<?php

namespace App\Modules\Performance\Infrastructure\Queue;

use App\Modules\Performance\Application\Contracts\StatisticalCalculationQueue;
use App\Support\Queue\JobContext;
use Illuminate\Contracts\Bus\Dispatcher;

final readonly class LaravelStatisticalCalculationQueue implements StatisticalCalculationQueue
{
    public function __construct(private Dispatcher $bus) {}

    public function dispatch(JobContext $context, string $id, string $version): void
    {
        $this->bus->dispatch(new CalculateStatistics($context, $id, $version));
    }

    public function affected(JobContext $context, string $observation, ?string $after = null): void
    {
        $this->bus->dispatch(new RecalculateObservationStatistics($context, $observation, $after));
    }
}
