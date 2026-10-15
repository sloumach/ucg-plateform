import { t, type TranslationKey } from '../i18n/fr'

export type SensitiveAccessEntry = { id: string; source: string; organization_id: string; actor_user_id: number | null; action: string; target: { type: string; id: string | null }; before: Record<string, unknown>; after: Record<string, unknown>; reason_code: string; request_id: string; ip_address: string | null; occurred_at: string }
export type SensitiveAccessPage = { data: SensitiveAccessEntry[]; meta: { request_id: string; per_page: number; next_cursor: string | null } }
export const sensitiveActions = ['contract.listed', 'contract.viewed', 'contract.history_viewed', 'contract.financial_viewed', 'contract.amendment_financial_viewed',
  'contract.documents_viewed', 'contract.versions_viewed', 'contract.approvals_viewed', 'contract.scan_history_viewed', 'contract.download_link_created',
  'contract.document_downloaded', 'contract.reminders_viewed', 'contract.reminder_history_viewed', 'contract.reminder_inbox_viewed',
  'tenant.export_requested', 'tenant.export_retried', 'tenant.export_generated', 'tenant.export_read'] as const
export function sensitiveText(key: string) { return t(('sensitiveAudit.' + key) as TranslationKey) }
