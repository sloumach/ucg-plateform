import type { MetricDefinition } from './metricCatalogue'
import type { StatisticalCalculationResult } from './statisticalCalculations'
import { t } from '../i18n/fr'

export type ProfilePage<T> = { data: T[]; meta: { current_page: number; last_page: number; per_page: number; total: number } }
export type ProfileContext = Record<string, string | null>
export type ProfileGroup = {
  key: string; context: ProfileContext; context_labels: ProfileContext; result: StatisticalCalculationResult | null; issue: string | null
  source_count: number; period_start: string; period_end: string; period_count: number; coverage_version: string
  latest_observed_on: string; last_updated_at: string
}
export type ProfileTimelineEntry = {
  id: string; metric_id: string; metric_version: string; metric_code: string; metric_number: number; metric_unit: string; period_start: string; period_end: string
  observed_on: string; sample_size: number; status: 'active' | 'voided'; availability: 'available' | 'unavailable'
  value: string | null; version: string; last_updated_at: string; context: ProfileContext; context_labels: ProfileContext
}
export type StatisticalProfile = {
  person_id: string; person_label: string; source_count: number; latest_observed_on: string | null; last_updated_at: string | null
  groups: ProfilePage<ProfileGroup>; timeline: ProfilePage<ProfileTimelineEntry>
}
export type ProfileComparison = {
  key: string; context: ProfileContext; context_labels: ProfileContext; comparable: boolean; reason: string | null; difference: string | null; unequal_samples: boolean
  left: ProfileGroup | null; right: ProfileGroup | null
}
export type StatisticalProfileResponse = {
  metric: MetricDefinition; filters: Record<string, unknown>; generated_at: string; engine_version: string; live: true
  profiles: StatisticalProfile[]; comparison: ProfilePage<ProfileComparison> | null
}
export const profileText = (key: string) => t(('profiles.' + key) as Parameters<typeof t>[0])
