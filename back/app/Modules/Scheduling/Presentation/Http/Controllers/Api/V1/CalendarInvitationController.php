<?php

namespace App\Modules\Scheduling\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Scheduling\Application\Services\CalendarInvitationService;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Scheduling\Presentation\Http\Requests\Api\V1\CalendarInvitationRequest;
use App\Modules\Scheduling\Presentation\Http\Resources\Api\V1\CalendarInvitationDeliveryResource;
use App\Modules\Scheduling\Presentation\Http\Resources\Api\V1\CalendarInvitationResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\JsonResponse;

final class CalendarInvitationController extends Controller
{
    public function __construct(private readonly CalendarInvitationService $service, private readonly Gate $gate, private readonly Repository $config) {}

    public function index(CalendarInvitationRequest $request, TenantContext $context): JsonResponse
    {
        $this->gate->authorize('viewAny', [CalendarActivity::class, $context]);

        return CalendarInvitationResource::collection($this->service->paginate($context, $request->validated()))
            ->additional($this->deliveryStatus())
            ->response()->header('Cache-Control', 'private, no-store');
    }

    public function activityIndex(CalendarInvitationRequest $request, TenantContext $context, string $tenant, string $activity): JsonResponse
    {
        $this->gate->authorize('manageInvitations', [CalendarActivity::class, $context]);

        return CalendarInvitationResource::collection($this->service->paginate($context, $request->validated(), $activity))
            ->additional($this->deliveryStatus())
            ->response()->header('Cache-Control', 'private, no-store');
    }

    public function issue(CalendarInvitationRequest $request, TenantContext $context, string $tenant, string $activity): JsonResponse
    {
        $this->gate->authorize('manageInvitations', [CalendarActivity::class, $context]);
        $this->service->issue($context, $activity, $request->validated(), $request->ip());

        return CalendarInvitationResource::collection($this->service->paginate($context, [], $activity))
            ->additional([...$this->notification('invitation_issued'), ...$this->deliveryStatus()])->response()->setStatusCode(201)->header('Cache-Control', 'private, no-store');
    }

    public function respond(CalendarInvitationRequest $request, TenantContext $context, string $tenant, string $invitation): JsonResponse
    {
        $this->gate->authorize('respondInvitations', [CalendarActivity::class, $context]);

        return CalendarInvitationResource::make($this->service->respond($context, $invitation, $request->validated(), $request->ip()))
            ->additional($this->notification('invitation_responded'))->response()->header('Cache-Control', 'private, no-store');
    }

    public function history(CalendarInvitationRequest $request, TenantContext $context, string $tenant, string $invitation): JsonResponse
    {
        $this->gate->authorize('viewAny', [CalendarActivity::class, $context]);

        return CalendarInvitationDeliveryResource::collection($this->service->history($context, $invitation, $request->validated()))
            ->response()->header('Cache-Control', 'private, no-store');
    }

    /** @return array{notification:array{type:string,message:string}} */
    private function notification(string $key): array
    {
        return ['notification' => ['type' => 'success', 'message' => __('calendar.'.$key)]];
    }

    /** @return array{calendar_invitations:array{delivery_enabled:bool}} */
    private function deliveryStatus(): array
    {
        $tasks = $this->config->get('tenancy.scheduled_tasks', []);

        return ['calendar_invitations' => ['delivery_enabled' => is_array($tasks) && isset($tasks['calendar-invitations'])]];
    }
}
