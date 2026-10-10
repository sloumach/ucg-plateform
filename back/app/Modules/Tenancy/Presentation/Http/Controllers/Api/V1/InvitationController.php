<?php

namespace App\Modules\Tenancy\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Services\InvitationService;
use App\Modules\Tenancy\Presentation\Http\Requests\Api\V1\InviteOrganizationRequest;
use App\Modules\Tenancy\Presentation\Http\Requests\Api\V1\MembershipListRequest;
use App\Modules\Tenancy\Presentation\Http\Requests\Api\V1\TenancyActionRequest;
use App\Modules\Tenancy\Presentation\Http\Resources\Api\V1\InvitationResource;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class InvitationController extends Controller
{
    public function __construct(private readonly InvitationService $invitations, private readonly Gate $gate) {}

    public function index(MembershipListRequest $request, TenantContext $context): AnonymousResourceCollection
    {
        return InvitationResource::collection($this->invitations->list($context, $request->integer('per_page', 20), $request->integer('page', 1)));
    }

    public function store(InviteOrganizationRequest $request, TenantContext $context): InvitationResource
    {
        return InvitationResource::make($this->invitations->invite($context, $request->toData()))
            ->additional(['notification' => ['type' => 'success', 'message' => __('tenancy.notifications.invited')]]);
    }

    public function destroy(TenancyActionRequest $request, TenantContext $context, string $tenant, string $invitation): InvitationResource
    {
        $this->gate->authorize('manage', $context);

        return InvitationResource::make($this->invitations->revoke($context, $invitation))
            ->additional(['notification' => ['type' => 'success', 'message' => __('tenancy.notifications.revoked')]]);
    }
}
