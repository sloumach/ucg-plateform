<?php

namespace App\Modules\Tenancy\Domain;

enum MembershipRole: string
{
    case Member = 'member';
    case Administrator = 'administrator';
}
