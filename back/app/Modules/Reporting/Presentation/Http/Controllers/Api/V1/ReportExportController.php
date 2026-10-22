<?php

namespace App\Modules\Reporting\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Reporting\Application\Services\ReportExportService;
use App\Modules\Reporting\Presentation\Http\Requests\Api\V1\ReportExportRequest as Request;
use App\Modules\Reporting\Presentation\Http\Resources\Api\V1\DashboardResource as Resource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\Response;

final class ReportExportController extends Controller
{
    public function __construct(private readonly ReportExportService $service, private readonly Gate $gate) {}

    public function index(Request $request, TenantContext $context): Resource
    {
        $this->gate->authorize('reporting.exports', $context);

        return Resource::make($this->service->index($context, (int) ($request->validated('page') ?? 1)))
            ->additional(['meta' => ['request_id' => $context->requestId]]);
    }

    public function show(Request $request, TenantContext $context, string $tenant, string $export): Resource
    {
        $this->gate->authorize('reporting.exports', $context);

        return Resource::make($this->service->show($context, $export))->additional(['meta' => ['request_id' => $context->requestId]]);
    }

    public function store(Request $request, TenantContext $context): Resource
    {
        $this->gate->authorize('reporting.exports', $context);

        return Resource::make($this->service->request($context, $request->validated()))
            ->additional(['meta' => ['request_id' => $context->requestId], 'notification' => ['type' => 'success', 'message' => __('report_exports.queued')]]);
    }

    public function download(Request $request, TenantContext $context, string $tenant, string $export): Response
    {
        $this->gate->authorize('reporting.exports', $context);

        return response($this->service->download($context, $export), 200, ['Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="report-'.$export.'.csv"', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff']);
    }
}
