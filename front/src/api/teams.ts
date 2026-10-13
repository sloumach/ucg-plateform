import { t, type TranslationKey } from '../i18n/fr'
export const teamKinds = ['first', 'academy', 'reserve', 'event', 'custom'] as const
export const teamStatuses = ['draft', 'active', 'archived'] as const
export type Team = {
  id: string; game_id: string; game_key: string; game_name: string; game_available: boolean
  slug: string; season: string; name: string; kind: typeof teamKinds[number]; custom_category: string | null
  short_name: string | null; public_description: string | null; color: string | null
  status: typeof teamStatuses[number]; version: string; publication_status: 'private'
}
export function teamLabel(key: string) { return t(('teams.' + key) as TranslationKey) }
export function teamsPath(org: string, id?: string) { return 'tenants/' + org + '/teams' + (id ? '/' + id : '') }
