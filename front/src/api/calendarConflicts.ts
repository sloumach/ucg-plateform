import { t, type TranslationKey } from '../i18n/fr'
export type CalendarResource = { id: string; key: string; label: string; kind: 'room' | 'equipment' | 'other'; status: 'active' | 'archived'; version: string }
export type CalendarBookingResource = { id: string; label: string | null; archived?: boolean }
export type CalendarConflict = {
  type: 'activity' | 'unavailable' | 'uncertain'; id: string; version: string; title: string | null; starts_at: string; ends_at: string
  causes: ('person' | 'team' | 'resource' | 'availability')[]; person_ids: string[]; resource_ids: string[]
}
export type CalendarConflictReport = { conflicts: CalendarConflict[]; truncated: boolean; availability_checked: boolean; can_override: boolean; review_token: string; expires_in: number }
export type CalendarConflictOverride = { id: string; activity_version: string; reason: string; conflict_count: number; conflicts: CalendarConflict[]; availability_checked: boolean; created_at: string }
export function bookingText(key: string) { return t(('booking.' + key) as TranslationKey) }
