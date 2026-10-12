<?php

namespace App\Modules\People\Presentation\Policies;

use App\Modules\People\Application\Validation\PersonImportInput;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;

final readonly class PersonImportPolicy
{
    public function __construct(private TenantAuthorization $authorization) {}

    public function manage(Authenticatable $user, TenantContext $context): bool
    {
        if ((int) $user->getAuthIdentifier() !== $context->actorUserId) {
            return false;
        }
        foreach (PersonImportInput::PERMISSIONS as $permission) {
            if (! $this->authorization->allows($context, $permission)) {
                return false;
            }
        }

        return true;
    }
}
