<?php

namespace App\Modules\Identity\Domain\Contracts;

use App\Modules\Identity\Domain\Models\User;

interface MfaRepository
{
    public function find(int $userId): User;

    /** Called in a transaction to serialize enrollment, recovery and TOTP reuse checks. */
    public function lock(int $userId): User;

    public function save(User $user): void;

    public function revokeOtherSessions(int $userId, string $currentSessionId): void;
}
