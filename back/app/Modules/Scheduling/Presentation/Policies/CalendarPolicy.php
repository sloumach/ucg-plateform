<?php

namespace App\Modules\Scheduling\Presentation\Policies;

use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;

final readonly class CalendarPolicy
{
    public function __construct(private TenantAuthorization $authorization) {}

    public function viewAny(Authenticatable $user, TenantContext $context): bool
    {
        return (int) $user->getAuthIdentifier() === $context->actorUserId && $this->authorization->allows($context, 'calendar.read');
    }

    public function manage(Authenticatable $user, TenantContext $context): bool
    {
        return $this->viewAny($user, $context) && $this->authorization->allows($context, 'calendar.write');
    }

    public function manageResources(Authenticatable $user, TenantContext $context): bool
    {
        return $this->manage($user, $context) && $this->authorization->allows($context, 'calendar.resources.manage');
    }

    public function manageInvitations(Authenticatable $user, TenantContext $context): bool
    {
        return $this->manage($user, $context) && $this->authorization->allows($context, 'calendar.invitations.manage');
    }

    public function respondInvitations(Authenticatable $user, TenantContext $context): bool
    {
        return $this->viewAny($user, $context) && $this->authorization->allows($context, 'calendar.invitations.respond');
    }

    public function overrideConflicts(Authenticatable $user, TenantContext $context): bool
    {
        return $this->manage($user, $context) && $this->authorization->allows($context, 'calendar.conflicts.override');
    }
}
