import type { Page } from './tenancy'
import { t, type TranslationKey } from '../i18n/fr'
export type CompetitionContact = { name: string; role: string | null; email: string | null; phone: string | null }
export type ExternalCompetition = { id: string; reference: string; game_id: string; game_key: string; game_name: string; name: string; organizer: string; region: string; season: string; website_url: string | null; contacts: CompetitionContact[]; status: 'active' | 'archived'; version: string; created_at: string; updated_at: string }
export type CompetitionRevision = { id: string; version: string; number: number; snapshot: Omit<ExternalCompetition, 'created_at' | 'updated_at'>; changed_fields: string[]; reason: string; actor_user_id: number; occurred_at: string }
export type CompetitionDocument = { document_id: string; version: string; number: number; kind: 'rules' | 'note'; title: string; body: string; source_url: string | null; reason: string; actor_user_id: number; occurred_at: string }
export type CompetitionPage = Page<ExternalCompetition>
export function competitionText(key: string) { return t(('competitions.' + key) as TranslationKey) }
export function safeCompetitionUrl(value: string | null) { try { const url = new URL(value ?? ''); return url.protocol === 'https:' && !url.username && !url.password ? url.href : undefined } catch { return undefined } }
