<?php

namespace App\Modules\Identity\Presentation\Http\Middleware;

use App\Http\Api\ApiResponseFactory;
use App\Modules\Identity\Application\Services\MfaRequirementService;
use App\Modules\Identity\Application\Services\MfaSession;
use App\Modules\Identity\Domain\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequireMfa
{
    public function __construct(
        private MfaRequirementService $requirements,
        private MfaSession $sessions,
        private ApiResponseFactory $responses,
    ) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        if (($user->hasMfa() || $this->requirements->required($user)) && ! $this->sessions->verified($user, $request)) {
            $code = $user->hasMfa() ? 'MFA_CHALLENGE_REQUIRED' : 'MFA_ENROLLMENT_REQUIRED';

            return $this->responses->error($request, __('identity.mfa.'.($user->hasMfa() ? 'challenge_required' : 'enrollment_required')), $code, 403);
        }

        return $next($request);
    }
}
