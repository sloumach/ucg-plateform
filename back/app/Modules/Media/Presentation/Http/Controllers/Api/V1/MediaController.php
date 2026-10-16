<?php

namespace App\Modules\Media\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Media\Application\Services\MediaService;
use App\Modules\Media\Domain\Models\MediaItem;
use App\Modules\Media\Presentation\Http\Requests\Api\V1\MediaRequest;
use App\Modules\Media\Presentation\Http\Resources\Api\V1\MediaResource;
use App\Modules\Media\Presentation\Http\Resources\Api\V1\MediaTeamResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;

final class MediaController extends Controller
{
    public function __construct(private readonly MediaService $media, private readonly Gate $gate) {}

    public function index(MediaRequest $request, TenantContext $context): JsonResponse
    {
        $this->gate->authorize('viewAny', [MediaItem::class, $context]);

        return MediaResource::collection($this->media->paginate($context, $request->validated()))->response()->header('Cache-Control', 'private, no-store');
    }

    public function show(MediaRequest $request, TenantContext $context, string $tenant, string $media): JsonResponse
    {
        $this->gate->authorize('viewAny', [MediaItem::class, $context]);

        return MediaResource::make($this->media->find($context, $media))->response()->header('Cache-Control', 'private, no-store');
    }

    public function store(MediaRequest $request, TenantContext $context): JsonResponse
    {
        $this->gate->authorize('manage', [MediaItem::class, $context]);

        return MediaResource::make($this->media->save($context, $request->validated(), ipAddress: $request->ip()))
            ->additional(['notification' => ['type' => 'success', 'message' => __('media.saved')]])
            ->response()->setStatusCode(201)->header('Cache-Control', 'private, no-store');
    }

    public function update(MediaRequest $request, TenantContext $context, string $tenant, string $media): JsonResponse
    {
        $this->gate->authorize('manage', [MediaItem::class, $context]);

        return MediaResource::make($this->media->save($context, $request->validated(), $media, $request->ip()))
            ->additional(['notification' => ['type' => 'success', 'message' => __('media.saved')]])
            ->response()->header('Cache-Control', 'private, no-store');
    }

    public function teams(MediaRequest $request, TenantContext $context): JsonResponse
    {
        $this->gate->authorize('viewAny', [MediaItem::class, $context]);

        return MediaTeamResource::collection($this->media->teams($context, $request->validated()))->response()->header('Cache-Control', 'private, no-store');
    }
}
