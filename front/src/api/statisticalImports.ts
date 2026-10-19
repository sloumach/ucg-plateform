import type { MetricDefinition, MetricScope } from './metricCatalogue'
import { t } from '../i18n/fr'

export type StatisticalImportSummary = {
  id: string; status: 'previewed' | 'queued' | 'completed' | 'failed'; row_count: number; valid_count: number; invalid_count: number; created_at: string; can_confirm: boolean; version: string; can_resume: boolean; processed_count: number; counts: { created: number; duplicate: number; rejected: number }; failure_code: string | null
}
export type StatisticalImportRow = {
  outcome?: { status: 'created' | 'duplicate' | 'rejected'; code: string | null } | null
  line: number; status: 'valid' | 'invalid'; values: Record<string, string | number | null>; errors: Record<string, string[]>
  resolved: { person_id: string; person_label: string | null; metric_id: string; metric_version: string; game_id: string; match_id: string | null; match_game_id: string | null; metric: MetricDefinition; game_context: MetricScope } | null
}
export type StatisticalImport = StatisticalImportSummary & {
  headers: string[]; delimiter: ',' | ';'
  rows: { data: StatisticalImportRow[]; meta: { current_page: number; last_page: number; per_page: number; total: number } }
}
export const statisticalImportText = (key: string) => t(('statisticalImports.' + key) as Parameters<typeof t>[0])
export const statisticalCsvHeaders = ['metric_id', 'metric_version', 'person_id', 'source_kind', 'source_label', 'period_start', 'period_end', 'observed_on', 'sample_size', 'availability', 'value', 'missing_reason', 'status', 'reason']
