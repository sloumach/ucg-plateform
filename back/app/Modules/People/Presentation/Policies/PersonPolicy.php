<?php

namespace App\Modules\People\Presentation\Policies;

use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;

final readonly class PersonPolicy
{
    public function __construct(private TenantAuthorization $authorization) {}

    public function view(Authenticatable $user, TenantContext $context): bool
    {
        return (int) $user->getAuthIdentifier() === $context->actorUserId
            && ($this->authorization->allows($context, 'sports.read') || $this->authorization->allows($context, 'administrative.read'));
    }

    public function create(Authenticatable $user, TenantContext $context): bool
    {
        return (int) $user->getAuthIdentifier() === $context->actorUserId && $this->authorization->allows($context, 'sports.write');
    }

    public function archive(Authenticatable $user, TenantContext $context): bool
    {
        return (int) $user->getAuthIdentifier() === $context->actorUserId
            && $this->authorization->allows($context, 'sports.write') && $this->authorization->allows($context, 'administrative.write');
    }

    public function update(Authenticatable $user, TenantContext $context): bool
    {
        return (int) $user->getAuthIdentifier() === $context->actorUserId
            && ($this->authorization->allows($context, 'sports.write') || $this->authorization->allows($context, 'administrative.write'));
    }
}
