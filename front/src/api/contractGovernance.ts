import { t, type TranslationKey } from '../i18n/fr'

export type FinancialValues = { currency: string; remuneration: string | null; remuneration_period: 'once' | 'monthly' | 'yearly' | null; bonus: string | null; prize_share_percent: string | null; payment_terms: string | null }
export type ContractFinancialTerms = { id: string; version: string; terms: FinancialValues; prepared_by: number; updated_at: string }
export type AmendmentApproval = { id: string; number: number; document_version_id: string; approved_by: number; approved_at: string }
export function financialText(key: string) { return t(('contractGovernance.' + key) as TranslationKey) }
