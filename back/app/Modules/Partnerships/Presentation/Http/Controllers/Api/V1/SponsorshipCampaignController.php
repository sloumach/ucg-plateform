<?php

namespace App\Modules\Partnerships\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Partnerships\Application\Services\SponsorshipCampaignProjection;
use App\Modules\Partnerships\Application\Services\SponsorshipCampaignService;
use App\Modules\Partnerships\Domain\Models\SponsorshipAgreement;
use App\Modules\Partnerships\Presentation\Http\Requests\Api\V1\SponsorshipCampaignRequest;
use App\Modules\Partnerships\Presentation\Http\Resources\Api\V1\SponsorshipCampaignEventResource;
use App\Modules\Partnerships\Presentation\Http\Resources\Api\V1\SponsorshipCampaignResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;

final class SponsorshipCampaignController extends Controller
{
    public function __construct(private readonly SponsorshipCampaignService $campaigns, private readonly SponsorshipCampaignProjection $projection, private readonly Gate $gate) {}

    public function index(SponsorshipCampaignRequest $request, TenantContext $context, string $tenant, string $sponsor, string $agreement): JsonResponse
    {
        $this->gate->authorize('viewAny', [SponsorshipAgreement::class, $context]);
        $page = $this->campaigns->paginate($context, $sponsor, $agreement, $request->validated());
        $page->setCollection(collect($this->projection->campaigns($context, array_values($page->items()))));

        return SponsorshipCampaignResource::collection($page)->additional(['meta' => ['request_id' => $context->requestId]])->response()->header('Cache-Control', 'private, no-store');
    }

    public function show(SponsorshipCampaignRequest $request, TenantContext $context, string $tenant, string $sponsor, string $agreement, string $campaign): JsonResponse
    {
        $this->gate->authorize('viewAny', [SponsorshipAgreement::class, $context]);
        $record = $this->campaigns->find($context, $sponsor, $agreement, $campaign);

        return SponsorshipCampaignResource::make($this->projection->campaigns($context, [$record])[0])->response()->header('Cache-Control', 'private, no-store');
    }

    public function store(SponsorshipCampaignRequest $request, TenantContext $context, string $tenant, string $sponsor, string $agreement): JsonResponse
    {
        $this->gate->authorize('manage', [SponsorshipAgreement::class, $context]);
        $record = $this->campaigns->save($context, $sponsor, $agreement, $request->validated(), ip: $request->ip());

        return SponsorshipCampaignResource::make($this->projection->campaigns($context, [$record])[0])->additional(['notification' => ['type' => 'success', 'message' => __('campaigns.saved')]])
            ->response()->setStatusCode(201)->header('Cache-Control', 'private, no-store');
    }

    public function update(SponsorshipCampaignRequest $request, TenantContext $context, string $tenant, string $sponsor, string $agreement, string $campaign): JsonResponse
    {
        $this->gate->authorize('manage', [SponsorshipAgreement::class, $context]);
        $record = $this->campaigns->save($context, $sponsor, $agreement, $request->validated(), $campaign, $request->ip());

        return SponsorshipCampaignResource::make($this->projection->campaigns($context, [$record])[0])->additional(['notification' => ['type' => 'success', 'message' => __('campaigns.saved')]])
            ->response()->header('Cache-Control', 'private, no-store');
    }

    public function history(SponsorshipCampaignRequest $request, TenantContext $context, string $tenant, string $sponsor, string $agreement, string $campaign): JsonResponse
    {
        $this->gate->authorize('viewAny', [SponsorshipAgreement::class, $context]);

        return SponsorshipCampaignEventResource::collection($this->campaigns->history($context, $sponsor, $agreement, $campaign, $request->validated()))
            ->additional(['meta' => ['request_id' => $context->requestId]])->response()->header('Cache-Control', 'private, no-store');
    }
}
