<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Application\Data\LoginData;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use App\Modules\Identity\Domain\Exceptions\InvalidCredentialsException;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\SecurityEventAction;
use Illuminate\Auth\SessionGuard;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

final readonly class AuthenticationService
{
    public function __construct(
        private UserRepositoryInterface $users,
        private SessionGuard $guard,
        private SecurityAuditService $audit,
        private ConnectionInterface $database,
    ) {}

    /** @throws InvalidCredentialsException */
    public function login(LoginData $credentials, Request $request): User
    {
        $user = $this->users->findByEmail($credentials->email);

        if ($user === null || ! Hash::check($credentials->password, $user->password)) {
            $this->audit->record(
                SecurityEventAction::LoginFailed,
                $request,
                $user,
                $credentials->email,
            );

            throw new InvalidCredentialsException;
        }

        $this->audit->record(SecurityEventAction::LoginSucceeded, $request, $user);
        $this->guard->login($user);
        $request->session()->regenerate();

        return $user;
    }

    public function logout(Request $request): void
    {
        $user = $request->user();
        if ($user instanceof User) {
            $this->audit->record(SecurityEventAction::LogoutSucceeded, $request, $user);
        }

        $this->guard->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    public function revokeOtherSessions(User $user, string $password, Request $request): void
    {
        $this->database->transaction(function () use ($user, $password, $request): void {
            $this->guard->logoutOtherDevices($password);
            $this->audit->record(SecurityEventAction::OtherSessionsRevoked, $request, $user);
        });
    }
}
