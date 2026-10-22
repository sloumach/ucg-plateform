<?php

namespace App\Modules\Reporting\Application\Contracts;

use App\Support\Queue\JobContext;

interface ReportExportQueue
{
    public function dispatch(JobContext $context, string $id): void;
}
