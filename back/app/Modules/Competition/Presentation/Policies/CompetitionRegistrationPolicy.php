<?php

namespace App\Modules\Competition\Presentation\Policies;

use App\Modules\Competition\Application\Services\CompetitionRegistrationService;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;

final readonly class CompetitionRegistrationPolicy
{
    public function __construct(private TenantAuthorization $authorization) {}

    public function viewAny(Authenticatable $user, TenantContext $context): bool
    {
        if ((int) $user->getAuthIdentifier() !== $context->actorUserId) {
            return false;
        }
        foreach (CompetitionRegistrationService::PERMISSIONS as $permission) {
            if (! $this->authorization->allows($context, $permission)) {
                return false;
            }
        }

        return true;
    }

    public function manage(Authenticatable $user, TenantContext $context): bool
    {
        return $this->viewAny($user, $context) && $this->authorization->allows($context, 'sports.registrations.write');
    }
}
