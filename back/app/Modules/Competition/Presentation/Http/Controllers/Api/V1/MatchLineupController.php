<?php

namespace App\Modules\Competition\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Competition\Application\Services\MatchLineupService;
use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Competition\Presentation\Http\Requests\Api\V1\MatchLineupRequest;
use App\Modules\Competition\Presentation\Http\Resources\Api\V1\MatchLineupResource;
use App\Modules\Competition\Presentation\Http\Resources\Api\V1\MatchReferenceResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;

final class MatchLineupController extends Controller
{
    public function __construct(private readonly MatchLineupService $service, private readonly Gate $gate) {}

    public function index(MatchLineupRequest $request, TenantContext $context, string $tenant, string $match, string $participant): JsonResponse
    {
        $this->gate->authorize('viewAny', [MatchRecord::class, $context]);

        return MatchLineupResource::collection($this->service->history($context, $match, $participant, $request->validated()))->response()->header('Cache-Control', 'private, no-store');
    }

    public function choices(MatchLineupRequest $request, TenantContext $context, string $tenant, string $match, string $participant): JsonResponse
    {
        $this->gate->authorize('manage', [MatchRecord::class, $context]);

        return MatchReferenceResource::collection($this->service->choices($context, $match, $participant, $request->validated()))->response()->header('Cache-Control', 'private, no-store');
    }

    public function store(MatchLineupRequest $request, TenantContext $context, string $tenant, string $match, string $participant): JsonResponse
    {
        return $this->save($request, $context, $match, $participant, false);
    }

    public function substitute(MatchLineupRequest $request, TenantContext $context, string $tenant, string $match, string $participant): JsonResponse
    {
        return $this->save($request, $context, $match, $participant, true);
    }

    private function save(MatchLineupRequest $request, TenantContext $context, string $match, string $participant, bool $substitution): JsonResponse
    {
        $this->gate->authorize('manage', [MatchRecord::class, $context]);

        return MatchLineupResource::make($this->service->change($context, $match, $participant, $request->validated(), $substitution, $request->ip()))
            ->additional(['notification' => ['type' => 'success', 'message' => __('match_lineups.saved')]])
            ->response()->setStatusCode(201)->header('Cache-Control', 'private, no-store');
    }
}
