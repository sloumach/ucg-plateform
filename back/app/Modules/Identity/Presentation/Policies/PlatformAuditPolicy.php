<?php

namespace App\Modules\Identity\Presentation\Policies;

use App\Modules\Identity\Application\Services\PlatformAuditService;
use Illuminate\Contracts\Auth\Authenticatable;

final readonly class PlatformAuditPolicy
{
    public function __construct(private PlatformAuditService $audit) {}

    public function viewAny(Authenticatable $user): bool
    {
        return $this->audit->allows((int) $user->getAuthIdentifier());
    }
}
