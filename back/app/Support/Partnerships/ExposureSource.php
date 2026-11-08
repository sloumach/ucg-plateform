<?php

namespace App\Support\Partnerships;

use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;

interface ExposureSource
{
    public function visibleIds(TenantContext $context): ?Builder;

    /** @return LengthAwarePaginator<int, array{id: string, label: string, version: string}> */
    public function paginate(TenantContext $context, int $page, int $perPage): LengthAwarePaginator;

    /** @param list<string> $ids
     * @return array<string, array{id: string, label: string, version: string}> */
    public function metadata(TenantContext $context, array $ids): array;
}
