<?php

namespace App\Modules\Reporting\Infrastructure\Queue;

use App\Modules\Reporting\Application\Contracts\ReportExportQueue;
use App\Support\Queue\JobContext;
use Illuminate\Contracts\Bus\Dispatcher;

final readonly class LaravelReportExportQueue implements ReportExportQueue
{
    public function __construct(private Dispatcher $bus) {}

    public function dispatch(JobContext $context, string $id): void
    {
        $this->bus->dispatch(new BuildReportExport($context, $id));
    }
}
