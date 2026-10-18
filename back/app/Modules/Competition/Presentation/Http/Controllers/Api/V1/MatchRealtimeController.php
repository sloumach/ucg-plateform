<?php

namespace App\Modules\Competition\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Competition\Application\Services\MatchRealtimeService;
use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Competition\Presentation\Http\Requests\Api\V1\MatchRealtimeRequest;
use App\Modules\Competition\Presentation\Http\Resources\Api\V1\MatchLiveScoreResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Broadcasting\Factory;
use Illuminate\Http\JsonResponse;

final class MatchRealtimeController extends Controller
{
    public function __construct(private readonly Factory $broadcasts, private readonly Gate $gate, private readonly MatchRealtimeService $service) {}

    public function show(MatchRealtimeRequest $request, TenantContext $context, string $tenant, string $match): JsonResponse
    {
        $this->gate->authorize('viewAny', [MatchRecord::class, $context]);

        return MatchLiveScoreResource::make($this->service->snapshot($context, $match))
            ->response()->header('Cache-Control', 'private, no-store');
    }

    public function __invoke(MatchRealtimeRequest $request, TenantContext $context): JsonResponse
    {
        $this->gate->authorize('viewAny', [MatchRecord::class, $context]);

        return response()->json($this->broadcasts->connection('reverb')->auth($request))->header('Cache-Control', 'private, no-store');
    }
}
