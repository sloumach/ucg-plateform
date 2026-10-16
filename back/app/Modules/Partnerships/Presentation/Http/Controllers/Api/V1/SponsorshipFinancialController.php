<?php

namespace App\Modules\Partnerships\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Partnerships\Application\Services\SponsorshipFinancialService;
use App\Modules\Partnerships\Domain\Models\SponsorshipAgreement;
use App\Modules\Partnerships\Presentation\Http\Requests\Api\V1\SponsorshipFinancialRequest;
use App\Modules\Partnerships\Presentation\Http\Resources\Api\V1\SponsorshipFinancialResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;

final class SponsorshipFinancialController extends Controller
{
    public function __construct(private readonly SponsorshipFinancialService $financial, private readonly Gate $gate) {}

    public function show(SponsorshipFinancialRequest $request, TenantContext $context, string $tenant, string $sponsor, string $agreement): JsonResponse
    {
        $this->gate->authorize('financialRead', [SponsorshipAgreement::class, $context]);

        return SponsorshipFinancialResource::make($this->financial->read($context, $sponsor, $agreement))->response()->header('Cache-Control', 'private, no-store');
    }

    public function update(SponsorshipFinancialRequest $request, TenantContext $context, string $tenant, string $sponsor, string $agreement): JsonResponse
    {
        $this->gate->authorize('financialWrite', [SponsorshipAgreement::class, $context]);

        return SponsorshipFinancialResource::make($this->financial->save($context, $sponsor, $agreement, $request->validated(), $request->ip()))
            ->additional(['notification' => ['type' => 'success', 'message' => __('sponsorships.financial_saved')]])
            ->response()->header('Cache-Control', 'private, no-store');
    }
}
