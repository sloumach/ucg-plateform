<?php

namespace App\Modules\Partnerships\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Partnerships\Application\Services\SponsorshipDeliverableService;
use App\Modules\Partnerships\Domain\Models\SponsorshipAgreement;
use App\Modules\Partnerships\Presentation\Http\Requests\Api\V1\SponsorshipDeliverableRequest;
use App\Modules\Partnerships\Presentation\Http\Requests\Api\V1\SponsorshipDeliverableTransitionRequest;
use App\Modules\Partnerships\Presentation\Http\Requests\Api\V1\SponsorshipDeliveryRequest;
use App\Modules\Partnerships\Presentation\Http\Resources\Api\V1\SponsorshipDeliverableResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;

final class SponsorshipDeliverableController extends Controller
{
    public function __construct(private readonly SponsorshipDeliverableService $deliverables, private readonly Gate $gate) {}

    public function index(SponsorshipDeliverableRequest $request, TenantContext $context, string $tenant, string $sponsor, string $agreement, string $campaign): JsonResponse
    {
        $this->gate->authorize('viewAny', [SponsorshipAgreement::class, $context]);

        return SponsorshipDeliverableResource::collection($this->deliverables->paginate($context, $sponsor, $agreement, $campaign, $request->validated()))
            ->additional(['meta' => ['request_id' => $context->requestId]])->response()->header('Cache-Control', 'private, no-store');
    }

    public function store(SponsorshipDeliverableRequest $request, TenantContext $context, string $tenant, string $sponsor, string $agreement, string $campaign): JsonResponse
    {
        $this->gate->authorize('manage', [SponsorshipAgreement::class, $context]);

        return $this->response($this->deliverables->save($context, $sponsor, $agreement, $campaign, $request->validated(), ip: $request->ip()), 'deliverable_saved', 201);
    }

    public function update(SponsorshipDeliverableRequest $request, TenantContext $context, string $tenant, string $sponsor, string $agreement, string $campaign, string $deliverable): JsonResponse
    {
        $this->gate->authorize('manage', [SponsorshipAgreement::class, $context]);

        return $this->response($this->deliverables->save($context, $sponsor, $agreement, $campaign, $request->validated(), $deliverable, $request->ip()), 'deliverable_saved');
    }

    public function deliver(SponsorshipDeliveryRequest $request, TenantContext $context, string $tenant, string $sponsor, string $agreement, string $campaign, string $deliverable): JsonResponse
    {
        $this->gate->authorize('manage', [SponsorshipAgreement::class, $context]);

        return $this->response($this->deliverables->deliver($context, $sponsor, $agreement, $campaign, $deliverable, $request->validated(), $request->ip()), 'delivered');
    }

    public function transition(SponsorshipDeliverableTransitionRequest $request, TenantContext $context, string $tenant, string $sponsor, string $agreement, string $campaign, string $deliverable): JsonResponse
    {
        $this->gate->authorize($request->validated('target_status') === 'cancelled' ? 'manage' : 'validate', [SponsorshipAgreement::class, $context]);

        return $this->response($this->deliverables->transition($context, $sponsor, $agreement, $campaign, $deliverable, $request->validated(), $request->ip()), 'transitioned');
    }

    /** @param array<string,mixed> $data */
    private function response(array $data, string $message, int $status = 200): JsonResponse
    {
        return SponsorshipDeliverableResource::make($data)->additional(['notification' => ['type' => 'success', 'message' => __('campaigns.'.$message)]])
            ->response()->setStatusCode($status)->header('Cache-Control', 'private, no-store');
    }
}
