<?php

namespace App\Modules\Partnerships\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Partnerships\Application\Services\SponsorRelationshipService;
use App\Modules\Partnerships\Domain\Models\Sponsor;
use App\Modules\Partnerships\Presentation\Http\Requests\Api\V1\PartnershipReadRequest;
use App\Modules\Partnerships\Presentation\Http\Requests\Api\V1\SavePartnershipRequest;
use App\Modules\Partnerships\Presentation\Http\Resources\Api\V1\SponsorContactResource;
use App\Modules\Partnerships\Presentation\Http\Resources\Api\V1\SponsorshipInteractionResource;
use App\Modules\Partnerships\Presentation\Http\Resources\Api\V1\SponsorshipOpportunityResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;

final class SponsorRelationshipController extends Controller
{
    public function __construct(private readonly SponsorRelationshipService $relationships, private readonly Gate $gate) {}

    public function index(PartnershipReadRequest $request, TenantContext $context, string $tenant, string $sponsor, string $kind): JsonResponse
    {
        $this->gate->authorize('viewAny', [Sponsor::class, $context]);
        $resource = $this->resource($kind);

        return $resource::collection($this->relationships->paginate($context, $sponsor, $kind, $request->validated()))
            ->additional(['meta' => ['request_id' => $context->requestId]])->response()->header('Cache-Control', 'private, no-store');
    }

    public function store(SavePartnershipRequest $request, TenantContext $context, string $tenant, string $sponsor, string $kind): JsonResponse
    {
        $this->gate->authorize('manage', [Sponsor::class, $context]);
        $resource = $this->resource($kind);

        return $resource::make($this->relationships->save($context, $sponsor, $kind, $request->validated(), ipAddress: $request->ip()))
            ->additional(['notification' => ['type' => 'success', 'message' => $this->message($kind)]])
            ->response()->setStatusCode(201)->header('Cache-Control', 'private, no-store');
    }

    public function update(SavePartnershipRequest $request, TenantContext $context, string $tenant, string $sponsor, string $kind, string $record): JsonResponse
    {
        $this->gate->authorize('manage', [Sponsor::class, $context]);
        $resource = $this->resource($kind);

        return $resource::make($this->relationships->save($context, $sponsor, $kind, $request->validated(), $record, $request->ip()))
            ->additional(['notification' => ['type' => 'success', 'message' => $this->message($kind)]])
            ->response()->header('Cache-Control', 'private, no-store');
    }

    /** @return class-string<SponsorContactResource>|class-string<SponsorshipOpportunityResource>|class-string<SponsorshipInteractionResource> */
    private function resource(string $kind): string
    {
        return match ($kind) {
            'contacts' => SponsorContactResource::class, 'opportunities' => SponsorshipOpportunityResource::class,
            default => SponsorshipInteractionResource::class
        };
    }

    private function message(string $kind): string
    {
        return __(match ($kind) {
            'contacts' => 'partnerships.contact_saved', 'opportunities' => 'partnerships.opportunity_saved',
            default => 'partnerships.interaction_saved'
        });
    }
}
