<?php

namespace App\Modules\Performance\Application\Contracts;

use App\Support\Queue\JobContext;

interface StatisticalCalculationQueue
{
    public function dispatch(JobContext $context, string $id, string $version): void;

    public function affected(JobContext $context, string $observation, ?string $after = null): void;
}
