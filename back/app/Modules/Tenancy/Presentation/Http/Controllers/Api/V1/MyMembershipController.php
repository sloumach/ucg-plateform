<?php

namespace App\Modules\Tenancy\Presentation\Http\Controllers\Api\V1;

use App\Http\Api\RequestId;
use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Application\Services\MembershipService;
use App\Modules\Tenancy\Presentation\Http\Requests\Api\V1\LeaveMembershipRequest;
use App\Modules\Tenancy\Presentation\Http\Resources\Api\V1\MembershipResource;
use App\Modules\Tenancy\Presentation\Support\ActiveOrganizationSession;
use Illuminate\Contracts\Auth\Access\Gate;

final class MyMembershipController extends Controller
{
    public function __construct(private readonly MembershipService $memberships, private readonly Gate $gate, private readonly ActiveOrganizationSession $selection) {}

    public function destroy(LeaveMembershipRequest $request, string $membership): MembershipResource
    {
        $userId = (int) $request->user()?->getAuthIdentifier();
        $record = $this->memberships->findForUser($membership, $userId);
        $this->gate->authorize('leave', $record);
        $this->selection->assertRevision($request);
        $result = $this->memberships->leave($membership, $userId, RequestId::for($request));
        if ($request->hasSession() && $request->session()->get(ActiveOrganizationSession::KEY.'.organization_id') === $result->organization_id) {
            $request->session()->forget(ActiveOrganizationSession::KEY);
        }

        return MembershipResource::make($result)
            ->additional(['notification' => ['type' => 'success', 'message' => __('tenancy.notifications.left')]]);
    }
}
