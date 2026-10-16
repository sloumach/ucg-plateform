<?php

namespace App\Modules\People\Application\Contracts;

use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface PartnershipPeopleDirectory
{
    /** @return LengthAwarePaginator<int, array{id: string, label: string}> */
    public function paginate(TenantContext $context, int $page, int $perPage): LengthAwarePaginator;

    /** Active public labels only, after partnership authorization; no HR fields.
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    public function labels(TenantContext $context, array $ids): array;
}
