import { t, type TranslationKey } from '../i18n/fr'
export type OutcomeStream = 'sport' | 'awards'
export type OutcomeMatch = { id: string; version: string; title: string; status: string }
export type OutcomeLink = { reference_id: string; match: OutcomeMatch | null }
export type OutcomePayload = { source: string; source_url: string | null; observed_on: string; notes: string | null; place?: number | null; field_size?: number | null; currency?: string; announced_minor?: number | null; received_minor?: number | null; received_on?: string | null }
export type CompetitionOutcome = { version: string; number: number; stream: OutcomeStream; payload: OutcomePayload; matches: OutcomeLink[]; reason: string; occurred_at: string }
export type OutcomeResult = { reference_id: string; status: string; approved: boolean; outcome: string | null; series: { complete: boolean; winner_ids: string[]; standings: { participant_id: string; label: string; score: number }[] } | null; winners: { participant_id: string; label: string }[]; observed_at: string }
export const awardCurrencies = ['EUR', 'USD', 'GBP', 'CAD', 'CHF', 'JPY', 'AUD'] as const
export function outcomeText(key: string) { return t(('outcomes.' + key) as TranslationKey) }
export function awardInput(minor: number | null | undefined, currency: string) {
  if (minor == null) return ''
  const digits = String(minor).padStart(currency === 'JPY' ? 1 : 3, '0')
  return currency === 'JPY' ? digits : digits.slice(0, -2) + ',' + digits.slice(-2)
}
// Decimal strings are converted using integer arithmetic; no floating-point money calculation.
export function awardMinor(input: string, currency: string): number | null {
  if (input.trim() === '') return null
  const value = input.trim().replace(',', '.'), precision = currency === 'JPY' ? 0 : 2
  if (!(precision === 0 ? /^\d{1,13}$/ : /^\d{1,13}(\.\d{1,2})?$/).test(value)) throw new Error(outcomeText('moneyError'))
  const [whole, fraction = ''] = value.split('.')
  const minor = BigInt(whole) * (precision === 0 ? 1n : 100n) + BigInt(precision === 0 ? '0' : fraction.padEnd(2, '0'))
  if (minor > 1000000000000n) throw new Error(outcomeText('moneyError'))
  return Number(minor)
}
