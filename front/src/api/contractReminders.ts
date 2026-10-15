import { t, type TranslationKey } from '../i18n/fr'

export type ReminderPlan = { id: string; version: string; enabled: boolean; notice_on: string | null; renewal_on: string | null; offsets: number[]; channels: ('in_app' | 'email')[]; checked_at: string | null }
export type ReminderDelivery = { id: string; contract_id: string; version: string; kind: 'deadline' | 'notice' | 'renewal'; due_on: string; offset_days: number; in_app_status: string; email_status: string; reason: string | null; attempts: number; created_at: string; email_sent_at: string | null }
export function reminderText(key: string) { return t(('contractReminders.' + key) as TranslationKey) }
