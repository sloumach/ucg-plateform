<?php

namespace App\Modules\Scheduling\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Scheduling\Application\Services\CalendarService;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Scheduling\Presentation\Http\Requests\Api\V1\CalendarRequest;
use App\Modules\Scheduling\Presentation\Http\Resources\Api\V1\CalendarActivityResource;
use App\Modules\Scheduling\Presentation\Http\Resources\Api\V1\CalendarReferenceResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;

final class CalendarController extends Controller
{
    public function __construct(private readonly CalendarService $service, private readonly Gate $gate) {}

    public function index(CalendarRequest $request, TenantContext $context): JsonResponse
    {
        $this->gate->authorize('viewAny', [CalendarActivity::class, $context]);
        $page = $this->service->paginate($context, $request->validated());

        return CalendarActivityResource::collection($page->activities)->additional(['calendar' => ['viewer_timezone' => $page->viewerTimezone,
            'display_timezone' => $page->displayTimezone, 'from_utc' => $page->from, 'to_utc' => $page->to]])->response()->header('Cache-Control', 'private, no-store');
    }

    public function show(CalendarRequest $request, TenantContext $context, string $tenant, string $activity): JsonResponse
    {
        $this->gate->authorize('viewAny', [CalendarActivity::class, $context]);

        return CalendarActivityResource::make($this->service->find($context, $activity, $request->validated()))->response()->header('Cache-Control', 'private, no-store');
    }

    public function store(CalendarRequest $request, TenantContext $context): JsonResponse
    {
        $this->gate->authorize('manage', [CalendarActivity::class, $context]);

        return CalendarActivityResource::make($this->service->save($context, $request->validated(), ip: $request->ip()))
            ->additional($this->notification())->response()->setStatusCode(201)->header('Cache-Control', 'private, no-store');
    }

    public function update(CalendarRequest $request, TenantContext $context, string $tenant, string $activity): JsonResponse
    {
        $this->gate->authorize('manage', [CalendarActivity::class, $context]);

        return CalendarActivityResource::make($this->service->save($context, $request->validated(), $activity, $request->ip()))
            ->additional($this->notification())->response()->header('Cache-Control', 'private, no-store');
    }

    public function teams(CalendarRequest $request, TenantContext $context): JsonResponse
    {
        $this->gate->authorize('manage', [CalendarActivity::class, $context]);

        return CalendarReferenceResource::collection($this->service->references($context, 'teams', $request->validated()))->response()->header('Cache-Control', 'private, no-store');
    }

    public function people(CalendarRequest $request, TenantContext $context): JsonResponse
    {
        $this->gate->authorize('manage', [CalendarActivity::class, $context]);

        return CalendarReferenceResource::collection($this->service->references($context, 'people', $request->validated()))->response()->header('Cache-Control', 'private, no-store');
    }

    /** @return array{notification:array{type:string,message:string}} */
    private function notification(): array
    {
        return ['notification' => ['type' => 'success', 'message' => __('calendar.saved')]];
    }
}
