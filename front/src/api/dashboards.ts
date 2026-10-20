import { t } from '../i18n/fr'

export const dashboardViews = ['direction', 'manager', 'coach', 'player', 'administration'] as const
export type DashboardView = typeof dashboardViews[number]
export type DashboardCardDefinition = { id: string; indicator_supported?: boolean; label: string; definition: string; period_kind: 'snapshot' | 'window'; scope: 'organization' | 'current_audience' | 'self'; status: 'available' | 'unavailable'; reason: string | null }
export type DashboardCatalogue = { organization_id: string; timezone: string; max_days: number; views: { id: DashboardView; label: string; cards: DashboardCardDefinition[] }[] }
export type DashboardCard = DashboardCardDefinition & {
  organization_id: string; view: DashboardView; captured_at: string; total: number | null
  period: { kind: 'snapshot' | 'window'; from: string | null; to: string | null; timezone: string }
  items: { id: string; label: string; state: string; at: string | null; value: string | null }[]
  meta: { total: number; current_page: number; last_page: number; per_page: number } | null
}
export const dashboardText = (key: string) => t(('dashboards.' + key) as Parameters<typeof t>[0])
export type DashboardIndicator = {
  enabled: boolean; checked_at: string; engine_version: string; id: string | null; version: string | null
  state: 'missing' | 'queued' | 'fresh' | 'stale' | 'failed'
  evaluated_at: string | null; expires_at: string | null; revision: number | null
  result: { total: number; groups: { state: string; count: number }[] } | null
  history: { items: { number: number; engine_version: string; evaluated_at: string; expires_at: string }[]; meta: { current_page: number; last_page: number; total: number } } | null
}
