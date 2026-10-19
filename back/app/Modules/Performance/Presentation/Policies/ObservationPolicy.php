<?php

namespace App\Modules\Performance\Presentation\Policies;

use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;

final readonly class ObservationPolicy
{
    public function __construct(private TenantAuthorization $authorization) {}

    public function viewAny(Authenticatable $user, TenantContext $context): bool
    {
        return (int) $user->getAuthIdentifier() === $context->actorUserId && $this->authorization->allows($context, 'sports.read') && $this->authorization->allows($context, 'sports.statistics.read');
    }

    public function create(Authenticatable $user, TenantContext $context): bool
    {
        return $this->viewAny($user, $context) && $this->authorization->allows($context, 'sports.write') && $this->authorization->allows($context, 'sports.statistics.write');
    }

    public function correct(Authenticatable $user, TenantContext $context): bool
    {
        return $this->viewAny($user, $context) && $this->authorization->allows($context, 'sports.statistics.correct');
    }
}
