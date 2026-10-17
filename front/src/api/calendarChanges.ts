import type { Page } from './tenancy'
import { t, type TranslationKey } from '../i18n/fr'
export type CalendarChangePeriod = { starts_at: string; ends_at: string; origin_timezone: string }
export type CalendarChange = { id: string; activity_id: string; activity_version: string; kind: 'rescheduled' | 'cancelled'; title: string; reason: string;
  before: CalendarChangePeriod; after: CalendarChangePeriod; occurred_at: string; recipients_count?: number; delivered_count?: number; blocked_count?: number }
export type CalendarChangeNotice = { id: string; change: CalendarChange; delivered_at: string; current: boolean }
export type CalendarChangePage = Page<CalendarChange | CalendarChangeNotice> & { calendar_changes?: { delivery_enabled: boolean } }
export function changeText(key: string) { return t(('calendarChanges.' + key) as TranslationKey) }