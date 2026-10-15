<?php

namespace App\Modules\Tenancy\Application\Contracts;

interface TenantNotificationRecipientDirectory
{
    /** Resolves current membership, verified account, MFA and explicit permissions. No owner impersonation.
     * @param  list<string>  $permissions
     */
    public function email(string $organizationId, int $accountId, string $requestId, array $permissions): ?string;
}
