<?php

namespace App\Modules\People\Application\Contracts;

use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface SportingPeopleDirectory
{
    /** @return LengthAwarePaginator<int,array{id:string,label:string}> */
    public function choices(TenantContext $context, int $page, int $perPage): LengthAwarePaginator;

    /** @param list<string> $ids
     * @return array<string,array{label:string,version:string}>
     */
    public function snapshots(TenantContext $context, array $ids): array;

    /** Locks the person under the tenant business guard; rejects departed/former people. */
    public function assertAssignable(TenantContext $context, string $personId): void;

    /** @param list<string> $ids
     * @return array<string,string>
     */
    public function pseudonyms(TenantContext $context, array $ids): array;

    /** Internal account correlation for scope authorization; no identity projection. */
    public function personForAccount(string $organizationId, int $userId): ?string;
}
