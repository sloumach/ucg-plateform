<?php

namespace App\Modules\Scheduling\Presentation\Policies;

use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;

final readonly class AvailabilityPolicy
{
    public function __construct(private TenantAuthorization $authorization) {}

    public function viewAny(Authenticatable $user, TenantContext $context): bool
    {
        return (int) $user->getAuthIdentifier() === $context->actorUserId &&
            ($this->authorization->allows($context, 'availability.self') || $this->authorization->allows($context, 'availability.read'));
    }

    public function manage(Authenticatable $user, TenantContext $context): bool
    {
        return $this->viewAny($user, $context) && ($this->authorization->allows($context, 'availability.self') ||
            $this->authorization->allows($context, 'availability.manage'));
    }

    public function people(Authenticatable $user, TenantContext $context): bool
    {
        return (int) $user->getAuthIdentifier() === $context->actorUserId && $this->authorization->allows($context, 'availability.read');
    }
}
