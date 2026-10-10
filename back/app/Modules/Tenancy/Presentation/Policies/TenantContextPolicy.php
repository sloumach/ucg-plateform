<?php

namespace App\Modules\Tenancy\Presentation\Policies;

use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;

final class TenantContextPolicy
{
    public function view(Authenticatable $user, TenantContext $context): bool
    {
        return (int) $user->getAuthIdentifier() === $context->actorUserId;
    }
}
