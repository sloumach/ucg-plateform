<?php

namespace App\Modules\People\Infrastructure\Queue;

use App\Modules\People\Application\Services\PersonImportProcessor;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Contracts\TenantJob;
use App\Support\Queue\JobContext;
use Illuminate\Contracts\Bus\Dispatcher;
use Throwable;

final class ProcessPersonImport extends TenantJob
{
    public function __construct(JobContext $context, public readonly string $importId)
    {
        parent::__construct($context);
        $this->onQueue('reports');
    }

    public function handle(PersonImportProcessor $processor, TenantContext $context, Dispatcher $bus): void
    {
        if ($processor->process($context, $this->importId)) {
            $bus->dispatch(new self($this->context, $this->importId));
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(PersonImportProcessor::class)->failed((string) $this->context->tenantId, $this->importId, (int) $this->context->actorUserId);
    }
}
