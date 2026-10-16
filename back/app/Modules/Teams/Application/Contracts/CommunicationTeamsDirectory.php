<?php

namespace App\Modules\Teams\Application\Contracts;

use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface CommunicationTeamsDirectory
{
    /** @return array{teams: list<string>, games: list<string>} */
    public function current(TenantContext $context): array;

    /** @return LengthAwarePaginator<int, array{id: string, label: string}> */
    public function choices(TenantContext $context, string $type, int $page, int $perPage): LengthAwarePaginator;

    public function assertActive(TenantContext $context, string $type, string $id): void;
}
