<?php

namespace App\Modules\People\Presentation\Policies;

use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;

final readonly class PersonLifecyclePolicy
{
    public function __construct(private TenantAuthorization $authorization) {}

    public function periods(Authenticatable $user, TenantContext $context): bool
    {
        return $this->allows($user, $context, ['sports.read']);
    }

    public function managePeriods(Authenticatable $user, TenantContext $context): bool
    {
        return $this->allows($user, $context, ['sports.write']);
    }

    public function items(Authenticatable $user, TenantContext $context): bool
    {
        return $this->allows($user, $context, ['administrative.read']);
    }

    public function manageItems(Authenticatable $user, TenantContext $context): bool
    {
        return $this->allows($user, $context, ['administrative.write']);
    }

    public function view(Authenticatable $user, TenantContext $context): bool
    {
        return $this->allows($user, $context, ['sports.read']) || $this->allows($user, $context, ['administrative.read']);
    }

    public function depart(Authenticatable $user, TenantContext $context): bool
    {
        return $this->allows($user, $context, ['sports.write', 'administrative.write', 'memberships.manage', 'invitations.manage']);
    }

    /** @param list<string> $permissions */
    private function allows(Authenticatable $user, TenantContext $context, array $permissions): bool
    {
        if ((int) $user->getAuthIdentifier() !== $context->actorUserId) {
            return false;
        }
        foreach ($permissions as $permission) {
            if (! $this->authorization->allows($context, $permission)) {
                return false;
            }
        }

        return true;
    }
}
