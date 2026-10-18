<?php

namespace App\Modules\Competition\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Competition\Application\Services\MatchCorrectionService;
use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Competition\Presentation\Http\Requests\Api\V1\MatchCorrectionRequest;
use App\Modules\Competition\Presentation\Http\Resources\Api\V1\MatchCorrectionResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;

final class MatchCorrectionController extends Controller
{
    public function __construct(private readonly MatchCorrectionService $service, private readonly Gate $gate) {}

    public function index(MatchCorrectionRequest $request, TenantContext $context, string $tenant, string $match): JsonResponse
    {
        $this->gate->authorize('viewAny', [MatchRecord::class, $context]);
        $data = $this->service->index($context, $match, $request->validated());
        $history = $data['history'];
        unset($data['history']);

        return MatchCorrectionResource::collection($history)->additional(['corrections' => $data])->response()->header('Cache-Control', 'private, no-store');
    }

    public function store(MatchCorrectionRequest $request, TenantContext $context, string $tenant, string $match): JsonResponse
    {
        $this->gate->authorize('correctScore', [MatchRecord::class, $context]);

        return MatchCorrectionResource::make($this->service->request($context, $match, $request->validated(), $request->ip()))
            ->additional(['notification' => ['type' => 'success', 'message' => __('match_corrections.saved')]])->response()->setStatusCode(202)->header('Cache-Control', 'private, no-store');
    }

    public function retry(MatchCorrectionRequest $request, TenantContext $context, string $tenant, string $match, string $correction): JsonResponse
    {
        $this->gate->authorize('correctScore', [MatchRecord::class, $context]);

        return MatchCorrectionResource::make($this->service->retry($context, $match, $correction, $request->validated(), $request->ip()))
            ->additional(['notification' => ['type' => 'success', 'message' => __('match_corrections.retry')]])->response()->setStatusCode(202)->header('Cache-Control', 'private, no-store');
    }
}
