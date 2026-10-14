<?php

namespace App\Modules\Teams\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Teams\Application\Services\ExternalOpponentService;
use App\Modules\Teams\Application\Services\ExternalOpponentSnapshotService;
use App\Modules\Teams\Domain\Models\ExternalOpponent;
use App\Modules\Teams\Presentation\Http\Requests\Api\V1\CaptureExternalOpponentRequest;
use App\Modules\Teams\Presentation\Http\Requests\Api\V1\ExternalOpponentReadRequest;
use App\Modules\Teams\Presentation\Http\Requests\Api\V1\SaveExternalOpponentRequest;
use App\Modules\Teams\Presentation\Http\Resources\Api\V1\ExternalOpponentResource;
use App\Modules\Teams\Presentation\Http\Resources\Api\V1\ExternalOpponentSnapshotResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ExternalOpponentController extends Controller
{
    public function __construct(private readonly ExternalOpponentService $opponents,
        private readonly ExternalOpponentSnapshotService $snapshots, private readonly Gate $gate) {}

    public function index(ExternalOpponentReadRequest $request, TenantContext $context): AnonymousResourceCollection
    {
        $this->gate->authorize('viewAny', [ExternalOpponent::class, $context]);

        return ExternalOpponentResource::collection($this->opponents->paginate($context, $request->validated()))
            ->additional(['meta' => ['request_id' => $context->requestId]]);
    }

    public function show(ExternalOpponentReadRequest $request, TenantContext $context, string $tenant, string $opponent): ExternalOpponentResource
    {
        $this->gate->authorize('viewAny', [ExternalOpponent::class, $context]);

        return ExternalOpponentResource::make($this->opponents->find($context, $opponent));
    }

    public function store(SaveExternalOpponentRequest $request, TenantContext $context): JsonResponse
    {
        $this->gate->authorize('manage', [ExternalOpponent::class, $context]);

        return ExternalOpponentResource::make($this->opponents->save($context, $request->validated(), ipAddress: $request->ip()))
            ->additional(['notification' => ['type' => 'success', 'message' => __('external_opponents.saved')]])->response()->setStatusCode(201);
    }

    public function update(SaveExternalOpponentRequest $request, TenantContext $context, string $tenant, string $opponent): ExternalOpponentResource
    {
        $this->gate->authorize('manage', [ExternalOpponent::class, $context]);

        return ExternalOpponentResource::make($this->opponents->save($context, $request->validated(), $opponent, $request->ip()))
            ->additional(['notification' => ['type' => 'success', 'message' => __('external_opponents.saved')]]);
    }

    public function snapshots(ExternalOpponentReadRequest $request, TenantContext $context, string $tenant, string $opponent): AnonymousResourceCollection
    {
        $this->gate->authorize('viewAny', [ExternalOpponent::class, $context]);

        return ExternalOpponentSnapshotResource::collection($this->snapshots->paginate($context, $opponent, $request->validated()))
            ->additional(['meta' => ['request_id' => $context->requestId]]);
    }

    public function capture(CaptureExternalOpponentRequest $request, TenantContext $context, string $tenant, string $opponent): JsonResponse
    {
        $this->gate->authorize('manage', [ExternalOpponent::class, $context]);

        return ExternalOpponentSnapshotResource::make($this->snapshots->store($context, $opponent, $request->validated(), $request->ip()))
            ->additional(['notification' => ['type' => 'success', 'message' => __('external_opponents.captured')]])->response()->setStatusCode(201);
    }
}
