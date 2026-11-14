<?php

namespace App\Modules\Identity\Infrastructure\Persistence;

use App\Modules\Identity\Application\Contracts\ClientAccountProvisioner;
use App\Modules\Identity\Domain\Contracts\SecurityAuditRepository;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\SecurityEventAction;

final readonly class EloquentClientAccountProvisioner implements ClientAccountProvisioner
{
    public function __construct(private SecurityAuditRepository $audit) {}

    public function emailExists(string $email): bool
    {
        return User::query()->whereRaw('LOWER(email) = ?', [$email])->exists();
    }

    public function createVerified(string $name, string $email, string $password, string $requestId): int
    {
        $user = new User;
        $user->fill(['name' => $name, 'email' => $email, 'password' => $password]);
        $user->email_verified_at = now();
        $user->save();
        $this->audit->record(SecurityEventAction::EmailVerified, $requestId, $user->id, null, null, null);

        return $user->id;
    }
}
