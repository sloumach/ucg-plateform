<?php

namespace App\Modules\Identity\Infrastructure\Persistence;

use App\Modules\Identity\Application\Contracts\AccountDirectory;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Str;

final class EloquentAccountDirectory implements AccountDirectory
{
    public function searchVerifiedAccounts(QueryBuilder $eligibleIds, ?string $search, int $perPage, int $page): LengthAwarePaginator
    {
        $accounts = User::query()->whereIn('id', $eligibleIds)->whereNotNull('email_verified_at');
        if ($search !== null && $search !== '') {
            $pattern = '%'.strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            $accounts->where(fn (Builder $query) => $query
                ->whereRaw("LOWER(name) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                ->orWhereRaw("LOWER(email) LIKE LOWER(?) ESCAPE '!'", [$pattern]));
        }

        return $accounts->orderByRaw('LOWER(name)')->orderBy('id')->paginate($perPage, ['id', 'name', 'email'], 'page', $page)
            ->through(fn (User $account): array => ['id' => (int) $account->getKey(), 'name' => $account->name, 'email' => $account->email]);
    }

    public function displayNames(array $ids): array
    {
        if (count($ids) > 100) {
            throw new \LogicException('Account labels require a bounded page.');
        }

        return User::query()->whereIn('id', $ids)->whereNotNull('email_verified_at')->pluck('name', 'id')->all();
    }

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
