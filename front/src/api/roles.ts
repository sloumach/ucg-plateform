export type PermissionKey = 'organization.view' | 'organization.update' | 'memberships.manage' | 'invitations.manage' | 'roles.assign'
  | 'sports.statistics.read' | 'sports.statistics.write' | 'sports.statistics.correct' | 'teams.manage' | 'games.manage' | 'sports.read' | 'sports.write' | 'sports.validate' | 'sports.registrations.read' | 'sports.registrations.write' | 'sports.competitions.read' | 'sports.competitions.write' | 'sports.correct' | 'sports.publish' | 'sports.notes.read' | 'sports.notes.write' | 'sports.strategies.read' | 'sports.strategies.write' | 'administrative.read' | 'administrative.write'
  | 'administrative.validate' | 'financial.read' | 'financial.write' | 'financial.validate' | 'audit.view'
export type OrganizationRole = {
  id: string; organization_id: string; key: string; name: string; permissions: PermissionKey[]
  active: boolean; privileged: boolean; version: string
}
export type RoleAssignment = {
  id: string; organization_id: string; role_id: string; role_name: string; user_id: number
  scope_type: ScopeType; scope_id: string; status: 'active' | 'suspended' | 'revoked'
  starts_at: string; ends_at: string | null; version: string
}
export type ScopeType = 'organization' | 'team' | 'game' | 'cohort' | 'event'
export type PermissionCatalogue = {
  permissions: { key: PermissionKey; privileged: boolean; organization_only: boolean }[]
  scope_types: ScopeType[]; can_manage: boolean
}
