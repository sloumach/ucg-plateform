<?php

namespace App\Modules\People\Application\Contracts;

use App\Support\Queue\JobContext;

interface PersonImportQueue
{
    public function dispatch(JobContext $context, string $importId): void;
}
