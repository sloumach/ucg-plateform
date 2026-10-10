<?php

namespace App\Modules\Identity\Presentation\Http\Controllers\Api\V1;

use App\Http\Api\ApiResponseFactory;
use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\PasswordRecoveryService;
use App\Modules\Identity\Presentation\Http\Requests\Api\V1\ForgotPasswordRequest;
use App\Modules\Identity\Presentation\Http\Requests\Api\V1\ResetPasswordRequest;
use Illuminate\Http\JsonResponse;

final class PasswordRecoveryController extends Controller
{
    public function __construct(
        private readonly PasswordRecoveryService $passwords,
        private readonly ApiResponseFactory $responses,
    ) {}

    public function requestLink(ForgotPasswordRequest $request): JsonResponse
    {
        $this->passwords->requestResetLink($request->email(), $request);

        return $this->responses->success($request, notification: [
            'type' => 'success',
            'message' => __('identity.notifications.password_link_requested'),
        ]);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $this->passwords->reset($request->credentials(), $request);

        return $this->responses->success($request, notification: [
            'type' => 'success',
            'message' => __('identity.notifications.password_reset_succeeded'),
        ]);
    }
}
