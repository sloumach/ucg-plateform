<?php

namespace App\Modules\Competition\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Competition\Application\Services\MatchService;
use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Competition\Presentation\Http\Requests\Api\V1\MatchRequest;
use App\Modules\Competition\Presentation\Http\Resources\Api\V1\MatchReferenceResource;
use App\Modules\Competition\Presentation\Http\Resources\Api\V1\MatchResource;
use App\Modules\Competition\Presentation\Http\Resources\Api\V1\MatchStatusEventResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;

final class MatchController extends Controller
{
    public function __construct(private readonly MatchService $service, private readonly Gate $gate) {}

    public function index(MatchRequest $request, TenantContext $context): JsonResponse
    {
        $this->gate->authorize('viewAny', [MatchRecord::class, $context]);

        return MatchResource::collection($this->service->paginate($context, $request->validated()))->response()->header('Cache-Control', 'private, no-store');
    }

    public function show(MatchRequest $request, TenantContext $context, string $tenant, string $match): JsonResponse
    {
        $this->gate->authorize('viewAny', [MatchRecord::class, $context]);

        return MatchResource::make($this->service->find($context, $match, $request->validated()))->response()->header('Cache-Control', 'private, no-store');
    }

    public function history(MatchRequest $request, TenantContext $context, string $tenant, string $match): JsonResponse
    {
        $this->gate->authorize('viewAny', [MatchRecord::class, $context]);

        return MatchStatusEventResource::collection($this->service->history($context, $match, $request->validated()))->response()->header('Cache-Control', 'private, no-store');
    }

    public function references(MatchRequest $request, TenantContext $context, string $tenant, string $type): JsonResponse
    {
        $this->gate->authorize('manage', [MatchRecord::class, $context]);

        return MatchReferenceResource::collection($this->service->references($context, $type, $request->validated()))->response()->header('Cache-Control', 'private, no-store');
    }

    public function store(MatchRequest $request, TenantContext $context): JsonResponse
    {
        $this->gate->authorize('manage', [MatchRecord::class, $context]);

        return MatchResource::make($this->service->save($context, $request->validated(), ip: $request->ip()))
            ->additional($this->notification())->response()->setStatusCode(201)->header('Cache-Control', 'private, no-store');
    }

    public function update(MatchRequest $request, TenantContext $context, string $tenant, string $match): JsonResponse
    {
        $this->gate->authorize('manage', [MatchRecord::class, $context]);

        return MatchResource::make($this->service->save($context, $request->validated(), $match, $request->ip()))
            ->additional($this->notification())->response()->header('Cache-Control', 'private, no-store');
    }

    public function transition(MatchRequest $request, TenantContext $context, string $tenant, string $match): JsonResponse
    {
        $this->gate->authorize('manage', [MatchRecord::class, $context]);

        return MatchResource::make($this->service->transition($context, $match, $request->validated(), $request->ip()))
            ->additional($this->notification())->response()->header('Cache-Control', 'private, no-store');
    }

    /** @return array{notification:array{type:string,message:string}} */
    private function notification(): array
    {
        return ['notification' => ['type' => 'success', 'message' => __('matches.saved')]];
    }
}
