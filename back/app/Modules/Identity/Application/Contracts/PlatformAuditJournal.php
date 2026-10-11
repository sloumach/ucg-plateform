<?php

namespace App\Modules\Identity\Application\Contracts;

use App\Support\Audit\AuditFilter;
use App\Support\Audit\AuditPage;
use App\Support\Audit\AuditWriteData;

interface PlatformAuditJournal
{
    public function read(AuditFilter $filter): AuditPage;

    public function record(AuditWriteData $data): void;
}
