import { t } from '../i18n/fr'

export type ReportExport = {
  id: string; card: string; label: string; view: string; view_label: string; period_kind: 'snapshot' | 'window'; from: string; to: string; timezone: string
  state: 'queued' | 'completed' | 'failed' | 'expired'; row_count: number | null
  created_at: string; generated_at: string | null; expires_at: string
}
export type ReportExportPage = {
  checked_at: string; enabled: boolean; max_rows: number; lifetime_minutes: number; items: ReportExport[]
  meta: { current_page: number; last_page: number; total: number }
}
export const exportText = (key: string) => t(('exports.' + key) as Parameters<typeof t>[0])
