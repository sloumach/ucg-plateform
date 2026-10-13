<?php

namespace App\Modules\Tenancy\Domain;

/** Closed action catalogue: tenant configuration cannot invent platform permissions. */
enum Permission: string
{
    case OrganizationView = 'organization.view';
    case OrganizationUpdate = 'organization.update';
    case MembershipsManage = 'memberships.manage';
    case InvitationsManage = 'invitations.manage';
    case RolesManage = 'roles.manage';
    case RolesAssign = 'roles.assign';
    case AuditView = 'audit.view';
    case SportsRead = 'sports.read';
    case GamesManage = 'games.manage';
    case TeamsManage = 'teams.manage';
    case SportsWrite = 'sports.write';
    case SportsValidate = 'sports.validate';
    case SportsPublish = 'sports.publish';
    case AdministrativeRead = 'administrative.read';
    case AdministrativeWrite = 'administrative.write';
    case AdministrativeValidate = 'administrative.validate';
    case FinancialRead = 'financial.read';
    case FinancialWrite = 'financial.write';
    case FinancialValidate = 'financial.validate';

    public function privileged(): bool
    {
        return ! in_array($this, [self::OrganizationView, self::SportsRead], true);
    }

    public function organizationOnly(): bool
    {
        return str_starts_with($this->value, 'organization.') || str_starts_with($this->value, 'memberships.')
            || str_starts_with($this->value, 'invitations.') || str_starts_with($this->value, 'roles.') || $this === self::AuditView;
    }

    /** Role definitions remain under the owner's governance. */
    public function configurable(): bool
    {
        return $this !== self::RolesManage;
    }

    /** @return list<string> */
    public static function governance(bool $owner): array
    {
        $permissions = ['organization.view', 'organization.update', 'memberships.manage', 'invitations.manage', 'audit.view'];

        return $owner ? [...$permissions, 'roles.manage', 'roles.assign'] : $permissions;
    }
}
