<?php

namespace App\Modules\Performance\Infrastructure\Queue;

use App\Modules\Performance\Application\Services\StatisticalCalculationProcessor;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Contracts\TenantJob;
use App\Support\Queue\JobContext;
use Throwable;

final class CalculateStatistics extends TenantJob
{
    public function __construct(JobContext $context, public readonly string $calculationId, public readonly string $version)
    {
        parent::__construct($context);
        $this->onQueue('reports');
    }

    public function handle(StatisticalCalculationProcessor $processor, TenantContext $context): void
    {
        $processor->process($context, $this->calculationId, $this->version);
    }

    public function failed(?Throwable $exception): void
    {
        app(StatisticalCalculationProcessor::class)->failed((string) $this->context->tenantId,
            (int) $this->context->actorUserId, $this->calculationId, $this->version);
    }
}
