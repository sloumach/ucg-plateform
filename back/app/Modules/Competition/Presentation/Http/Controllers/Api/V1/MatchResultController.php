<?php

namespace App\Modules\Competition\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Competition\Application\Services\MatchResultService;
use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Competition\Presentation\Http\Requests\Api\V1\MatchResultRequest;
use App\Modules\Competition\Presentation\Http\Resources\Api\V1\MatchResultEventResource;
use App\Modules\Competition\Presentation\Http\Resources\Api\V1\MatchResultResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;

final class MatchResultController extends Controller
{
    public function __construct(private readonly MatchResultService $service, private readonly Gate $gate) {}

    public function index(MatchResultRequest $request, TenantContext $context, string $tenant, string $match): JsonResponse
    {
        $this->gate->authorize('viewAny', [MatchRecord::class, $context]);
        $result = $this->service->index($context, $match, $request->validated());

        return MatchResultEventResource::collection($result['history'])->additional(['result' => MatchResultResource::make($result)])
            ->response()->header('Cache-Control', 'private, no-store');
    }

    public function submit(MatchResultRequest $request, TenantContext $context, string $tenant, string $match): JsonResponse
    {
        return $this->change($request, $context, $match, 'submit');
    }

    public function approve(MatchResultRequest $request, TenantContext $context, string $tenant, string $match): JsonResponse
    {
        return $this->change($request, $context, $match, 'approve');
    }

    public function dispute(MatchResultRequest $request, TenantContext $context, string $tenant, string $match): JsonResponse
    {
        return $this->change($request, $context, $match, 'dispute');
    }

    private function change(MatchResultRequest $request, TenantContext $context, string $match, string $action): JsonResponse
    {
        $this->gate->authorize(match ($action) {
            'submit' => 'manage', 'approve' => 'validateResult', default => 'disputeResult'
        }, [MatchRecord::class, $context]);

        return MatchResultResource::make($this->service->change($context, $match, $request->validated(), $action, $request->ip()))
            ->additional(['notification' => ['type' => 'success', 'message' => __('match_results.saved')]])
            ->response()->setStatusCode($action === 'submit' ? 201 : 200)->header('Cache-Control', 'private, no-store');
    }
}
