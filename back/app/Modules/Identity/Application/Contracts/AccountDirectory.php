<?php

namespace App\Modules\Identity\Application\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;

interface AccountDirectory
{
    /** @return LengthAwarePaginator<int, array{id: int, name: string, email: string}> */
    public function searchVerifiedAccounts(Builder $eligibleIds, ?string $search, int $perPage, int $page): LengthAwarePaginator;

    /** @param list<int> $ids
     * @return array<int, string> Verified account display names only; no email, MFA secret or HR data.
     */
    public function displayNames(array $ids): array;

    /** Locks any account for cleanup, even if email verification was withdrawn. */
    public function lockAccountEmail(int $userId): ?string;

    public function verifiedAccountExists(int $userId): bool;

    public function hasConfirmedMfa(int $userId): bool;

    public function verifiedEmail(int $userId): ?string;

    /** Must be called inside the same database transaction as the tenant admission. */
    public function lockVerifiedEmail(int $userId): ?string;
}
