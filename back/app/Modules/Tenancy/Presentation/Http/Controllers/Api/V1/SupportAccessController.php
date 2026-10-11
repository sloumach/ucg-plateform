<?php

namespace App\Modules\Tenancy\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Application\Services\SupportAccessService;
use App\Modules\Tenancy\Domain\Models\OrganizationSupportAccess;
use App\Modules\Tenancy\Presentation\Http\Requests\Api\V1\OpenSupportAccessRequest;
use App\Modules\Tenancy\Presentation\Http\Requests\Api\V1\SupportAccessRequest;
use App\Modules\Tenancy\Presentation\Http\Resources\Api\V1\SupportAccessResource;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class SupportAccessController extends Controller
{
    public function __construct(private readonly SupportAccessService $support, private readonly Gate $gate) {}

    public function index(SupportAccessRequest $request): AnonymousResourceCollection
    {
        $this->gate->authorize('operate', OrganizationSupportAccess::class);

        return SupportAccessResource::collection($this->support->listing((int) $request->user()?->getAuthIdentifier(),
            $request->integer('per_page', 20), $request->integer('page', 1)));
    }

    public function store(OpenSupportAccessRequest $request, string $tenant): SupportAccessResource
    {
        $this->gate->authorize('operate', OrganizationSupportAccess::class);

        return SupportAccessResource::make($this->support->open($tenant, $request->toData(), $request->auditData()))
            ->additional(['notification' => ['type' => 'success', 'message' => __('platform.access_opened')]]);
    }

    public function destroy(SupportAccessRequest $request, string $tenant, string $access): SupportAccessResource
    {
        $this->gate->authorize('operate', OrganizationSupportAccess::class);

        return SupportAccessResource::make($this->support->revoke($tenant, $access, $request->auditData()))
            ->additional(['notification' => ['type' => 'success', 'message' => __('platform.access_revoked')]]);
    }
}
