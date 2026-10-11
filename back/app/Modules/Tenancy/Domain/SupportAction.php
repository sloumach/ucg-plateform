<?php

namespace App\Modules\Tenancy\Domain;

enum SupportAction: string
{
    case OrganizationInspect = 'organization.inspect';
    case AccessInspect = 'access.inspect';
}
