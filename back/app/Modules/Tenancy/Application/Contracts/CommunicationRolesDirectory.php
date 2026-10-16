<?php

namespace App\Modules\Tenancy\Application\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface CommunicationRolesDirectory
{
    /** @return list<string> */
    public function current(TenantContext $context): array;

    /** @return LengthAwarePaginator<int, array{id: string, label: string}> */
    public function choices(TenantContext $context, int $page, int $perPage): LengthAwarePaginator;

    public function assertActive(TenantContext $context, string $id): void;
}
