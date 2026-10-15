<?php

namespace App\Modules\Tenancy\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditEntryResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Services\TenantAuditService;
use App\Modules\Tenancy\Domain\Models\OrganizationAuditEvent;
use App\Modules\Tenancy\Presentation\Http\Requests\Api\V1\TenantAuditRequest;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;

final class TenantAuditController extends Controller
{
    public function __construct(private readonly TenantAuditService $audit, private readonly Gate $gate) {}

    public function index(TenantAuditRequest $request, TenantContext $context): JsonResponse
    {
        $this->gate->authorize('viewAny', [OrganizationAuditEvent::class, $context]);

        return AuditEntryResource::page($this->audit->listing($context, $request->toFilter(), $request->ip()), $request)
            ->response()->header('Cache-Control', 'private, no-store');
    }
}
