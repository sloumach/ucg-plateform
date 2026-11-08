<?php

namespace App\Modules\Academy\Presentation\Policies;

use App\Modules\Tenancy\Application\Contracts\ContextualAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;

final readonly class AcademySessionPolicy
{
    public function __construct(private TenantAuthorization $authorization, private ContextualAuthorization $scoped) {}

    public function viewAny(Authenticatable $user, TenantContext $context): bool
    {
        return (int) $user->getAuthIdentifier() === $context->actorUserId && $this->scoped->any($context, 'cohort', 'academy.read');
    }

    public function manage(Authenticatable $user, TenantContext $context): bool
    {
        return $this->viewAny($user, $context) && $this->scoped->any($context, 'cohort', 'academy.manage');
    }

    public function attendance(Authenticatable $user, TenantContext $context): bool
    {
        return $this->activities($user, $context) && $this->scoped->any($context, 'cohort', 'academy.attendance.manage');
    }

    public function activities(Authenticatable $user, TenantContext $context): bool
    {
        return $this->viewAny($user, $context) && $this->authorization->allows($context, 'calendar.read');
    }
}
