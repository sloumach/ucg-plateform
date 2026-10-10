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

    public function manage(Authenticatable $user, TenantContext $context): bool
    {
        return $this->view($user, $context) && $context->canAdminister();
    }
}
