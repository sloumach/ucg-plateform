<?php

namespace App\Modules\Performance\Application\Contracts;

use App\Support\Queue\JobContext;

interface StatisticalImportQueue
{
    public function dispatch(JobContext $context, string $importId, string $version): void;
}
