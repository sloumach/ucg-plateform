<?php

namespace App\Modules\Performance\Infrastructure\Queue;

use App\Modules\Performance\Application\Services\StatisticalImportProcessor;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Contracts\TenantJob;
use App\Support\Queue\JobContext;
use Illuminate\Contracts\Bus\Dispatcher;
use Throwable;

final class ProcessStatisticalImport extends TenantJob
{
    public function __construct(JobContext $context, public readonly string $importId, public readonly string $version)
    {
        parent::__construct($context);
        $this->onQueue('reports');
    }

    public function handle(StatisticalImportProcessor $processor, TenantContext $context, Dispatcher $bus): void
    {
        if ($processor->process($context, $this->importId, $this->version)) {
            $bus->dispatch(new self($this->context, $this->importId, $this->version));
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(StatisticalImportProcessor::class)->failed((string) $this->context->tenantId, (int) $this->context->actorUserId,
            $this->importId, $this->version);
    }
}
