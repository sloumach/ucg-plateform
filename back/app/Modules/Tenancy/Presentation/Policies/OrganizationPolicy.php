<?php

namespace App\Modules\Tenancy\Presentation\Policies;

use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Contracts\Auth\Authenticatable;

final class OrganizationPolicy
{
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
        return $this->view($user, $organization);
    }
}
