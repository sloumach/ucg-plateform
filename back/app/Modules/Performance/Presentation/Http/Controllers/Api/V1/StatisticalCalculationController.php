<?php

namespace App\Modules\Performance\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Performance\Application\Services\StatisticalCalculationService;
use App\Modules\Performance\Domain\Models\StatisticalCalculation;
use App\Modules\Performance\Presentation\Http\Requests\Api\V1\StatisticalCalculationRequest as Request;
use App\Modules\Performance\Presentation\Http\Resources\Api\V1\StatisticalCalculationResource as Resource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;

final class StatisticalCalculationController extends Controller
{
    public function __construct(private readonly StatisticalCalculationService $service, private readonly Gate $gate) {}

    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $this->gate->authorize('viewAny', [StatisticalCalculation::class, $context]);
        $list = $this->service->index($context, (int) $request->validated('page', 1));
        $list->withPath($request->url())->appends($request->validated());

        return Resource::collection($list)->additional(['meta' => ['request_id' => $context->requestId]])->response()->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, TenantContext $context, string $tenant, string $calculation): Resource
    {
        $this->gate->authorize('viewAny', [StatisticalCalculation::class, $context]);

        return Resource::make($this->service->show($context, $calculation, (int) $request->validated('page', 1)))
            ->additional(['meta' => ['request_id' => $context->requestId]]);
    }

    public function store(Request $request, TenantContext $context): Resource
    {
        $this->gate->authorize('create', [StatisticalCalculation::class, $context]);
        $calculation = $this->service->create($context, $request->validated());

        return Resource::make($this->service->show($context, $calculation->id, 1))
            ->additional(['meta' => ['request_id' => $context->requestId], 'notification' => ['type' => 'success', 'message' => __('statistical_calculations.queued')]]);
    }

    public function recalculate(Request $request, TenantContext $context, string $tenant, string $calculation): Resource
    {
        $this->gate->authorize('create', [StatisticalCalculation::class, $context]);
        $row = $this->service->recalculate($context, $calculation, $request->validated());

        return Resource::make($this->service->show($context, $row->id, 1))
            ->additional(['meta' => ['request_id' => $context->requestId], 'notification' => ['type' => 'success', 'message' => __('statistical_calculations.queued')]]);
    }

    public function history(Request $request, TenantContext $context, string $tenant, string $calculation): JsonResponse
    {
        $this->gate->authorize('viewAny', [StatisticalCalculation::class, $context]);
        $list = $this->service->history($context, $calculation, (int) $request->validated('page', 1));
        $list->withPath($request->url());

        return Resource::collection($list)->additional(['meta' => ['request_id' => $context->requestId]])->response()->header('Cache-Control', 'private, no-store');
    }
}
