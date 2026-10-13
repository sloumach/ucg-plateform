import { t, type TranslationKey } from '../i18n/fr'
export const sportingRoles = ['player', 'substitute', 'captain', 'coach', 'analyst'] as const
export type TeamMembership = {
  id: string; team_id: string; person_id: string; pseudonym: string | null; role_key: typeof sportingRoles[number]
  starts_at: string; ends_at: string | null; status: 'active' | 'closed' | 'cancelled'; effective: boolean; version: string
}
export type TeamMovement = {
  id: string; team_id: string; team_name: string; destination_team_name: string | null; membership_id: string; destination_team_id: string | null; destination_membership_id: string | null
  action: 'created' | 'close' | 'cancel' | 'transfer' | 'promotion' | 'person_departure'; reason_key: string
  actor_user_id: number; effective_at: string; recorded_at: string
}
export function rosterText(key: string) { return t(('roster.' + key) as TranslationKey) }
