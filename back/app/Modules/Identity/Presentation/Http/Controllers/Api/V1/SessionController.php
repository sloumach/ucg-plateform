<?php

namespace App\Modules\Identity\Presentation\Http\Controllers\Api\V1;

use App\Http\Api\ApiResponseFactory;
use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\AuthenticationService;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Presentation\Http\Requests\Api\V1\RevokeOtherSessionsRequest;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;

final class SessionController extends Controller
{
    public function __construct(
        private readonly AuthenticationService $authentication,
        private readonly Gate $gate,
        private readonly ApiResponseFactory $responses,
    ) {}

    public function revokeOthers(RevokeOtherSessionsRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->gate->authorize('view', $user);

        $this->authentication->revokeOtherSessions($user, $request->password(), $request);

        return $this->responses->success($request, notification: [
            'type' => 'success',
            'message' => __('identity.notifications.sessions_revoked'),
        ]);
    }
}
