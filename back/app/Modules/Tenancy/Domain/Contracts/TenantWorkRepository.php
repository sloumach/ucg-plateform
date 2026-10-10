<?php

namespace App\Modules\Tenancy\Domain\Contracts;

use App\Modules\Tenancy\Domain\Models\Organization;

interface TenantWorkRepository
{
    public function find(string $organizationId): Organization;

    /** @return list<string> */
    public function activeIdsAfter(?string $after, int $limit): array;
}
