<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\SecurityEventAction;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;

final readonly class EmailVerificationService
{
    public function __construct(
        private SecurityAuditService $audit,
        private ConnectionInterface $database,
    ) {}

    public function send(User $user, Request $request): void
    {
        if ($user->hasVerifiedEmail()) {
            return;
        }

        $this->audit->record(SecurityEventAction::EmailVerificationRequested, $request, $user);
        $user->sendEmailVerificationNotification();
    }

    public function verify(User $user, Request $request): void
    {
        if ($user->hasVerifiedEmail()) {
            return;
        }

        $verified = $this->database->transaction(function () use ($user, $request): bool {
            if (! $user->markEmailAsVerified()) {
                return false;
            }

            $this->audit->record(SecurityEventAction::EmailVerified, $request, $user);

            return true;
        });

        if ($verified) {
            event(new Verified($user));
        }
    }
}
