<?php

namespace App\Modules\Identity\Application\Contracts;

interface AccountDirectory
{
    /** Locks any account for cleanup, even if email verification was withdrawn. */
    public function lockAccountEmail(int $userId): ?string;

    public function verifiedAccountExists(int $userId): bool;

    public function hasConfirmedMfa(int $userId): bool;

    public function verifiedEmail(int $userId): ?string;

    /** Must be called inside the same database transaction as the tenant admission. */
    public function lockVerifiedEmail(int $userId): ?string;
}
