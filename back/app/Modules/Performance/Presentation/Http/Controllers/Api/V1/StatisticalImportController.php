<?php

namespace App\Modules\Performance\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Performance\Application\Services\StatisticalImportService;
use App\Modules\Performance\Domain\Models\StatisticalImport;
use App\Modules\Performance\Presentation\Http\Requests\Api\V1\StatisticalImportRequest as Request;
use App\Modules\Performance\Presentation\Http\Resources\Api\V1\StatisticalImportResource as Resource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;

final class StatisticalImportController extends Controller
{
    public function __construct(private readonly StatisticalImportService $service, private readonly Gate $gate) {}

    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $this->gate->authorize('manage', [StatisticalImport::class, $context]);
        $list = $this->service->index($context, (int) $request->validated('page', 1));
        $list->withPath($request->url())->appends($request->validated());

        return Resource::collection($list)->additional(['meta' => ['request_id' => $context->requestId]])->response()->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, TenantContext $context, string $tenant, string $import): Resource
    {
        $this->gate->authorize('manage', [StatisticalImport::class, $context]);

        return Resource::make($this->service->show($context, $import, (int) $request->validated('page', 1)))
            ->additional(['meta' => ['request_id' => $context->requestId]]);
    }

    public function store(Request $request, TenantContext $context): Resource
    {
        $this->gate->authorize('manage', [StatisticalImport::class, $context]);
        $run = $this->service->upload($context, $request->file('file')->getContent(), $request->validated('delimiter'));

        return Resource::make($this->service->show($context, $run->id))
            ->additional(['meta' => ['request_id' => $context->requestId], 'notification' => ['type' => 'success', 'message' => __('statistical_imports.saved')]]);
    }
}
