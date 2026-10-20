import { t } from '../i18n/fr'
export const searchTypes = ['people', 'teams', 'matches', 'competitions', 'contract_documents', 'competition_documents'] as const
export type SearchType = typeof searchTypes[number]
export type SearchItem = { id: string; label: string; reference: string | null; parent_id: string | null }
export type SearchPage = { type: SearchType; items: SearchItem[]; meta: { per_page: number; examined: number; scan_limit: number; has_more: boolean; next_cursor: string | null } }
export const searchText = (key: string) => t(('search.' + key) as Parameters<typeof t>[0])
