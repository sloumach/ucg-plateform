<?php

namespace App\Modules\Reporting\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Reporting\Application\Services\DashboardService;
use App\Modules\Reporting\Presentation\Http\Requests\Api\V1\DashboardRequest as Request;
use App\Modules\Reporting\Presentation\Http\Resources\Api\V1\DashboardResource as Resource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;

final class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboards, private readonly Gate $gate) {}

    public function index(Request $request, TenantContext $context): Resource
    {
        $this->gate->authorize('reporting.viewAny', $context);

        return Resource::make($this->dashboards->index($context, $request->validated()))->additional(['meta' => ['request_id' => $context->requestId]]);
    }

    public function show(Request $request, TenantContext $context, string $tenant, string $card): Resource
    {
        $this->gate->authorize('reporting.viewAny', $context);

        return Resource::make($this->dashboards->show($context, $card, $request->validated()))->additional(['meta' => ['request_id' => $context->requestId]]);
    }
}
