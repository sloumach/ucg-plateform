<?php

namespace App\Modules\Tenancy\Infrastructure\Persistence;

use App\Modules\Tenancy\Domain\Contracts\AccessAuditRepository;
use App\Modules\Tenancy\Domain\Models\OrganizationAccessEvent;

final class EloquentAccessAuditRepository implements AccessAuditRepository
{
    /** @param array<string, mixed> $attributes */
    public function record(array $attributes): void
    {
        (new OrganizationAccessEvent)->forceFill($attributes)->save();
    }
}
