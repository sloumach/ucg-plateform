<?php

namespace App\Modules\Performance\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Performance\Application\Services\StatisticalProfileService;
use App\Modules\Performance\Domain\Models\StatisticalObservation;
use App\Modules\Performance\Presentation\Http\Requests\Api\V1\StatisticalProfileRequest as Request;
use App\Modules\Performance\Presentation\Http\Resources\Api\V1\StatisticalProfileResource as Resource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;

final class StatisticalProfileController extends Controller
{
    public function __construct(private readonly StatisticalProfileService $service, private readonly Gate $gate) {}

    public function show(Request $request, TenantContext $context): Resource
    {
        $this->gate->authorize('viewAny', [StatisticalObservation::class, $context]);

        return Resource::make($this->service->show($context, $request->validated()))
            ->additional(['meta' => ['request_id' => $context->requestId]]);
    }

    public function people(Request $request, TenantContext $context): JsonResponse
    {
        $this->gate->authorize('viewAny', [StatisticalObservation::class, $context]);
        $list = $this->service->people($context, $request->validated());
        $list->withPath($request->url());

        return Resource::collection($list)->additional(['meta' => ['request_id' => $context->requestId]])
            ->response()->header('Cache-Control', 'private, no-store');
    }
}
