<?php

namespace App\Modules\Reporting\Infrastructure\Queue;

use App\Exceptions\DomainException;
use App\Modules\Reporting\Application\Services\ReportExportProcessor;
use App\Modules\Reporting\Application\Services\ReportExportService;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Contracts\TenantJob;
use App\Support\Queue\JobContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Throwable;

final class BuildReportExport extends TenantJob
{
    public function __construct(JobContext $context, public readonly string $exportId)
    {
        parent::__construct($context);
        $this->onQueue('reports');
    }

    public function handle(ReportExportProcessor $processor, TenantContext $context): void
    {
        try {
            $processor->process($context, $this->exportId);
        } catch (DomainException $exception) {
            if ($exception->errorCode() === 'TENANT_RESOURCE_BUSY') {
                // A duplicate delivery must not exhaust retries and fail the active owner.
                return;
            }
            $this->fail($exception);
        } catch (AuthorizationException|ModelNotFoundException $exception) {
            $this->fail($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(ReportExportService::class)->fail((string) $this->context->tenantId, (int) $this->context->actorUserId, $this->exportId);
    }
}
