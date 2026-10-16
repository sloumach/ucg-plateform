<?php

namespace App\Modules\Teams\Application\Contracts;

use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface MediaTeamsDirectory
{
    /** @return LengthAwarePaginator<int, array{id: string, label: string}> */
    public function paginate(TenantContext $context, int $page, int $perPage): LengthAwarePaginator;

    /** @param list<string> $ids
     * @return array<string, string>
     */
    public function labels(TenantContext $context, array $ids): array;

    /** Called under the tenant business lock; no sporting or HR payload crosses this boundary. */
    public function assertActive(TenantContext $context, string $id): void;
}
