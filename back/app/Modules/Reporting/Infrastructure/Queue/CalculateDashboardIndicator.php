<?php

namespace App\Modules\Reporting\Infrastructure\Queue;

use App\Modules\Reporting\Application\Services\DashboardIndicatorProcessor;
use App\Modules\Reporting\Application\Services\DashboardIndicatorService;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Contracts\TenantJob;
use App\Support\Queue\JobContext;
use Throwable;

final class CalculateDashboardIndicator extends TenantJob
{
    public function __construct(JobContext $context, public readonly string $indicatorId, public readonly string $version)
    {
        parent::__construct($context);
        $this->onQueue('reports');
    }

    public function handle(DashboardIndicatorProcessor $processor, TenantContext $context): void
    {
        $processor->process($context, $this->indicatorId, $this->version);
    }

    public function failed(?Throwable $exception): void
    {
        app(DashboardIndicatorService::class)->fail((string) $this->context->tenantId, (int) $this->context->actorUserId, $this->indicatorId, $this->version);
    }
}
