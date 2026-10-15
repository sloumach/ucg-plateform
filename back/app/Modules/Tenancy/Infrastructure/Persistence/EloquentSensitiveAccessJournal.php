<?php

namespace App\Modules\Tenancy\Infrastructure\Persistence;

use App\Modules\Tenancy\Application\Contracts\TenantSensitiveAccessJournal;
use App\Modules\Tenancy\Domain\Models\SensitiveAccessEvent;
use App\Support\Audit\SensitiveAccessData;

final class EloquentSensitiveAccessJournal implements TenantSensitiveAccessJournal
{
    public function record(string $organizationId, SensitiveAccessData $data): void
    {
        (new SensitiveAccessEvent)->forceFill(['organization_id' => strtolower($organizationId), ...$data->attributes()])->save();
    }
}
