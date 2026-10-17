<?php

namespace App\Modules\Scheduling\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Scheduling\Application\Services\AvailabilityService;
use App\Modules\Scheduling\Domain\Models\Availability;
use App\Modules\Scheduling\Presentation\Http\Requests\Api\V1\AvailabilityRequest;
use App\Modules\Scheduling\Presentation\Http\Resources\Api\V1\AvailabilityResource;
use App\Modules\Scheduling\Presentation\Http\Resources\Api\V1\AvailabilityRevisionResource;
use App\Modules\Scheduling\Presentation\Http\Resources\Api\V1\CalendarReferenceResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;

final class AvailabilityController extends Controller
{
    public function __construct(private readonly AvailabilityService $service, private readonly Gate $gate) {}

    public function index(AvailabilityRequest $request, TenantContext $context): JsonResponse
    {
        $this->gate->authorize('viewAny', [Availability::class, $context]);
        $page = $this->service->paginate($context, $request->validated());

        return AvailabilityResource::collection($page->records)->additional(['availability' => ['viewer_person_id' => $page->personId,
            'viewer_timezone' => $page->viewerTimezone, 'display_timezone' => $page->displayTimezone, 'from_utc' => $page->from, 'to_utc' => $page->to,
            'can_self' => $page->canSelf, 'can_manage' => $page->canManage]])->response()->header('Cache-Control', 'private, no-store');
    }

    public function show(AvailabilityRequest $request, TenantContext $context, string $tenant, string $availability): JsonResponse
    {
        $this->gate->authorize('viewAny', [Availability::class, $context]);

        return AvailabilityResource::make($this->service->find($context, $availability, $request->validated()))->response()->header('Cache-Control', 'private, no-store');
    }

    public function history(AvailabilityRequest $request, TenantContext $context, string $tenant, string $availability): JsonResponse
    {
        $this->gate->authorize('viewAny', [Availability::class, $context]);

        return AvailabilityRevisionResource::collection($this->service->history($context, $availability, $request->validated()))->response()->header('Cache-Control', 'private, no-store');
    }

    public function people(AvailabilityRequest $request, TenantContext $context): JsonResponse
    {
        $this->gate->authorize('people', [Availability::class, $context]);

        return CalendarReferenceResource::collection($this->service->people($context, $request->validated()))->response()->header('Cache-Control', 'private, no-store');
    }

    public function store(AvailabilityRequest $request, TenantContext $context): JsonResponse
    {
        $this->gate->authorize('manage', [Availability::class, $context]);

        return AvailabilityResource::make($this->service->save($context, $request->validated(), ip: $request->ip()))
            ->additional($this->notification())->response()->setStatusCode(201)->header('Cache-Control', 'private, no-store');
    }

    public function update(AvailabilityRequest $request, TenantContext $context, string $tenant, string $availability): JsonResponse
    {
        $this->gate->authorize('manage', [Availability::class, $context]);

        return AvailabilityResource::make($this->service->save($context, $request->validated(), $availability, $request->ip()))
            ->additional($this->notification())->response()->header('Cache-Control', 'private, no-store');
    }

    /** @return array{notification:array{type:string,message:string}} */
    private function notification(): array
    {
        return ['notification' => ['type' => 'success', 'message' => __('availability.saved')]];
    }
}
