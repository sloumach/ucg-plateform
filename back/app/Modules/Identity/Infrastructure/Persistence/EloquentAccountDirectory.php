<?php

namespace App\Modules\Identity\Infrastructure\Persistence;

use App\Modules\Identity\Application\Contracts\AccountDirectory;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Str;

final class EloquentAccountDirectory implements AccountDirectory
{
    public function lockAccountEmail(int $userId): ?string
    {
        $account = User::query()->whereKey($userId)->lockForUpdate()->first();

        return $account === null ? null : Str::lower(trim($account->email));
    }

    public function hasConfirmedMfa(int $userId): bool
    {
        return User::query()->whereKey($userId)->whereNotNull('mfa_confirmed_at')
            ->whereNotNull('mfa_secret')->whereNotNull('mfa_version')->exists();
    }

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
