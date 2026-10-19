import type { MetricDefinition } from './metricCatalogue'
import { t } from '../i18n/fr'

export type StatisticalCalculationResult = {
  engine_version: string; precision: number; rounding: string; availability: 'available' | 'unavailable'; value: string | null
  sample_size: number; source_count: number; included_count: number; missing_count: number; voided_count: number; incomplete: boolean
  numerator: string | null; denominator: string | null; reason: string | null
}
export type StatisticalCalculation = {
  id: string; status: 'queued' | 'stale' | 'completed' | 'failed'; version: string; source_count: number
  metric: MetricDefinition; cohort: { person_id: string; source_kind: string; source_label: string }
  failure_code: string | null; result: StatisticalCalculationResult | null; result_version: string | null; number: number | null; can_recalculate: boolean
  sources?: { data: string[]; meta: { current_page: number; last_page: number; per_page: number; total: number } }
}
export type StatisticalCalculationRevision = { id: string; number: number; engine_version: string; result: StatisticalCalculationResult; created_at: string }
export const calculationText = (key: string) => t(('calculations.' + key) as Parameters<typeof t>[0])
