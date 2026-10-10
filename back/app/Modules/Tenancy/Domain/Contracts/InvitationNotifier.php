<?php

namespace App\Modules\Tenancy\Domain\Contracts;

use App\Modules\Tenancy\Domain\Models\OrganizationInvitation;

interface InvitationNotifier
{
    public function notify(OrganizationInvitation $invitation, string $organizationName, string $requestId): void;
}
