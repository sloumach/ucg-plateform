<?php

namespace App\Modules\Contracts\Presentation\Http\Controllers\Api\V1;

use App\Http\Api\ApiResponseFactory;
use App\Http\Controllers\Controller;
use App\Modules\Contracts\Application\Services\ContractAmendmentApprovalService;
use App\Modules\Contracts\Application\Services\ContractFinancialService;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\Contracts\Presentation\Http\Requests\Api\V1\ContractGovernanceRequest;
use App\Modules\Contracts\Presentation\Http\Resources\Api\V1\ContractAmendmentApprovalResource;
use App\Modules\Contracts\Presentation\Http\Resources\Api\V1\ContractFinancialTermsResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ContractGovernanceController extends Controller
{
    public function __construct(private readonly ContractFinancialService $financial, private readonly ContractAmendmentApprovalService $approvals,
        private readonly Gate $gate, private readonly ApiResponseFactory $responses) {}

    public function financial(ContractGovernanceRequest $request, TenantContext $context, string $tenant, string $contract, ?string $document = null): ContractFinancialTermsResource|JsonResponse
    {
        $this->gate->authorize('financialRead', [ContractRecord::class, $context]);
        $terms = $this->financial->read($context, $contract, $document);

        return $terms === null ? $this->responses->success($request)->header('Cache-Control', 'private, no-store') : ContractFinancialTermsResource::make($terms);
    }

    public function saveFinancial(ContractGovernanceRequest $request, TenantContext $context, string $tenant, string $contract, ?string $document = null): ContractFinancialTermsResource
    {
        $this->gate->authorize('financialWrite', [ContractRecord::class, $context]);
        $result = $this->financial->save($context, $contract, $request->validated(), $document, $request->ip());

        return ContractFinancialTermsResource::make($result['terms'])->additional($this->extra('financial_saved', $result['contract_version']));
    }

    public function approvals(ContractGovernanceRequest $request, TenantContext $context, string $tenant, string $contract, string $document): AnonymousResourceCollection
    {
        $this->gate->authorize('viewAny', [ContractRecord::class, $context]);

        return ContractAmendmentApprovalResource::collection($this->approvals->history($context, $contract, $document, $request->validated()))
            ->additional(['meta' => ['request_id' => $context->requestId]]);
    }

    public function approve(ContractGovernanceRequest $request, TenantContext $context, string $tenant, string $contract, string $document): ContractAmendmentApprovalResource
    {
        $this->gate->authorize('approveAmendment', [ContractRecord::class, $context]);
        $result = $this->approvals->approve($context, $contract, $document, $request->validated(), $request->ip());

        return ContractAmendmentApprovalResource::make($result['approval'])->additional($this->extra('amendment_approved', $result['contract_version']));
    }

    /** @return array<string, mixed> */
    private function extra(string $key, string $version): array
    {
        return ['meta' => ['contract_version' => $version], 'notification' => ['type' => 'success', 'message' => __('managed_contracts.'.$key)]];
    }
}
