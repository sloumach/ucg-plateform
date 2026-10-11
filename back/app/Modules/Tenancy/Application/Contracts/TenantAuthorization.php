<?php

namespace App\Modules\Tenancy\Application\Contracts;

interface TenantAuthorization
{
    public function allows(TenantContext $context, string $permission, string $scopeType = 'organization', ?string $scopeId = null): bool;

    public function authorize(TenantContext $context, string $permission, string $scopeType = 'organization', ?string $scopeId = null): void;

    /**
     * Organization-wide snapshot for display; mutations must call authorize again.
     *
     * @return list<string>
     */
    public function permissions(TenantContext $context): array;
}
