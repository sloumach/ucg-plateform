<?php

namespace App\Modules\Tenancy\Application\Contracts;

interface TenantBusinessGuard
{
    /**
     * Called in the business transaction, before resource locks and authorization.
     * A requested account must be verified and already effective in this tenant.
     * This operation never creates an admission or grants any permission.
     */
    public function lock(TenantContext $context, ?int $accountId = null): void;
}
