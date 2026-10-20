<?php

namespace App\Modules\Reporting\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Reporting\Application\Services\DashboardIndicatorService;
use App\Modules\Reporting\Presentation\Http\Requests\Api\V1\DashboardIndicatorRequest as Request;
use App\Modules\Reporting\Presentation\Http\Resources\Api\V1\DashboardResource as Resource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;

final class DashboardIndicatorController extends Controller
{
    public function __construct(private readonly DashboardIndicatorService $service, private readonly Gate $gate) {}

    public function show(Request $request, TenantContext $context, string $tenant, string $card): Resource
    {
        $this->gate->authorize('reporting.viewAny', $context);

        return Resource::make($this->service->read($context, $card, $request->validated()))->additional(['meta' => ['request_id' => $context->requestId]]);
    }

    public function store(Request $request, TenantContext $context, string $tenant, string $card): Resource
    {
        $this->gate->authorize('reporting.viewAny', $context);
        $this->service->request($context, $card, $request->validated());

        return Resource::make($this->service->read($context, $card, array_intersect_key($request->validated(), array_flip(['view', 'from', 'to']))))
            ->additional(['meta' => ['request_id' => $context->requestId], 'notification' => ['type' => 'success', 'message' => __('dashboards.indicator_queued')]]);
    }
}
