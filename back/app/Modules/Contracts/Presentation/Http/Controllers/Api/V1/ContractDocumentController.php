<?php

namespace App\Modules\Contracts\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Contracts\Application\Services\ContractDocumentService;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\Contracts\Presentation\Http\Requests\Api\V1\ContractDocumentRequest;
use App\Modules\Contracts\Presentation\Http\Resources\Api\V1\ContractDocumentResource;
use App\Modules\Contracts\Presentation\Http\Resources\Api\V1\ContractDocumentVersionResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ContractDocumentController extends Controller
{
    public function __construct(private readonly ContractDocumentService $documents, private readonly Gate $gate) {}

    public function index(ContractDocumentRequest $request, TenantContext $context, string $tenant, string $contract): AnonymousResourceCollection
    {
        $this->gate->authorize('viewAny', [ContractRecord::class, $context]);

        return ContractDocumentResource::collection($this->documents->paginate($context, $contract, $request->validated()))
            ->additional(['meta' => ['request_id' => $context->requestId]]);
    }

    public function store(ContractDocumentRequest $request, TenantContext $context, string $tenant, string $contract): JsonResponse
    {
        $this->gate->authorize('manage', [ContractRecord::class, $context]);
        $result = $this->documents->create($context, $contract, $request->validated(), $request->ip());

        return ContractDocumentResource::make($result['document'])->additional($this->extra('created', $result['contract_version']))->response()->setStatusCode(201);
    }

    public function versions(ContractDocumentRequest $request, TenantContext $context, string $tenant, string $contract, string $document): AnonymousResourceCollection
    {
        $this->gate->authorize('viewAny', [ContractRecord::class, $context]);

        return ContractDocumentVersionResource::collection($this->documents->versions($context, $contract, $document, $request->validated()))
            ->additional(['meta' => ['request_id' => $context->requestId]]);
    }

    public function upload(ContractDocumentRequest $request, TenantContext $context, string $tenant, string $contract, string $document): JsonResponse
    {
        $this->gate->authorize($request->validated('purpose') === 'signed_copy' ? 'transition' : 'manage',
            $request->validated('purpose') === 'signed_copy' ? [ContractRecord::class, $context, 'signed'] : [ContractRecord::class, $context]);
        $result = $this->documents->upload($context, $contract, $document, $request->validated(), $request->ip());

        return ContractDocumentVersionResource::make($result['version'])->additional($this->extra('uploaded', $result['contract_version']))->response()->setStatusCode(201);
    }

    public function sign(ContractDocumentRequest $request, TenantContext $context, string $tenant, string $contract, string $document): ContractDocumentResource
    {
        $this->gate->authorize('transition', [ContractRecord::class, $context, 'signed']);
        $result = $this->documents->sign($context, $contract, $document, $request->validated(), $request->ip());

        return ContractDocumentResource::make($result['document'])->additional($this->extra('signed', $result['contract_version']));
    }

    /** @return array<string, mixed> */
    private function extra(string $key, string $version): array
    {
        return ['meta' => ['contract_version' => $version], 'notification' => ['type' => 'success', 'message' => __('contract_documents.'.$key)]];
    }
}
