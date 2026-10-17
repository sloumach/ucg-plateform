<?php

namespace App\Modules\Scheduling\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Scheduling\Application\Services\CalendarChangeService;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Scheduling\Presentation\Http\Requests\Api\V1\CalendarChangeRequest;
use App\Modules\Scheduling\Presentation\Http\Resources\Api\V1\CalendarChangeNoticeResource;
use App\Modules\Scheduling\Presentation\Http\Resources\Api\V1\CalendarChangeResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\JsonResponse;

final class CalendarChangeController extends Controller
{
    public function __construct(private readonly CalendarChangeService $service, private readonly Gate $gate, private readonly Repository $config) {}

    public function index(CalendarChangeRequest $request, TenantContext $context): JsonResponse
    {
        $this->gate->authorize('viewAny', [CalendarActivity::class, $context]);

        return CalendarChangeNoticeResource::collection($this->service->inbox($context, $request->validated()))
            ->additional($this->deliveryStatus())->response()->header('Cache-Control', 'private, no-store');
    }

    public function history(CalendarChangeRequest $request, TenantContext $context, string $tenant, string $activity): JsonResponse
    {
        $this->gate->authorize('manage', [CalendarActivity::class, $context]);

        return CalendarChangeResource::collection($this->service->history($context, $activity, $request->validated()))
            ->additional($this->deliveryStatus())->response()->header('Cache-Control', 'private, no-store');
    }

    /** @return array{calendar_changes:array{delivery_enabled:bool}} */
    private function deliveryStatus(): array
    {
        $tasks = $this->config->get('tenancy.scheduled_tasks', []);

        return ['calendar_changes' => ['delivery_enabled' => is_array($tasks) && isset($tasks['calendar-changes'])]];
    }
}
