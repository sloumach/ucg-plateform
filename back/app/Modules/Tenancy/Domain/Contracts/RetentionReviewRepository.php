<?php

namespace App\Modules\Tenancy\Domain\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface RetentionReviewRepository
{
    public function assertCatalog(): void;

    /** @param list<string> $tables
     * @return array<string, int>
     */
    public function counts(string $organizationId, array $tables): array;

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function people(string $organizationId, int $perPage, int $page): LengthAwarePaginator;

    /** @param list<string> $personIds
     * @return array<string, array<string, int>>
     */
    public function dependencies(string $organizationId, array $personIds): array;
}
