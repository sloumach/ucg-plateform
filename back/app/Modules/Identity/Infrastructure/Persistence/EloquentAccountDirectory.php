<?php

namespace App\Modules\Identity\Infrastructure\Persistence;

use App\Modules\Identity\Application\Contracts\AccountDirectory;
use App\Modules\Identity\Domain\Models\User;

final class EloquentAccountDirectory implements AccountDirectory
{
    public function verifiedAccountExists(int $userId): bool
    {
        return User::query()->whereKey($userId)->whereNotNull('email_verified_at')->exists();
    }
}
