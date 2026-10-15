import { t, type TranslationKey } from '../i18n/fr'

export const sponsorStatuses = ['prospect', 'partner', 'archived'] as const
export const opportunityStatuses = ['open', 'contacted', 'negotiating', 'won', 'lost', 'archived'] as const
export const interactionChannels = ['note', 'email', 'phone', 'meeting', 'other'] as const
export type Sponsor = { id: string; name: string; industry: string | null; status: typeof sponsorStatuses[number]; version: string; publication_status: 'private' }
export type SponsorContact = { id: string; sponsor_id: string; name: string; position: string | null; email: string | null; phone: string | null; status: 'active' | 'archived'; version: string; publication_status: 'private' }
export type SponsorshipOpportunity = { id: string; sponsor_id: string; title: string; description: string | null; status: typeof opportunityStatuses[number]; version: string; publication_status: 'private' }
export type SponsorshipInteraction = { id: string; sponsor_id: string; contact_id: string | null; opportunity_id: string | null; channel: typeof interactionChannels[number]; summary: string; occurred_at: string; created_at: string; created_by: number; publication_status: 'private' }
export type PartnershipKind = 'contacts' | 'opportunities' | 'interactions'
export type PartnershipRecord = SponsorContact | SponsorshipOpportunity | SponsorshipInteraction
export function partnershipText(key: string) { return t(('partnerships.' + key) as TranslationKey) }
export function sponsorsPath(org: string, id?: string) { return 'tenants/' + org + '/sponsors' + (id ? '/' + id : '') }
