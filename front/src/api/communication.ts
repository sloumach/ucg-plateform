import { t, type TranslationKey } from '../i18n/fr'
export type Announcement = {
  id: string; title: string; body: string; discussion?: boolean; target_type: 'organization' | 'team' | 'game' | 'role' | 'event' | 'match' | 'session' | 'task'; target_id: string | null
  status: 'draft' | 'published' | 'withdrawn'; comments_enabled: boolean; can_comment: boolean
  published_at: string | null; expires_at: string | null; withdrawn_at: string | null; version: string
}
export type AnnouncementComment = { id: string; announcement_id: string; body: string; is_mine: boolean; created_at: string }
export type CommunicationEvent = { id: string; announcement_id: string; comment_id: string | null; title: string; kind: 'announcement' | 'comment'; read: boolean; created_at: string }
export type CommunicationPreferences = { announcements_enabled: boolean; comments_enabled: boolean; version: string | null }
export type WorkTask = { id: string; title: string; description: string | null; assignee_id: string | null; assignee_label: string | null; due_on: string | null; status: 'todo' | 'in_progress' | 'done'; version: string }
export function taskText(key: string) { return t(('tasks.' + key) as TranslationKey) }
export function communicationText(key: string) { return t(('communication.' + key) as TranslationKey) }
export function communicationDate(value: string, timezone: string) {
  return new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'short', timeZone: timezone }).format(new Date(value))
}
