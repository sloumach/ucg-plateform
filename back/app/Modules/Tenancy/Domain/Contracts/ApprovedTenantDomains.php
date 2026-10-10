<?php

namespace App\Modules\Tenancy\Domain\Contracts;

interface ApprovedTenantDomains
{
    public function organizationIdFor(string $host): ?string;
}
