<?php

namespace App\Modules\Tenancy\Application\Contracts;

use Illuminate\Database\Query\Builder;

interface ContextualAuthorization
{
    /** Null means organization-wide access; otherwise the subquery restricts objects before pagination. */
    public function ids(TenantContext $context, string $type, string $permission, bool $scopedOnly = false): ?Builder;

    /** Entry-point check only; object operations must call TenantAuthorization with their exact scope. */
    public function any(TenantContext $context, string $type, string $permission): bool;
}
