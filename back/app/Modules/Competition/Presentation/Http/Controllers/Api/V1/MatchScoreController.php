<?php

namespace App\Modules\Competition\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Competition\Application\Services\MatchScoreService;
use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Competition\Presentation\Http\Requests\Api\V1\MatchScoreRequest;
use App\Modules\Competition\Presentation\Http\Resources\Api\V1\MatchGameResource;
use App\Modules\Competition\Presentation\Http\Resources\Api\V1\MatchScoreResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;

final class MatchScoreController extends Controller
{
    public function __construct(private readonly MatchScoreService $service, private readonly Gate $gate) {}

    public function index(MatchScoreRequest $request, TenantContext $context, string $tenant, string $match): JsonResponse
    {
        $this->gate->authorize('viewAny', [MatchRecord::class, $context]);
        $result = $this->service->index($context, $match, $request->validated());

        return MatchGameResource::collection($result['games'])->additional(['scorecard' => MatchScoreResource::make($result)])
            ->response()->header('Cache-Control', 'private, no-store');
    }

    public function configure(MatchScoreRequest $request, TenantContext $context, string $tenant, string $match): JsonResponse
    {
        return $this->save($request, $context, $match, true);
    }

    public function store(MatchScoreRequest $request, TenantContext $context, string $tenant, string $match): JsonResponse
    {
        return $this->save($request, $context, $match, false);
    }

    private function save(MatchScoreRequest $request, TenantContext $context, string $match, bool $configure): JsonResponse
    {
        $this->gate->authorize('manage', [MatchRecord::class, $context]);

        return MatchScoreResource::make($this->service->save($context, $match, $request->validated(), $configure, $request->ip()))
            ->additional(['notification' => ['type' => 'success', 'message' => __('match_scores.saved')]])
            ->response()->setStatusCode(201)->header('Cache-Control', 'private, no-store');
    }
}
