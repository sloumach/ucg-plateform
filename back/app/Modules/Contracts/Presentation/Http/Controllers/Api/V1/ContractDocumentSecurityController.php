<?php

namespace App\Modules\Contracts\Presentation\Http\Controllers\Api\V1;

use App\Http\Api\ApiResponseFactory;
use App\Http\Controllers\Controller;
use App\Modules\Contracts\Application\Services\ContractDocumentDownloadService;
use App\Modules\Contracts\Application\Services\ContractDocumentScanService;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\Contracts\Presentation\Http\Requests\Api\V1\ContractDocumentSecurityRequest;
use App\Modules\Contracts\Presentation\Http\Resources\Api\V1\ContractDocumentScanResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

final class ContractDocumentSecurityController extends Controller
{
    public function __construct(private readonly ContractDocumentScanService $scans, private readonly ContractDocumentDownloadService $downloads,
        private readonly Gate $gate, private readonly ApiResponseFactory $responses) {}

    public function index(ContractDocumentSecurityRequest $request, TenantContext $context, string $tenant, string $contract, string $document, string $version): AnonymousResourceCollection
    {
        $this->gate->authorize('viewAny', [ContractRecord::class, $context]);

        return ContractDocumentScanResource::collection($this->scans->paginate($context, $contract, $document, $version, $request->validated()))
            ->additional(['meta' => ['request_id' => $context->requestId]]);
    }

    public function scan(ContractDocumentSecurityRequest $request, TenantContext $context, string $tenant, string $contract, string $document, string $version): ContractDocumentScanResource
    {
        $this->gate->authorize('manage', [ContractRecord::class, $context]);

        return ContractDocumentScanResource::make($this->scans->request($context, $contract, $document, $version, $request->validated()))
            ->additional(['notification' => ['type' => 'success', 'message' => __('document_security.queued')]]);
    }

    public function link(ContractDocumentSecurityRequest $request, TenantContext $context, string $tenant, string $contract, string $document, string $version): JsonResponse
    {
        $this->gate->authorize('viewAny', [ContractRecord::class, $context]);

        return $this->responses->success($request, $this->downloads->link($context, $contract, $document, $version, $request->validated('expected_scan_id'))->toArray(),
            notification: ['type' => 'success', 'message' => __('document_security.link')]);
    }

    public function download(ContractDocumentSecurityRequest $request, TenantContext $context, string $tenant, string $contract, string $document, string $version): Response
    {
        $this->gate->authorize('viewAny', [ContractRecord::class, $context]);
        $file = $this->downloads->download($context, $contract, $document, $version, $request->validated('scan'), (int) $request->validated('expires'));

        return new Response($file->contents, 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $file->filename, 'document.pdf'),
            'Cache-Control' => 'private, no-store', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'; frame-ancestors 'none'",
            'X-Frame-Options' => 'DENY', 'Referrer-Policy' => 'no-referrer']);
    }
}
