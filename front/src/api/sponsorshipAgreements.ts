import { t, type TranslationKey } from '../i18n/fr'
import { sponsorsPath } from './partnerships'

export const agreementStatuses = ['draft', 'approved', 'ended', 'archived'] as const
export const exposureChannels = ['jersey', 'social', 'stream', 'event', 'website', 'other'] as const
export type SponsorshipAgreement = { id: string; sponsor_id: string; opportunity_id: string | null; reference: string; title: string; starts_on: string; ends_on: string; responsible_user_id: number; responsible_label?: string | null; contract_id?: string | null; contract_version?: string | null; status: typeof agreementStatuses[number]; version: string; prepared_by: number; approved_by: number | null; approved_at: string | null; publication_status: 'private' }
export type SponsorshipExposure = { id: string; agreement_id: string; target_type: 'organization' | 'team' | 'player'; target_id: string; target_label: string; channel: typeof exposureChannels[number]; description: string | null; authorization_reference: string; exclusivity_key: string | null; status: 'active' | 'archived'; version: string; exposure_authorized: boolean; publication_status: 'private'; agreement_version?: string }
export type SponsorshipTerms = { kind: 'cash' | 'in_kind' | 'mixed'; currency: string | null; amount: string | null; in_kind: string | null; payment_terms: string | null }
export type SponsorshipFinancial = { id: string | null; version: string | null; terms: SponsorshipTerms | null; agreement_version: string }
export type SponsorshipEvent = { id: string; action: string; from_status: string | null; to_status: string; agreement_version: string; actor_user_id: number; occurred_at: string }
export type SponsorshipReference = { id: string; label: string }
export function agreementText(key: string) { return t(('sponsorships.' + key) as TranslationKey) }
export function agreementsPath(org: string, sponsor: string, id?: string) { return sponsorsPath(org, sponsor) + '/agreements' + (id ? '/' + id : '') }
