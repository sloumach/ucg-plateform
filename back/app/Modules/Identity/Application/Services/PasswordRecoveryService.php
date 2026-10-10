<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\SecurityEventAction;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\PasswordBrokerFactory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final readonly class PasswordRecoveryService
{
    public function __construct(
        private PasswordBrokerFactory $passwords,
        private UserRepositoryInterface $users,
        private SecurityAuditService $audit,
        private ConnectionInterface $database,
        private SessionGuard $guard,
    ) {}

    public function requestResetLink(string $email, Request $request): void
    {
        $user = $this->users->findByEmail($email);
        $this->audit->record(SecurityEventAction::PasswordResetRequested, $request, $user, $email);
        $this->passwords->broker()->sendResetLink(['email' => $email]);
    }

    /** @param array{token: string, email: string, password: string, password_confirmation: string} $credentials */
    public function reset(array $credentials, Request $request): void
    {
        $status = $this->passwords->broker()->reset(
            $credentials,
            function (object $account, string $password) use ($request): void {
                if (! $account instanceof User) {
                    throw new UnexpectedValueException('The configured password broker returned an unsupported account type.');
                }

                $this->database->transaction(function () use ($account, $password, $request): void {
                    $account->forceFill([
                        'password' => $password,
                        'remember_token' => Str::random(60),
                    ])->save();

                    $this->audit->record(SecurityEventAction::PasswordResetSucceeded, $request, $account);
                });

                event(new PasswordReset($account));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'token' => [__('identity.password_reset.token.invalid')],
            ]);
        }

        $this->guard->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
