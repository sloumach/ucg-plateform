<?php

namespace App\Modules\Identity\Infrastructure\Persistence;

use App\Modules\Identity\Application\Contracts\AccountDirectory;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Str;

final class EloquentAccountDirectory implements AccountDirectory
{
    public function verifiedAccountExists(int $userId): bool
    {
        return User::query()->whereKey($userId)->whereNotNull('email_verified_at')->exists();
    }

    public function verifiedEmail(int $userId): ?string
    {
        $email = User::query()->whereKey($userId)->whereNotNull('email_verified_at')->value('email');

        return is_string($email) ? Str::lower(trim($email)) : null;
    }

    public function lockVerifiedEmail(int $userId): ?string
    {
        $account = User::query()->whereKey($userId)->whereNotNull('email_verified_at')->lockForUpdate()->first();

        return $account === null ? null : Str::lower(trim($account->email));
    }
}
