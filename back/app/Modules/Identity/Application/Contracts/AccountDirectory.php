<?php

namespace App\Modules\Identity\Application\Contracts;

interface AccountDirectory
{
    public function verifiedAccountExists(int $userId): bool;

    public function verifiedEmail(int $userId): ?string;

    /** Must be called inside the same database transaction as the tenant admission. */
    public function lockVerifiedEmail(int $userId): ?string;
}
