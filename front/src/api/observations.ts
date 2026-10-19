import type { MetricDefinition, MetricScope } from './metricCatalogue'
import { t } from '../i18n/fr'
export const observationSources = ['official', 'scrim', 'training', 'assessment'] as const
export type StatisticalObservation = {
  id: string; version: string; number: number; metric_id: string; metric_version: string; game_id: string; person_id: string; person_label: string | null
  match_id: string | null; match_game_id: string | null; source_kind: typeof observationSources[number]; acquired_via: 'manual' | 'import'; origin_reference: string | null
  source_label: string; period_start: string; period_end: string; observed_on: string; sample_size: number
  context: { metric: MetricDefinition; game_context: MetricScope; region: string | null; season: string | null; map: string | null; opponent: string | null; round_number: number | null; session_label: string | null }
  availability: 'available' | 'unavailable'; value: string | null; missing_reason: string | null; notes: string | null; status: 'active' | 'voided'; reason: string; occurred_at: string
}
export const observationText = (key: string) => t(('observations.' + key) as Parameters<typeof t>[0])
