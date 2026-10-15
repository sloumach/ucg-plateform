<?php

namespace App\Modules\Partnerships\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Partnerships\Application\Services\SponsorService;
use App\Modules\Partnerships\Domain\Models\Sponsor;
use App\Modules\Partnerships\Presentation\Http\Requests\Api\V1\PartnershipReadRequest;
use App\Modules\Partnerships\Presentation\Http\Requests\Api\V1\SavePartnershipRequest;
use App\Modules\Partnerships\Presentation\Http\Resources\Api\V1\SponsorResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;

final class SponsorController extends Controller
{
    public function __construct(private readonly SponsorService $sponsors, private readonly Gate $gate) {}

    public function index(PartnershipReadRequest $request, TenantContext $context): JsonResponse
    {
        $this->gate->authorize('viewAny', [Sponsor::class, $context]);

        return SponsorResource::collection($this->sponsors->paginate($context, $request->validated()))
            ->additional(['meta' => ['request_id' => $context->requestId]])->response()->header('Cache-Control', 'private, no-store');
    }

    public function show(PartnershipReadRequest $request, TenantContext $context, string $tenant, string $sponsor): JsonResponse
    {
        $this->gate->authorize('viewAny', [Sponsor::class, $context]);

        return SponsorResource::make($this->sponsors->find($context, $sponsor))->response()->header('Cache-Control', 'private, no-store');
    }

    public function store(SavePartnershipRequest $request, TenantContext $context): JsonResponse
    {
        $this->gate->authorize('manage', [Sponsor::class, $context]);

        return SponsorResource::make($this->sponsors->save($context, $request->validated(), ipAddress: $request->ip()))
            ->additional(['notification' => ['type' => 'success', 'message' => __('partnerships.saved')]])
            ->response()->setStatusCode(201)->header('Cache-Control', 'private, no-store');
    }

    public function update(SavePartnershipRequest $request, TenantContext $context, string $tenant, string $sponsor): JsonResponse
    {
        $this->gate->authorize('manage', [Sponsor::class, $context]);

        return SponsorResource::make($this->sponsors->save($context, $request->validated(), $sponsor, $request->ip()))
            ->additional(['notification' => ['type' => 'success', 'message' => __('partnerships.saved')]])
            ->response()->header('Cache-Control', 'private, no-store');
    }
}
