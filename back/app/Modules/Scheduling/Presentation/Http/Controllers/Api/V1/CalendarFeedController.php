<?php

namespace App\Modules\Scheduling\Presentation\Http\Controllers\Api\V1;

use App\Http\Api\ApiResponseFactory;
use App\Http\Api\RequestId;
use App\Http\Controllers\Controller;
use App\Modules\Scheduling\Application\Services\CalendarFeedService;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Scheduling\Presentation\Http\Requests\Api\V1\CalendarFeedRequest;
use App\Modules\Scheduling\Presentation\Http\Requests\Api\V1\CalendarSubscriptionRequest;
use App\Modules\Scheduling\Presentation\Http\Resources\Api\V1\CalendarFeedResource;
use App\Modules\Scheduling\Presentation\Http\Resources\Api\V1\CalendarFeedSecretResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

final class CalendarFeedController extends Controller
{
    public function __construct(private readonly CalendarFeedService $service, private readonly Gate $gate, private readonly ApiResponseFactory $responses) {}

    public function show(CalendarFeedRequest $request, TenantContext $context): JsonResponse
    {
        $this->gate->authorize('export', [CalendarActivity::class, $context]);

        $feed = $this->service->personal($context, $request->validated());

        return $this->responses->success($request, $feed === null ? null : CalendarFeedResource::make($feed))->header('Cache-Control', 'private, no-store');
    }

    public function store(CalendarFeedRequest $request, TenantContext $context): JsonResponse
    {
        $this->gate->authorize('export', [CalendarActivity::class, $context]);

        return CalendarFeedSecretResource::make($this->service->issue($context, $request->validated(), $request->ip()))
            ->additional(['notification' => ['type' => 'success', 'message' => __('calendar.feed_issued')]])->response()
            ->setStatusCode(201)->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    public function revoke(CalendarFeedRequest $request, TenantContext $context): JsonResponse
    {
        $this->gate->authorize('export', [CalendarActivity::class, $context]);

        return CalendarFeedResource::make($this->service->revoke($context, $request->validated(), $request->ip()))
            ->additional(['notification' => ['type' => 'success', 'message' => __('calendar.feed_revoked')]])->response()->header('Cache-Control', 'private, no-store');
    }

    public function subscription(CalendarSubscriptionRequest $request, string $tenant, string $feed): Response
    {
        $subscription = $this->service->open($tenant, $feed, $request->validated('token'), RequestId::for($request));
        $this->gate->authorize('subscription', [CalendarActivity::class, $subscription->context]);

        return new Response($this->service->render($subscription), 200, ['Content-Type' => 'text/calendar; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="calendar.ics"']);
    }
}
