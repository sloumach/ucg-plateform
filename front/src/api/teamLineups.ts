import { t, type TranslationKey } from '../i18n/fr'

export const MAX_LINEUP_ENTRIES = 50

export type LineupChoice = {
  membership_id: string; membership_version: string; person_id: string; pseudonym: string
  membership_role_key: 'player' | 'substitute'; slot_key: 'starter' | 'substitute'
}
export type TeamLineup = {
  id: string; team_id: string; game_id: string; purpose: 'match' | 'registration'; label: string; effective_at: string
  status: 'draft' | 'finalized' | 'cancelled'; version: string; team_name: string; game_name: string; game_key: string; season: string
  created_by: number; finalized_by: number | null; finalized_at: string | null
  ruleset_id?: string | null; ruleset_version?: string | null; ruleset_name?: string | null; lock_at?: string | null
  eligibility_status?: string; eligibility_report_id?: string | null
  entries: (LineupChoice & { id: string; position: number })[]
}
export function lineupText(key: string) { return t(('lineups.' + key) as TranslationKey) }
