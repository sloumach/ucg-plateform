import { t, type TranslationKey } from '../i18n/fr'
import { agreementsPath } from './sponsorshipAgreements'
import type { Page } from './tenancy'

export const deliverableKinds = ['publication', 'video', 'event_presence', 'brand_placement'] as const
export const deliverableStatuses = ['promised', 'delivered', 'validated', 'cancelled'] as const
export type SponsorshipCampaign = { id: string; agreement_id: string; title: string; starts_on: string; ends_on: string; responsible_user_id: number; responsible_label: string | null; status: 'active' | 'archived'; version: string; publication_status: 'private' }
export type SponsorshipDeliverable = { id: string; sponsor_name?: string; agreement_reference?: string; campaign_title?: string; agreement_id: string; campaign_id: string; exposure_id: string; kind: typeof deliverableKinds[number]; title: string; responsible_user_id: number; responsible_label: string | null; due_at: string; status: typeof deliverableStatuses[number]; version: string; prepared_by: number; proof_reference?: string | null; delivered_at: string | null; delivered_by: number | null; validated_at: string | null; validated_by: number | null; overdue: boolean; delivered_late: boolean; validation_matches_agreement?: boolean; exposure_authorized?: boolean; campaign_version?: string; agreement_version?: string; publication_status: 'private' }
export type SponsorshipCampaignEvent = { id: string; campaign_id: string; deliverable_id: string | null; action: string; from_status: string | null; to_status: string; actor_user_id: number; occurred_at: string }
export type SponsorshipDashboard = Page<SponsorshipDeliverable> & { summary?: Record<string, number> }
export function campaignText(key: string) { return t(('campaigns.' + key) as TranslationKey) }
export function campaignsPath(org: string, sponsor: string, agreement: string) { return agreementsPath(org, sponsor, agreement) + '/campaigns' }
export function campaignDate(value: string, timezone: string) { return new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'short', timeZone: timezone }).format(new Date(value)) }
