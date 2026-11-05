<?php

namespace App\Support\Communication;

use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;

interface ContextSource
{
    /** Null means the actor currently lacks read permission. */
    public function visibleIds(TenantContext $context): ?Builder;

    /** @return LengthAwarePaginator<int, array{id: string, label: string}> */
    public function choices(TenantContext $context, int $page, int $perPage): LengthAwarePaginator;

    /** @return array{id: string, label: string, version: string} */
    public function resolve(TenantContext $context, string $id): array;
}
