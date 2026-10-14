import { t, type TranslationKey } from '../i18n/fr'

export const opponentKinds = ['organization', 'team', 'player'] as const
export type ExternalOpponent = {
  id: string; key: string; kind: typeof opponentKinds[number]; name: string; short_name: string | null
  organization_name: string | null; game_id: string | null; game_key: string | null; game_name: string | null
  source_label: string; source_url: string | null; observed_at: string; status: 'active' | 'archived'
  version: string; publication_status: 'private'
}
export type OpponentSnapshot = {
  id: string; opponent_id: string; opponent_version: string; context_label: string
  kind: ExternalOpponent['kind']; name: string; short_name: string | null; organization_name: string | null
  game_key: string | null; game_name: string | null; source_label: string; source_url: string | null
  observed_at: string; captured_at: string; created_by: number; publication_status: 'private'
}
export function opponentText(key: string) { return t(('opponents.' + key) as TranslationKey) }
export function opponentsPath(org: string, id?: string) { return 'tenants/' + org + '/external-opponents' + (id ? '/' + id : '') }
