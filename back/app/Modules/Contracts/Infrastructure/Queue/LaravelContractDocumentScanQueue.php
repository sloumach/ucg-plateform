<?php

namespace App\Modules\Contracts\Infrastructure\Queue;

use App\Modules\Contracts\Application\Contracts\ContractDocumentScanQueue;
use App\Support\Queue\JobContext;
use Illuminate\Contracts\Bus\Dispatcher;

final readonly class LaravelContractDocumentScanQueue implements ContractDocumentScanQueue
{
    public function __construct(private Dispatcher $bus) {}

    public function dispatch(JobContext $context, string $scanId): void
    {
        $this->bus->dispatch(new ScanContractDocument($context, $scanId));
    }
}
