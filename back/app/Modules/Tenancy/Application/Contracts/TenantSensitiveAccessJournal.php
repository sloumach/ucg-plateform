<?php

namespace App\Modules\Tenancy\Application\Contracts;

use App\Support\Audit\SensitiveAccessData;

interface TenantSensitiveAccessJournal
{
    /** Trusted, authorized server operations only. Failure must prevent sensitive data disclosure. */
    public function record(string $organizationId, SensitiveAccessData $data): void;
}
