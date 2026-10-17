<?php

namespace App\Modules\People\Application\Contracts;

use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface AvailabilityPeopleDirectory
{
    /** @return array{person_id:string|null,timezone:string} */
    public function viewer(TenantContext $context): array;

    /** @return LengthAwarePaginator<int,array{id:string,label:string}> */
    public function choices(TenantContext $context, int $page, int $perPage): LengthAwarePaginator;

    public function assertAssignable(TenantContext $context, string $personId): void;

    /** @param list<string> $ids
     * @return array<string,string>
     */
    public function labels(TenantContext $context, array $ids): array;
}
