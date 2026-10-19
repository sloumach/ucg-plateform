import type { Page } from './tenancy'
import { t, type TranslationKey } from '../i18n/fr'
export const metricAggregations = ['sum', 'mean', 'min', 'max', 'last', 'ratio', 'manual'] as const
export const metricLevels = ['round', 'game', 'match', 'session', 'period'] as const
export const metricAvailability = ['manual', 'source_required', 'unavailable'] as const
export type MetricScope = { game_id: string; game_key: string; game_name: string; mode_id: string | null; mode_name: string | null; role_id: string | null; role_name: string | null; release_id: string | null; release_name: string | null }
export type RatioComponents = { numerator: { metric_id: string; version: string; code?: string }[]; denominator: { metric_id: string; version: string; code?: string }[] }
export type MetricDefinition = { ratio_components?: RatioComponents | null; id: string; code: string; game_id: string; version: string; number: number; scope: MetricScope; mode_id: string | null; role_id: string | null; release_id: string | null; title: string; definition: string; unit: string; aggregation: typeof metricAggregations[number]; aggregation_level: typeof metricLevels[number]; formula: string; zero_denominator: 'unavailable' | 'zero' | 'not_applicable'; measurement_kind: 'objective' | 'subjective'; availability: typeof metricAvailability[number]; availability_note: string; status: 'active' | 'retired'; reason: string; occurred_at: string }
export type MetricPage = Page<MetricDefinition> & { meta: { catalogue_version: string; game_catalogue_version: string; can_manage: boolean } }
export function metricText(key: string) { return t(('metrics.' + key) as TranslationKey) }
