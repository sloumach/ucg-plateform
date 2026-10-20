<?php

namespace App\Modules\Reporting\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Reporting\Application\Services\TenantSearchService;
use App\Modules\Reporting\Presentation\Http\Requests\Api\V1\TenantSearchRequest;
use App\Modules\Reporting\Presentation\Http\Resources\Api\V1\TenantSearchResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;

final class TenantSearchController extends Controller
{
    public function __construct(private readonly TenantSearchService $search, private readonly Gate $gate) {}

    public function types(TenantSearchRequest $request, TenantContext $context, string $tenant): TenantSearchResource
    {
        $this->gate->authorize('search.viewAny', $context);

        return TenantSearchResource::make(['types' => $this->search->types($context)])->additional(['meta' => ['request_id' => $context->requestId]]);
    }

    public function index(TenantSearchRequest $request, TenantContext $context, string $tenant): TenantSearchResource
    {
        $this->gate->authorize('search.viewAny', $context);

        return TenantSearchResource::make($this->search->search($context, $request->validated()))->additional(['meta' => ['request_id' => $context->requestId]]);
    }
}
