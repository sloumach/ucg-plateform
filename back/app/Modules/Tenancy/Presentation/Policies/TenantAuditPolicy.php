<?php

namespace App\Modules\Tenancy\Presentation\Policies;

use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Services\TenantAuditService;
use Illuminate\Contracts\Auth\Authenticatable;

final readonly class TenantAuditPolicy
{
    public function __construct(private TenantAuditService $audit) {}

    public function viewAny(Authenticatable $user, TenantContext $context): bool
    {
        return (int) $user->getAuthIdentifier() === $context->actorUserId && $this->audit->allows($context);
    }
}
