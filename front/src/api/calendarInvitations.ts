import { t, type TranslationKey } from '../i18n/fr'
import type { Page } from './tenancy'
export type CalendarInvitation = {
  id: string; activity_id: string; activity_version: string; title: string; starts_at: string; origin_timezone: string
  person_id: string; person_label: string | null; response: 'pending' | 'present' | 'absent'; response_deadline: string
  reminder_offsets: number[]; responded_at: string | null; sent_at: string | null; current: boolean; expired: boolean
  blocked_reason: 'issuer_unavailable' | 'recipient_unavailable' | null; delivery_count: number; can_respond: boolean; version: string
}
export type CalendarInvitationDelivery = { id: string; kind: 'invitation' | 'reminder'; offset_minutes: number; created_at: string }
export type CalendarInvitationPage = Page<CalendarInvitation> & { calendar_invitations?: { delivery_enabled: boolean } }
export function invitationText(key: string) { return t(('calendarInvitations.' + key) as TranslationKey) }