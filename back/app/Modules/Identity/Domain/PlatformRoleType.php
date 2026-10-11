<?php

namespace App\Modules\Identity\Domain;

enum PlatformRoleType: string
{
    case Superadmin = 'superadmin';
    case Support = 'support';
}
