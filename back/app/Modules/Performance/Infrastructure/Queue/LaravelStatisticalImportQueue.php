<?php

namespace App\Modules\Performance\Infrastructure\Queue;

use App\Modules\Performance\Application\Contracts\StatisticalImportQueue;
use App\Support\Queue\JobContext;
use Illuminate\Contracts\Bus\Dispatcher;

final readonly class LaravelStatisticalImportQueue implements StatisticalImportQueue
{
    public function __construct(private Dispatcher $bus) {}

    public function dispatch(JobContext $context, string $importId, string $version): void
    {
        $this->bus->dispatch(new ProcessStatisticalImport($context, $importId, $version));
    }
}
