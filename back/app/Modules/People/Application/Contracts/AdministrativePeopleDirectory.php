<?php

namespace App\Modules\People\Application\Contracts;

use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface AdministrativePeopleDirectory
{
    /** Tenant administrative references, including retained people; never grants account access.
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    public function labels(TenantContext $context, array $ids): array;

    /** @return LengthAwarePaginator<int, array{id: string, label: string}> */
    public function paginate(TenantContext $context, int $page, int $perPage): LengthAwarePaginator;
}
