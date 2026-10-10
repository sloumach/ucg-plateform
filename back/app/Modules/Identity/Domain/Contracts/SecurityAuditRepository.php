<?php

namespace App\Modules\Identity\Domain\Contracts;

use App\Modules\Identity\Domain\SecurityEventAction;

interface SecurityAuditRepository
{
    public function record(
        SecurityEventAction $action,
        string $requestId,
        ?int $userId,
        ?string $subjectHash,
        ?string $ipAddress,
        ?string $userAgent,
    ): void;
}
