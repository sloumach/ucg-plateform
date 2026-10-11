<?php

namespace App\Modules\Identity\Application\Contracts;

interface PrivilegedAccountDirectory
{
    public function hasOrganizationPrivileges(int $userId): bool;
}
