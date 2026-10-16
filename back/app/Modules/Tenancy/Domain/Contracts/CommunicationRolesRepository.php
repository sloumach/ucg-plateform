<?php

namespace App\Modules\Tenancy\Domain\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface CommunicationRolesRepository
{
    /** @return list<string> */
    public function current(string $org, int $userId, ?string $membershipId): array;

    /** @return LengthAwarePaginator<int, array{id: string, label: string}> */
    public function choices(string $org, int $page, int $perPage): LengthAwarePaginator;

    public function assertActive(string $org, string $id): void;
}
