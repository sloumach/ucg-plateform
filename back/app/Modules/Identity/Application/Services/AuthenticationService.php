<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Application\Data\LoginData;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use App\Modules\Identity\Domain\Exceptions\InvalidCredentialsException;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

final readonly class AuthenticationService
{
    public function __construct(
        private UserRepositoryInterface $users,
        private StatefulGuard $guard,
    ) {}

    /** @throws InvalidCredentialsException */
    public function login(LoginData $credentials, Request $request): User
    {
        $user = $this->users->findByEmail($credentials->email);

        if ($user === null || ! Hash::check($credentials->password, $user->password)) {
            throw new InvalidCredentialsException;
        }

        $this->guard->login($user);
        $request->session()->regenerate();

        return $user;
    }

    public function logout(Request $request): void
    {
        $this->guard->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
