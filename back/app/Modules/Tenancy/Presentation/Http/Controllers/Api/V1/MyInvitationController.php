<?php

namespace App\Modules\Tenancy\Presentation\Http\Controllers\Api\V1;

use App\Http\Api\RequestId;
use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Application\Services\InvitationService;
use App\Modules\Tenancy\Presentation\Http\Requests\Api\V1\InvitationResponseRequest;
use App\Modules\Tenancy\Presentation\Http\Requests\Api\V1\ListOrganizationsRequest;
use App\Modules\Tenancy\Presentation\Http\Resources\Api\V1\InvitationResource;
use App\Modules\Tenancy\Presentation\Support\ActiveOrganizationSession;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class MyInvitationController extends Controller
{
    public function __construct(private readonly InvitationService $invitations, private readonly Gate $gate, private readonly ActiveOrganizationSession $selection) {}

    public function index(ListOrganizationsRequest $request): AnonymousResourceCollection
    {
        return InvitationResource::collection($this->invitations->inbox((int) $request->user()?->getAuthIdentifier(),
            $request->integer('per_page', 20), $request->integer('page', 1)));
    }

    public function update(InvitationResponseRequest $request, string $invitation): InvitationResource
    {
        $userId = (int) $request->user()?->getAuthIdentifier();
        $record = $this->invitations->findForRecipient($invitation, $userId);
        $this->gate->authorize('respond', $record);
        $this->selection->assertRevision($request);

        return InvitationResource::make($this->invitations->respond($invitation, $userId, $request->string('decision')->toString(), RequestId::for($request)))
            ->additional(['notification' => ['type' => 'success', 'message' => __('tenancy.notifications.responded')]]);
    }
}
