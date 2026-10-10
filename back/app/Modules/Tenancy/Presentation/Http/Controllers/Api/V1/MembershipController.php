<?php

namespace App\Modules\Tenancy\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Services\MembershipService;
use App\Modules\Tenancy\Presentation\Http\Requests\Api\V1\MembershipListRequest;
use App\Modules\Tenancy\Presentation\Http\Requests\Api\V1\UpdateMembershipRequest;
use App\Modules\Tenancy\Presentation\Http\Resources\Api\V1\MembershipResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class MembershipController extends Controller
{
    public function __construct(private readonly MembershipService $memberships) {}

    public function index(MembershipListRequest $request, TenantContext $context): AnonymousResourceCollection
    {
        return MembershipResource::collection($this->memberships->list($context, $request->integer('per_page', 20), $request->integer('page', 1)));
    }

    public function update(UpdateMembershipRequest $request, TenantContext $context, string $tenant, string $membership): MembershipResource
    {
        return MembershipResource::make($this->memberships->update($context, $membership, $request->toData()))
            ->additional(['notification' => ['type' => 'success', 'message' => __('tenancy.notifications.membership_updated')]]);
    }
}
