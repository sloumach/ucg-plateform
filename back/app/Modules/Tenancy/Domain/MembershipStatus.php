<?php

namespace App\Modules\Tenancy\Domain;

enum MembershipStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Revoked = 'revoked';
}
