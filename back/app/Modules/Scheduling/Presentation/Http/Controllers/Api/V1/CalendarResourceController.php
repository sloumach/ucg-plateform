<?php

namespace App\Modules\Scheduling\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Scheduling\Application\Services\CalendarResourceService;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Scheduling\Presentation\Http\Requests\Api\V1\CalendarResourceRequest;
use App\Modules\Scheduling\Presentation\Http\Resources\Api\V1\CalendarResourceResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;

final class CalendarResourceController extends Controller
{
    public function __construct(private readonly CalendarResourceService $service, private readonly Gate $gate) {}

    public function index(CalendarResourceRequest $request, TenantContext $context): JsonResponse
    {
        $this->gate->authorize('manage', [CalendarActivity::class, $context]);

        return CalendarResourceResource::collection($this->service->paginate($context, $request->validated()))->response()->header('Cache-Control', 'private, no-store');
    }

    public function store(CalendarResourceRequest $request, TenantContext $context): JsonResponse
    {
        $this->gate->authorize('manageResources', [CalendarActivity::class, $context]);

        return CalendarResourceResource::make($this->service->save($context, $request->validated(), ip: $request->ip()))->additional($this->notification())->response()->setStatusCode(201)->header('Cache-Control', 'private, no-store');
    }

    public function update(CalendarResourceRequest $request, TenantContext $context, string $tenant, string $resource): JsonResponse
    {
        $this->gate->authorize('manageResources', [CalendarActivity::class, $context]);

        return CalendarResourceResource::make($this->service->save($context, $request->validated(), $resource, $request->ip()))->additional($this->notification())->response()->header('Cache-Control', 'private, no-store');
    }

    /** @return array{notification:array{type:string,message:string}} */
    private function notification(): array
    {
        return ['notification' => ['type' => 'success', 'message' => __('calendar.resource_saved')]];
    }
}
