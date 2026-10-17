import { t, type TranslationKey } from '../i18n/fr'
import type { Page } from './tenancy'
import type { CalendarBookingResource } from './calendarConflicts'
export const calendarKinds = ['training','scrim','match','meeting','academy','bootcamp','sponsor','travel'] as const
export const calendarVisibilities = ['private','team','organization'] as const
export type CalendarReference = { id: string; label: string | null }
export type CalendarActivity = {
  id: string; title: string; description: string | null; kind: typeof calendarKinds[number]
  starts_at: string; ends_at: string; origin_timezone: string; starts_at_origin: string; ends_at_origin: string
  team_id: string | null; team_label: string | null; location: string | null; url: string | null
  visibility: typeof calendarVisibilities[number]; participants: CalendarReference[]; archived_at: string | null; version: string
  resources?: CalendarBookingResource[]
}
export type CalendarPage = Page<CalendarActivity> & { calendar: { viewer_timezone: string; display_timezone: string; from_utc: string; to_utc: string } }
export function calendarText(key: string) { return t(('calendar.' + key) as TranslationKey) }
export function calendarDate(value: string, timezone: string) {
  return new Intl.DateTimeFormat('fr-FR', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', timeZoneName: 'short', timeZone: timezone }).format(new Date(value))
}
export function safeCalendarUrl(value: string | null): string | null {
  if (!value || Array.from(value).some((character) => character.charCodeAt(0) <= 32 || character.charCodeAt(0) === 127)) return null
  try { const url = new URL(value); return url.protocol === 'https:' && !url.username && !url.password && !url.port ? url.href : null } catch { return null }
}
