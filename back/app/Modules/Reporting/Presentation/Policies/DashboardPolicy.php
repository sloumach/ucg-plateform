<?php

namespace App\Modules\Reporting\Presentation\Policies;

use App\Modules\Reporting\Application\Services\DashboardService;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;

final readonly class DashboardPolicy
{
    public function __construct(private DashboardService $dashboards) {}

    public function viewAny(Authenticatable $user, TenantContext $context): bool
    {
        return (int) $user->getAuthIdentifier() === $context->actorUserId && $this->dashboards->canRead($context);
    }
}
