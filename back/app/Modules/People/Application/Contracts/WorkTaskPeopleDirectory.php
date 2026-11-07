<?php

namespace App\Modules\People\Application\Contracts;

use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface WorkTaskPeopleDirectory
{
    /** @return LengthAwarePaginator<int, array{id: string, label: string}> */
    public function choices(TenantContext $context, int $page, int $perPage): LengthAwarePaginator;

    public function assertAssignable(TenantContext $context, string $id): void;

    /** @param list<string> $ids
     * @return array<string, string> */
    public function labels(TenantContext $context, array $ids): array;
}
