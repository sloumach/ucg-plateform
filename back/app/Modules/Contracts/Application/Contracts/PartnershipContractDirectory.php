<?php

namespace App\Modules\Contracts\Application\Contracts;

use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface PartnershipContractDirectory
{
    /** Administrative access remains required; only sponsor contract references, never titles, parties or amounts.
     * @return LengthAwarePaginator<int, array{id: string, label: string}>
     */
    public function paginate(TenantContext $context, int $page, int $perPage): LengthAwarePaginator;

    /** Returns the server version after administrative authorization and tenant/type verification. */
    public function bind(TenantContext $context, string $id): string;

    /** Internal boolean check only, after the caller's partnership authorization. Never returns contract data. */
    public function isCurrent(TenantContext $context, string $id, string $version): bool;
}
