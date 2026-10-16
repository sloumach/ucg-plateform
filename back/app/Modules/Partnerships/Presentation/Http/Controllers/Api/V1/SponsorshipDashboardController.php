<?php

namespace App\Modules\Partnerships\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Partnerships\Application\Services\SponsorshipDeliverableService;
use App\Modules\Partnerships\Domain\Models\SponsorshipAgreement;
use App\Modules\Partnerships\Presentation\Http\Requests\Api\V1\SponsorshipCampaignRequest;
use App\Modules\Partnerships\Presentation\Http\Resources\Api\V1\SponsorshipDeliverableResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;

final class SponsorshipDashboardController extends Controller
{
    public function __construct(private readonly SponsorshipDeliverableService $deliverables, private readonly Gate $gate) {}

    public function index(SponsorshipCampaignRequest $request, TenantContext $context): JsonResponse
    {
        $this->gate->authorize('viewAny', [SponsorshipAgreement::class, $context]);
        $result = $this->deliverables->dashboard($context, $request->validated());

        return SponsorshipDeliverableResource::collection($result['page'])->additional(['summary' => $result['summary'], 'meta' => ['request_id' => $context->requestId]])
            ->response()->header('Cache-Control', 'private, no-store');
    }
}
