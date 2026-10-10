<?php

namespace App\Modules\Tenancy\Presentation\Policies;

use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use Illuminate\Contracts\Auth\Authenticatable;

final class MembershipPolicy
{
    public function leave(Authenticatable $user, OrganizationMembership $membership): bool
    {
        return (int) $user->getAuthIdentifier() === $membership->user_id;
    }
}
