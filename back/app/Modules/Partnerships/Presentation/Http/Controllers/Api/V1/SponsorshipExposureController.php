<?php

namespace App\Modules\Partnerships\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Partnerships\Application\Services\SponsorshipExposureService;
use App\Modules\Partnerships\Domain\Models\SponsorshipAgreement;
use App\Modules\Partnerships\Presentation\Http\Requests\Api\V1\SponsorshipExposureRequest;
use App\Modules\Partnerships\Presentation\Http\Resources\Api\V1\SponsorshipExposureResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;

final class SponsorshipExposureController extends Controller
{
    public function __construct(private readonly SponsorshipExposureService $exposures, private readonly Gate $gate) {}

    public function index(SponsorshipExposureRequest $request, TenantContext $context, string $tenant, string $sponsor, string $agreement): JsonResponse
    {
        $this->gate->authorize('viewAny', [SponsorshipAgreement::class, $context]);

        return SponsorshipExposureResource::collection($this->exposures->paginate($context, $sponsor, $agreement, $request->validated()))
            ->additional(['meta' => ['request_id' => $context->requestId]])->response()->header('Cache-Control', 'private, no-store');
    }

    public function store(SponsorshipExposureRequest $request, TenantContext $context, string $tenant, string $sponsor, string $agreement): JsonResponse
    {
        $this->gate->authorize('manage', [SponsorshipAgreement::class, $context]);

        return SponsorshipExposureResource::make($this->exposures->save($context, $sponsor, $agreement, $request->validated(), ip: $request->ip()))
            ->additional(['notification' => ['type' => 'success', 'message' => __('sponsorships.exposure_saved')]])
            ->response()->setStatusCode(201)->header('Cache-Control', 'private, no-store');
    }

    public function update(SponsorshipExposureRequest $request, TenantContext $context, string $tenant, string $sponsor, string $agreement, string $exposure): JsonResponse
    {
        $this->gate->authorize('manage', [SponsorshipAgreement::class, $context]);

        return SponsorshipExposureResource::make($this->exposures->save($context, $sponsor, $agreement, $request->validated(), $exposure, $request->ip()))
            ->additional(['notification' => ['type' => 'success', 'message' => __('sponsorships.exposure_saved')]])
            ->response()->header('Cache-Control', 'private, no-store');
    }
}
