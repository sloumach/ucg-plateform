<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Application\Contracts\PlatformAccountDirectory;
use App\Modules\Identity\Application\Contracts\PrivilegedAccountDirectory;
use App\Modules\Identity\Domain\Models\User;

final readonly class MfaRequirementService
{
    public function __construct(private PrivilegedAccountDirectory $privileges, private PlatformAccountDirectory $platform) {}

    public function required(User $user): bool
    {
        $operators = config('horizon.authorized_emails', []);

        return (is_array($operators) && in_array($user->email, $operators, true))
            || $this->platform->isTechnical((int) $user->getKey())
            || $this->privileges->hasOrganizationPrivileges((int) $user->getKey());
    }
}
