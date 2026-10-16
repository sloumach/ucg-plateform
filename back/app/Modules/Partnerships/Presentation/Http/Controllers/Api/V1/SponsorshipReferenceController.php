<?php

namespace App\Modules\Partnerships\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Partnerships\Application\Services\SponsorshipReferenceService;
use App\Modules\Partnerships\Domain\Models\SponsorshipAgreement;
use App\Modules\Partnerships\Presentation\Http\Requests\Api\V1\SponsorshipReferenceRequest;
use App\Modules\Partnerships\Presentation\Http\Resources\Api\V1\SponsorshipReferenceResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;

final class SponsorshipReferenceController extends Controller
{
    public function __construct(private readonly SponsorshipReferenceService $references, private readonly Gate $gate) {}

    public function index(SponsorshipReferenceRequest $request, TenantContext $context, string $tenant, string $kind): JsonResponse
    {
        $this->gate->authorize('viewAny', [SponsorshipAgreement::class, $context]);

        return SponsorshipReferenceResource::collection($this->references->paginate($context, $kind, (int) $request->validated('page', 1), (int) $request->validated('per_page', 10)))
            ->additional(['meta' => ['request_id' => $context->requestId]])->response()->header('Cache-Control', 'private, no-store');
    }
}
