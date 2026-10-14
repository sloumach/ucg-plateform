<?php

namespace App\Modules\Contracts\Infrastructure\Queue;

use App\Modules\Contracts\Application\Services\ContractDocumentScanProcessor;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Contracts\TenantJob;
use App\Support\Queue\JobContext;
use Throwable;

final class ScanContractDocument extends TenantJob
{
    public function __construct(JobContext $context, public readonly string $scanId)
    {
        parent::__construct($context);
        $this->onQueue('reports');
    }

    public function handle(ContractDocumentScanProcessor $processor, TenantContext $context): void
    {
        $processor->process($context, $this->scanId);
    }

    public function failed(?Throwable $exception): void
    {
        app(ContractDocumentScanProcessor::class)->failed((string) $this->context->tenantId, $this->scanId, (int) $this->context->actorUserId);
    }
}
