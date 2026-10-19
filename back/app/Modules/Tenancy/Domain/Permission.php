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
    case SportsRegistrationsRead = 'sports.registrations.read';
    case SportsRegistrationsWrite = 'sports.registrations.write';
    case SportsCompetitionsRead = 'sports.competitions.read';
    case SportsCompetitionsWrite = 'sports.competitions.write';
    case GamesManage = 'games.manage';
    case TeamsManage = 'teams.manage';
    case SportsWrite = 'sports.write';
    case SportsCorrect = 'sports.correct';
    case SportsValidate = 'sports.validate';
    case SportsPublish = 'sports.publish';
    case SportsNotesRead = 'sports.notes.read';
    case SportsNotesWrite = 'sports.notes.write';
    case SportsStrategiesRead = 'sports.strategies.read';
    case SportsStrategiesWrite = 'sports.strategies.write';
    case AdministrativeRead = 'administrative.read';
    case AdministrativeWrite = 'administrative.write';
    case AdministrativeValidate = 'administrative.validate';
    case FinancialRead = 'financial.read';
    case FinancialWrite = 'financial.write';
    case FinancialValidate = 'financial.validate';
    case PartnershipsRead = 'partnerships.read';
    case PartnershipsWrite = 'partnerships.write';
    case PartnershipsValidate = 'partnerships.validate';
    case MediaRead = 'media.read';
    case MediaWrite = 'media.write';
    case MediaPublish = 'media.publish';
    case CommunicationRead = 'communication.read';
    case CommunicationWrite = 'communication.write';
    case CommunicationPublish = 'communication.publish';
    case CommunicationComment = 'communication.comment';
    case CalendarRead = 'calendar.read';
    case CalendarExport = 'calendar.export';
    case CalendarWrite = 'calendar.write';
    case CalendarPublish = 'calendar.publish';
    case CalendarResourcesManage = 'calendar.resources.manage';
    case CalendarConflictsOverride = 'calendar.conflicts.override';
    case CalendarInvitationsManage = 'calendar.invitations.manage';
    case CalendarInvitationsRespond = 'calendar.invitations.respond';
    case AvailabilitySelf = 'availability.self';
    case AvailabilityRead = 'availability.read';
    case AvailabilityManage = 'availability.manage';

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
