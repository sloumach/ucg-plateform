<?php

namespace App\Modules\Identity\Presentation\Http\Middleware;

use App\Modules\Identity\Application\Services\MfaSession;
use App\Modules\Identity\Domain\Contracts\MfaRepository;
use App\Modules\Identity\Domain\Models\User;
use Closure;
use Illuminate\Auth\AuthManager;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ValidateIdentitySession
{
    public function __construct(private MfaRepository $accounts, private MfaSession $sessions, private SessionGuard $guard, private AuthManager $auth) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $account = $this->accounts->find((int) $user->getKey());
        $user->setRawAttributes($account->getAttributes(), true);
        $account = $user;
        if (! $this->sessions->current($account, $request)) {
            $this->guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $this->auth->forgetGuards();
            abort(401);
        }
        $this->guard->setUser($account);
        $request->setUserResolver(static fn () => $account);

        return $next($request);
    }
}
