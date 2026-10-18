<?php

namespace App\Modules\Competition\Presentation\Policies;

use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;

final readonly class MatchPolicy
{
    public function __construct(private TenantAuthorization $authorization) {}

    public function viewAny(Authenticatable $user, TenantContext $context): bool
    {
        return (int) $user->getAuthIdentifier() === $context->actorUserId && $this->authorization->allows($context, 'sports.read');
    }

    public function manage(Authenticatable $user, TenantContext $context): bool
    {
        return $this->viewAny($user, $context) && $this->authorization->allows($context, 'sports.write');
    }

    public function correctScore(Authenticatable $user, TenantContext $context): bool
    {
        return $this->manage($user, $context) && $this->authorization->allows($context, 'sports.correct');
    }

    public function validateResult(Authenticatable $user, TenantContext $context): bool
    {
        return $this->viewAny($user, $context) && $this->authorization->allows($context, 'sports.validate');
    }

    public function disputeResult(Authenticatable $user, TenantContext $context): bool
    {
        return $this->manage($user, $context) || $this->validateResult($user, $context);
    }
}
