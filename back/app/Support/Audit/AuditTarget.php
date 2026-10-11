<?php

namespace App\Support\Audit;

enum AuditTarget: string
{
    case Journal = 'journal';
    case Route = 'route';
    case Organization = 'organization';
    case Account = 'account';
    case Membership = 'membership';
    case Invitation = 'invitation';
    case Role = 'role';
    case RoleAssignment = 'role_assignment';
    case SupportAccess = 'support_access';
}
