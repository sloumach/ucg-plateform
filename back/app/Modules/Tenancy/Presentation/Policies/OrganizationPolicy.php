<?php

namespace App\Modules\Tenancy\Presentation\Policies;

use App\Modules\Tenancy\Application\Services\OrganizationAccessService;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Contracts\Auth\Authenticatable;

final class OrganizationPolicy
{
    public function __construct(private readonly OrganizationAccessService $access) {}

    public function viewAny(Authenticatable $user): bool
    {
        return (int) $user->getAuthIdentifier() > 0;
    }

    public function view(Authenticatable $user, Organization $organization): bool
    {
        return (int) $user->getAuthIdentifier() === $organization->owner_user_id;
    }

    public function update(Authenticatable $user, Organization $organization): bool
    {
        return $this->access->canAdminister($organization, (int) $user->getAuthIdentifier());
    }
}
