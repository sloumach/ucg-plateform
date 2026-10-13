import type { ApiSuccessResponse } from './contracts'
import { tenancyRequest, type Page } from './tenancy'
import { t, type TranslationKey } from '../i18n/fr'

export const catalogueKinds = ['game', 'platform', 'provider', 'region', 'mode', 'role', 'map', 'release'] as const
export type CatalogueKind = typeof catalogueKinds[number]
export const restrictionKinds = ['platform', 'provider', 'region', 'mode', 'role', 'map', 'release'] as const
export type RestrictionKind = typeof restrictionKinds[number]
export const scopedKinds: CatalogueKind[] = ['mode', 'role', 'map', 'release']
export type Choice = { key: string; name: string }
export type GameDefinition = { platforms: Record<string, string[]>; regions: string[] }
export type CatalogueEntry = Choice & {
  id: string; kind: CatalogueKind; game_id: string | null; active: boolean; definition: GameDefinition | null; version: string
}
export type CataloguePage = Page<CatalogueEntry> & { meta: { catalogue_version: string; can_manage: boolean } }
export type GameChoices = Record<RestrictionKind, Choice[]>
export type GameRestrictions = Partial<Record<RestrictionKind, string[]>>
export type OrganizationGame = Choice & {
  id: string; available: boolean; enabled: boolean; configured_enabled: boolean; restrictions: GameRestrictions; version: string | null
}
export type OrganizationGameDetail = OrganizationGame & { options: GameChoices; effective_options: GameChoices; catalogue_version: string }
export type GlobalChoices = { games: CatalogueEntry[]; platforms: CatalogueEntry[]; providers: CatalogueEntry[]; regions: CatalogueEntry[] }
export function kindLabel(kind: CatalogueKind) { return t(('games.kind.' + kind) as TranslationKey) }
export function cataloguePath(kind: CatalogueKind, page = 1, gameId?: string, perPage = 10) {
  return 'game-catalogue?kind=' + kind + '&per_page=' + perPage + '&page=' + page + (gameId ? '&game_id=' + encodeURIComponent(gameId) : '')
}
export async function globalChoices(signal?: AbortSignal): Promise<GlobalChoices> {
  const [games, platforms, providers, regions] = await Promise.all(
    (['game', 'platform', 'provider', 'region'] as const).map((kind) => tenancyRequest<CataloguePage>(cataloguePath(kind, 1, undefined, 100), { signal })),
  )
  return { games: games.data, platforms: platforms.data, providers: providers.data, regions: regions.data }
}
export function organizationGamesPath(org: string, game?: string) { return 'tenants/' + org + '/games' + (game ? '/' + game : '') }
export function getOrganizationGame(org: string, game: string, revision?: string, signal?: AbortSignal) {
  return tenancyRequest<ApiSuccessResponse<OrganizationGameDetail>>(organizationGamesPath(org, game), { revision, signal })
}
