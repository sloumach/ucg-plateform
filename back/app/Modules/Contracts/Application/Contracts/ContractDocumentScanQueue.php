<?php

namespace App\Modules\Contracts\Application\Contracts;

use App\Support\Queue\JobContext;

interface ContractDocumentScanQueue
{
    public function dispatch(JobContext $context, string $scanId): void;
}
