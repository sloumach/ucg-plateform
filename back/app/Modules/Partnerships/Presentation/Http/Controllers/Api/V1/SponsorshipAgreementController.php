<?php

namespace App\Modules\Partnerships\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Partnerships\Application\Services\SponsorshipAgreementService;
use App\Modules\Partnerships\Application\Services\SponsorshipProjectionService;
use App\Modules\Partnerships\Domain\Models\SponsorshipAgreement;
use App\Modules\Partnerships\Presentation\Http\Requests\Api\V1\SaveSponsorshipAgreementRequest;
use App\Modules\Partnerships\Presentation\Http\Requests\Api\V1\SponsorshipReadRequest;
use App\Modules\Partnerships\Presentation\Http\Requests\Api\V1\SponsorshipTransitionRequest;
use App\Modules\Partnerships\Presentation\Http\Resources\Api\V1\SponsorshipAgreementResource;
use App\Modules\Partnerships\Presentation\Http\Resources\Api\V1\SponsorshipHistoryResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;

final class SponsorshipAgreementController extends Controller
{
    public function __construct(private readonly SponsorshipAgreementService $agreements,
        private readonly SponsorshipProjectionService $projection, private readonly Gate $gate) {}

    public function index(SponsorshipReadRequest $request, TenantContext $context, string $tenant, string $sponsor): JsonResponse
    {
        $this->gate->authorize('viewAny', [SponsorshipAgreement::class, $context]);
        $page = $this->agreements->paginate($context, $sponsor, $request->validated());
        $page->setCollection(collect($this->projection->many($context, array_values($page->items()))));

        return SponsorshipAgreementResource::collection($page)->additional(['meta' => ['request_id' => $context->requestId]])
            ->response()->header('Cache-Control', 'private, no-store');
    }

    public function show(SponsorshipReadRequest $request, TenantContext $context, string $tenant, string $sponsor, string $agreement): JsonResponse
    {
        $this->gate->authorize('viewAny', [SponsorshipAgreement::class, $context]);

        return SponsorshipAgreementResource::make($this->projection->project($context, $this->agreements->find($context, $sponsor, $agreement)))
            ->response()->header('Cache-Control', 'private, no-store');
    }

    public function store(SaveSponsorshipAgreementRequest $request, TenantContext $context, string $tenant, string $sponsor): JsonResponse
    {
        $this->gate->authorize('manage', [SponsorshipAgreement::class, $context]);
        $record = $this->agreements->save($context, $sponsor, $request->validated(), ip: $request->ip());

        return SponsorshipAgreementResource::make($this->projection->project($context, $record))
            ->additional(['notification' => ['type' => 'success', 'message' => __('sponsorships.saved')]])
            ->response()->setStatusCode(201)->header('Cache-Control', 'private, no-store');
    }

    public function update(SaveSponsorshipAgreementRequest $request, TenantContext $context, string $tenant, string $sponsor, string $agreement): JsonResponse
    {
        $this->gate->authorize('manage', [SponsorshipAgreement::class, $context]);
        $record = $this->agreements->save($context, $sponsor, $request->validated(), $agreement, $request->ip());

        return SponsorshipAgreementResource::make($this->projection->project($context, $record))
            ->additional(['notification' => ['type' => 'success', 'message' => __('sponsorships.saved')]])
            ->response()->header('Cache-Control', 'private, no-store');
    }

    public function transition(SponsorshipTransitionRequest $request, TenantContext $context, string $tenant, string $sponsor, string $agreement): JsonResponse
    {
        $this->gate->authorize($request->validated('target_status') === 'archived' ? 'manage' : 'validate', [SponsorshipAgreement::class, $context]);
        $record = $this->agreements->transition($context, $sponsor, $agreement, $request->validated(), $request->ip());

        return SponsorshipAgreementResource::make($this->projection->project($context, $record))
            ->additional(['notification' => ['type' => 'success', 'message' => __('sponsorships.transitioned')]])
            ->response()->header('Cache-Control', 'private, no-store');
    }

    public function history(SponsorshipReadRequest $request, TenantContext $context, string $tenant, string $sponsor, string $agreement): JsonResponse
    {
        $this->gate->authorize('viewAny', [SponsorshipAgreement::class, $context]);

        return SponsorshipHistoryResource::collection($this->agreements->history($context, $sponsor, $agreement, $request->validated()))
            ->additional(['meta' => ['request_id' => $context->requestId]])->response()->header('Cache-Control', 'private, no-store');
    }
}
