<?php

namespace App\Modules\Tenancy\Application\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;

interface AuthorizationScopeSource extends AuthorizationScopeDirectory
{
    /** Additional dated binding restriction; null means no extra binding is required. */
    public function eligibleAssignmentIds(string $org, string $type, int $userId): ?Builder;

    /** Tenant-owned, currently usable objects; columns id and label only. */
    public function sources(string $organizationId, string $type): Builder;

    /** @return LengthAwarePaginator<int, \stdClass> */
    public function choices(TenantContext $context, string $type, int $page, int $perPage, ?Builder $visible = null): LengthAwarePaginator;
}
