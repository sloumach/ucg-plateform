<?php

namespace App\Modules\Contracts\Presentation\Policies;

use App\Modules\Contracts\Domain\ContractLifecycle;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;

final readonly class ContractPolicy
{
    public function __construct(private TenantAuthorization $authorization) {}

    public function viewAny(Authenticatable $user, TenantContext $context): bool
    {
        return (int) $user->getAuthIdentifier() === $context->actorUserId && $this->authorization->allows($context, 'administrative.read');
    }

    public function manage(Authenticatable $user, TenantContext $context): bool
    {
        return $this->viewAny($user, $context) && $this->authorization->allows($context, 'administrative.write');
    }

    public function transition(Authenticatable $user, TenantContext $context, string $target): bool
    {
        return $this->manage($user, $context) && (! in_array($target, ContractLifecycle::VALIDATION_TARGETS, true)
            || $this->authorization->allows($context, 'administrative.validate'));
    }

    public function financialRead(Authenticatable $user, TenantContext $context): bool
    {
        return $this->viewAny($user, $context) && $this->authorization->allows($context, 'financial.read');
    }

    public function financialWrite(Authenticatable $user, TenantContext $context): bool
    {
        return $this->manage($user, $context) && $this->financialRead($user, $context) && $this->authorization->allows($context, 'financial.write');
    }

    public function approveAmendment(Authenticatable $user, TenantContext $context): bool
    {
        return $this->transition($user, $context, 'approved');
    }
}
