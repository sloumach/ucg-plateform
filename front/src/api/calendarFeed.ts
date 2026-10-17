import { t, type TranslationKey } from '../i18n/fr'
export type CalendarFeed = { id: string; before_days: number; after_days: number; expires_at: string; revoked_at: string | null; status: 'active' | 'revoked' | 'expired'; version: string }
export type CalendarFeedSecret = { feed: CalendarFeed; url: string }
export function feedText(key: string) { return t(('calendarFeed.' + key) as TranslationKey) }