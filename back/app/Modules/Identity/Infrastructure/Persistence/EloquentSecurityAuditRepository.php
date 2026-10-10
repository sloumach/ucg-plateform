<?php

namespace App\Modules\Identity\Infrastructure\Persistence;

use App\Modules\Identity\Domain\Contracts\SecurityAuditRepository;
use App\Modules\Identity\Domain\Models\IdentitySecurityEvent;
use App\Modules\Identity\Domain\SecurityEventAction;

final class EloquentSecurityAuditRepository implements SecurityAuditRepository
{
    public function record(
        SecurityEventAction $action,
        string $requestId,
        ?int $userId,
        ?string $subjectHash,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        (new IdentitySecurityEvent)->forceFill([
            'action' => $action->value,
            'request_id' => $requestId,
            'user_id' => $userId,
            'subject_hash' => $subjectHash,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'occurred_at' => now(),
        ])->save();
    }
}
