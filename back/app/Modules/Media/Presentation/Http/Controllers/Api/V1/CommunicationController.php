<?php

namespace App\Modules\Media\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Media\Application\Services\CommunicationService;
use App\Modules\Media\Domain\Models\Announcement;
use App\Modules\Media\Presentation\Http\Requests\Api\V1\CommunicationRequest;
use App\Modules\Media\Presentation\Http\Resources\Api\V1\AnnouncementCommentResource;
use App\Modules\Media\Presentation\Http\Resources\Api\V1\AnnouncementResource;
use App\Modules\Media\Presentation\Http\Resources\Api\V1\CommunicationEventResource;
use App\Modules\Media\Presentation\Http\Resources\Api\V1\CommunicationPreferenceResource;
use App\Modules\Media\Presentation\Http\Resources\Api\V1\CommunicationReceiptResource;
use App\Modules\Media\Presentation\Http\Resources\Api\V1\MediaTeamResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;

final class CommunicationController extends Controller
{
    public function __construct(private readonly CommunicationService $service, private readonly Gate $gate) {}

    public function index(CommunicationRequest $request, TenantContext $context): JsonResponse
    {
        $this->gate->authorize('viewAny', [Announcement::class, $context]);

        return AnnouncementResource::collection($this->service->paginate($context, $request->validated()))->response()->header('Cache-Control', 'private, no-store');
    }

    public function show(CommunicationRequest $request, TenantContext $context, string $tenant, string $announcement): JsonResponse
    {
        $this->gate->authorize('viewAny', [Announcement::class, $context]);

        return AnnouncementResource::make($this->service->find($context, $announcement, $request->validated()))->response()->header('Cache-Control', 'private, no-store');
    }

    public function targets(CommunicationRequest $request, TenantContext $context): JsonResponse
    {
        $this->gate->authorize('manage', [Announcement::class, $context]);

        return MediaTeamResource::collection($this->service->targets($context, $request->validated()))->response()->header('Cache-Control', 'private, no-store');
    }

    public function store(CommunicationRequest $request, TenantContext $context): JsonResponse
    {
        $this->gate->authorize('manage', [Announcement::class, $context]);

        return AnnouncementResource::make($this->service->save($context, $request->validated(), ip: $request->ip()))
            ->additional($this->notification())->response()->setStatusCode(201)->header('Cache-Control', 'private, no-store');
    }

    public function update(CommunicationRequest $request, TenantContext $context, string $tenant, string $announcement): JsonResponse
    {
        $this->gate->authorize('manage', [Announcement::class, $context]);

        return AnnouncementResource::make($this->service->save($context, $request->validated(), $announcement, $request->ip()))
            ->additional($this->notification())->response()->header('Cache-Control', 'private, no-store');
    }

    public function comments(CommunicationRequest $request, TenantContext $context, string $tenant, string $announcement): JsonResponse
    {
        $this->gate->authorize('viewAny', [Announcement::class, $context]);

        return AnnouncementCommentResource::collection($this->service->comments($context, $announcement, $request->validated()))->response()->header('Cache-Control', 'private, no-store');
    }

    public function comment(CommunicationRequest $request, TenantContext $context, string $tenant, string $announcement): JsonResponse
    {
        $this->gate->authorize('comment', [Announcement::class, $context]);

        return AnnouncementCommentResource::make($this->service->comment($context, $announcement, $request->validated(), $request->ip()))
            ->additional($this->notification())->response()->setStatusCode(201)->header('Cache-Control', 'private, no-store');
    }

    public function preferences(CommunicationRequest $request, TenantContext $context): JsonResponse
    {
        $this->gate->authorize('viewAny', [Announcement::class, $context]);

        return CommunicationPreferenceResource::make($this->service->preferences($context))->response()->header('Cache-Control', 'private, no-store');
    }

    public function savePreferences(CommunicationRequest $request, TenantContext $context): JsonResponse
    {
        $this->gate->authorize('viewAny', [Announcement::class, $context]);

        return CommunicationPreferenceResource::make($this->service->savePreferences($context, $request->validated(), $request->ip()))
            ->additional($this->notification())->response()->header('Cache-Control', 'private, no-store');
    }

    public function inbox(CommunicationRequest $request, TenantContext $context): JsonResponse
    {
        $this->gate->authorize('viewAny', [Announcement::class, $context]);

        return CommunicationEventResource::collection($this->service->inbox($context, $request->validated()))->response()->header('Cache-Control', 'private, no-store');
    }

    public function markRead(CommunicationRequest $request, TenantContext $context, string $tenant, string $event): JsonResponse
    {
        $this->gate->authorize('viewAny', [Announcement::class, $context]);
        $this->service->markRead($context, $event);

        return CommunicationReceiptResource::make(['read' => true])->additional($this->notification())->response()->header('Cache-Control', 'private, no-store');
    }

    /** @return array{notification: array{type: string, message: string}} */
    private function notification(): array
    {
        return ['notification' => ['type' => 'success', 'message' => __('communication.saved')]];
    }
}
