<?php

namespace App\Modules\Contracts\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Contracts\Application\Services\ContractService;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\Contracts\Presentation\Http\Requests\Api\V1\ContractObligationRequest;
use App\Modules\Contracts\Presentation\Http\Requests\Api\V1\ContractReadRequest;
use App\Modules\Contracts\Presentation\Http\Requests\Api\V1\ContractTransitionRequest;
use App\Modules\Contracts\Presentation\Http\Requests\Api\V1\SaveContractRequest;
use App\Modules\Contracts\Presentation\Http\Resources\Api\V1\ContractPersonResource;
use App\Modules\Contracts\Presentation\Http\Resources\Api\V1\ContractResource;
use App\Modules\Contracts\Presentation\Http\Resources\Api\V1\ContractTransitionResource;
use App\Modules\People\Application\Contracts\AdministrativePeopleDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ContractController extends Controller
{
    public function __construct(private readonly ContractService $contracts, private readonly AdministrativePeopleDirectory $people, private readonly Gate $gate) {}

    public function index(ContractReadRequest $request, TenantContext $context): AnonymousResourceCollection
    {
        $this->gate->authorize('viewAny', [ContractRecord::class, $context]);

        return ContractResource::collection($this->contracts->paginate($context, $request->validated()))->additional(['meta' => ['request_id' => $context->requestId]]);
    }

    public function people(ContractReadRequest $request, TenantContext $context): AnonymousResourceCollection
    {
        $this->gate->authorize('viewAny', [ContractRecord::class, $context]);

        return ContractPersonResource::collection($this->people->paginate($context, (int) $request->validated('page', 1), (int) $request->validated('per_page', 10)))
            ->additional(['meta' => ['request_id' => $context->requestId]]);
    }

    public function show(ContractReadRequest $request, TenantContext $context, string $tenant, string $contract): ContractResource
    {
        $this->gate->authorize('viewAny', [ContractRecord::class, $context]);

        return ContractResource::make($this->contracts->find($context, $contract));
    }

    public function store(SaveContractRequest $request, TenantContext $context): JsonResponse
    {
        $this->gate->authorize('manage', [ContractRecord::class, $context]);

        return $this->result($this->contracts->save($context, $request->validated(), ipAddress: $request->ip()), 'saved')->response()->setStatusCode(201);
    }

    public function update(SaveContractRequest $request, TenantContext $context, string $tenant, string $contract): ContractResource
    {
        $this->gate->authorize('manage', [ContractRecord::class, $context]);

        return $this->result($this->contracts->save($context, $request->validated(), $contract, $request->ip()), 'saved');
    }

    public function transition(ContractTransitionRequest $request, TenantContext $context, string $tenant, string $contract): ContractResource
    {
        $this->gate->authorize('transition', [ContractRecord::class, $context, $request->validated('status')]);

        return $this->result($this->contracts->transition($context, $contract, $request->validated(), $request->ip()), 'transitioned');
    }

    public function obligation(ContractObligationRequest $request, TenantContext $context, string $tenant, string $contract, string $obligation): ContractResource
    {
        $this->gate->authorize('manage', [ContractRecord::class, $context]);

        return $this->result($this->contracts->updateObligation($context, $contract, $obligation, $request->validated(), $request->ip()), 'obligation_saved');
    }

    public function transitions(ContractReadRequest $request, TenantContext $context, string $tenant, string $contract): AnonymousResourceCollection
    {
        $this->gate->authorize('viewAny', [ContractRecord::class, $context]);

        return ContractTransitionResource::collection($this->contracts->transitions($context, $contract, $request->validated()))->additional(['meta' => ['request_id' => $context->requestId]]);
    }

    private function result(ContractRecord $contract, string $message): ContractResource
    {
        return ContractResource::make($contract)->additional(['notification' => ['type' => 'success', 'message' => __('managed_contracts.'.$message)]]);
    }
}
