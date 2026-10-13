import { t, type TranslationKey } from '../i18n/fr'

export type EligibilityRuleset = {
  id: string; version: string; game_id: string; name: string; status: 'active' | 'archived'
  min_starters: number; max_starters: number; max_substitutes: number; platform_key: string | null
  allowed_regions: string[]; allowed_ranks: string[]; min_age: number | null; max_age: number | null
  observation_max_days: number; lock_at: string | null
}
export type EligibilityReport = {
  id: string; lineup_version: string; ruleset_id: string; ruleset_version: string; stage: 'preview' | 'final'
  status: 'eligible' | 'ineligible' | 'incomplete'; evaluated_at: string; actor_user_id: number
  checks: { code: string; status: 'passed' | 'failed' | 'unknown'; person_id: string | null; message: string }[]
}
export const eligibilityPath = (org: string) => 'tenants/' + org + '/eligibility-rulesets'
export const eligibilityText = (key: string) => t(('eligibility.' + key) as TranslationKey)
