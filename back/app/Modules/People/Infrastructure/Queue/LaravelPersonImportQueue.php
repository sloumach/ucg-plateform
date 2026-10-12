<?php

namespace App\Modules\People\Infrastructure\Queue;

use App\Modules\People\Application\Contracts\PersonImportQueue;
use App\Support\Queue\JobContext;
use Illuminate\Contracts\Bus\Dispatcher;

final readonly class LaravelPersonImportQueue implements PersonImportQueue
{
    public function __construct(private Dispatcher $bus) {}

    public function dispatch(JobContext $context, string $importId): void
    {
        $this->bus->dispatch(new ProcessPersonImport($context, $importId));
    }
}
