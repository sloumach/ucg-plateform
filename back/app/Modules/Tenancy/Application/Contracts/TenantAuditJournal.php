<?php

namespace App\Modules\Tenancy\Application\Contracts;

use App\Support\Audit\AuditFilter;
use App\Support\Audit\AuditPage;
use App\Support\Audit\AuditWriteData;

interface TenantAuditJournal
{
    public function read(string $organizationId, AuditFilter $filter): AuditPage;

    public function record(string $organizationId, AuditWriteData $data): void;
}
