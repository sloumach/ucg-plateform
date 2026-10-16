<?php

namespace App\Modules\Partnerships\Presentation\Policies;

use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;

final readonly class SponsorshipAgreementPolicy
{
    public function __construct(private TenantAuthorization $authorization) {}

    public function viewAny(Authenticatable $user, TenantContext $context): bool
    {
        return (int) $user->getAuthIdentifier() === $context->actorUserId && $this->authorization->allows($context, 'partnerships.read');
    }

    public function manage(Authenticatable $user, TenantContext $context): bool
    {
        return $this->viewAny($user, $context) && $this->authorization->allows($context, 'partnerships.write');
    }

    public function validate(Authenticatable $user, TenantContext $context): bool
    {
        return $this->manage($user, $context) && $this->authorization->allows($context, 'partnerships.validate');
    }

    public function financialRead(Authenticatable $user, TenantContext $context): bool
    {
        return $this->viewAny($user, $context) && $this->authorization->allows($context, 'financial.read');
    }

    public function financialWrite(Authenticatable $user, TenantContext $context): bool
    {
        return $this->manage($user, $context) && $this->financialRead($user, $context) && $this->authorization->allows($context, 'financial.write');
    }
}
