<?php

namespace App\Modules\Tenancy\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Application\Services\SupportAccessService;
use App\Modules\Tenancy\Domain\Models\OrganizationSupportAccess;
use App\Modules\Tenancy\Domain\SupportAction;
use App\Modules\Tenancy\Presentation\Http\Requests\Api\V1\SupportAccessRequest;
use App\Modules\Tenancy\Presentation\Http\Resources\Api\V1\SupportDiagnosticResource;
use Illuminate\Contracts\Auth\Access\Gate;

final class SupportDiagnosticController extends Controller
{
    public function __construct(private readonly SupportAccessService $support, private readonly Gate $gate) {}

    public function organization(SupportAccessRequest $request, string $tenant, string $access): SupportDiagnosticResource
    {
        return $this->result($request, $tenant, $access, SupportAction::OrganizationInspect);
    }

    public function accessSummary(SupportAccessRequest $request, string $tenant, string $access): SupportDiagnosticResource
    {
        return $this->result($request, $tenant, $access, SupportAction::AccessInspect);
    }

    private function result(SupportAccessRequest $request, string $tenant, string $access, SupportAction $action): SupportDiagnosticResource
    {
        $this->gate->authorize('operate', OrganizationSupportAccess::class);

        return SupportDiagnosticResource::make($this->support->inspect($tenant, $access, $action, $request->auditData()));
    }
}
