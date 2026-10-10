<?php

namespace App\Modules\Tenancy\Presentation\Policies;

use App\Modules\Identity\Application\Contracts\AccountDirectory;
use App\Modules\Tenancy\Domain\Models\OrganizationInvitation;
use Illuminate\Contracts\Auth\Authenticatable;

final readonly class InvitationPolicy
{
    public function __construct(private AccountDirectory $accounts) {}

    public function respond(Authenticatable $user, OrganizationInvitation $invitation): bool
    {
        return $this->accounts->verifiedEmail((int) $user->getAuthIdentifier()) === $invitation->email;
    }
}
