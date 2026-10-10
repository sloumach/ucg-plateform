<?php

namespace App\Modules\Identity\Presentation\Http\Controllers\Api\V1;

use App\Http\Api\ApiResponseFactory;
use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\EmailVerificationService;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class EmailVerificationController extends Controller
{
    public function __construct(
        private readonly EmailVerificationService $verification,
        private readonly Gate $gate,
        private readonly ApiResponseFactory $responses,
    ) {}

    public function send(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $this->gate->authorize('view', $user);
        $this->verification->send($user, $request);

        return $this->responses->success($request, notification: [
            'type' => 'success',
            'message' => __('identity.notifications.verification_sent'),
        ]);
    }

    public function verify(EmailVerificationRequest $request): JsonResponse|RedirectResponse
    {
        $user = $this->user($request);
        $this->gate->authorize('view', $user);
        $this->verification->verify($user, $request);

        if (! $request->expectsJson()) {
            return redirect()->away(rtrim((string) config('identity.frontend_url'), '/').'/?email_verified=1');
        }

        return $this->responses->success($request, notification: [
            'type' => 'success',
            'message' => __('identity.notifications.email_verified'),
        ]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
