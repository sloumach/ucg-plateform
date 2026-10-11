import type { ApiSuccessResponse } from './contracts'
import { tenancyRequest, type Page } from './tenancy'

export type GameProfileStatus = 'active' | 'archived'
export type ObservationFreshness = 'unknown' | 'recent' | 'stale'
export type CatalogueReference = { key: string; name: string }
export type GameProfileCatalogue = ApiSuccessResponse<{
  games: (CatalogueReference & {
    regions: CatalogueReference[]
    platforms: (CatalogueReference & { providers: CatalogueReference[] })[]
  })[]
}>

export type GameProfileCreateInput = {
  game_key: string
  platform_key: string
  region_key: string
  rank?: string | null
  game_role?: string | null
  observed_at?: string | null
  status?: GameProfileStatus
}

export type GameProfilePatchInput = Pick<GameProfileCreateInput, 'rank' | 'game_role' | 'observed_at' | 'status'> & { version: string }
export type GameProfile = { id: string; version: string } & Partial<GameProfileCreateInput & {
  person_id: string
  freshness: ObservationFreshness
  external_accounts_count: number
  data_source: 'manual'
}>

export type ExternalGameAccountCreateInput = {
  provider_key: string
  external_id?: string | null
  display_name?: string | null
  observed_at?: string | null
  status?: GameProfileStatus
}

export type ExternalGameAccountPatchInput = Omit<ExternalGameAccountCreateInput, 'provider_key'> & { version: string }
export type ExternalGameAccount = { id: string; version: string } & Partial<ExternalGameAccountCreateInput & {
  game_profile_id: string
  game_key: string
  platform_key: string
  region_key: string
  freshness: ObservationFreshness
  data_source: 'manual'
  ownership_verified: false
}>

type PageOptions = { perPage?: number; page?: number; revision?: string; signal?: AbortSignal }

function profilePath(organizationId: string, personId: string, profileId?: string) {
  const path = 'tenants/' + encodeURIComponent(organizationId) + '/people/' + encodeURIComponent(personId) + '/game-profiles'
  return profileId === undefined ? path : path + '/' + encodeURIComponent(profileId)
}

function accountPath(organizationId: string, personId: string, profileId: string, accountId?: string) {
  const path = profilePath(organizationId, personId, profileId) + '/external-accounts'
  return accountId === undefined ? path : path + '/' + encodeURIComponent(accountId)
}

function pageQuery(options: PageOptions) {
  return '?' + new URLSearchParams({ per_page: String(options.perPage ?? 20), page: String(options.page ?? 1) })
}

export function getGameProfileCatalogue(organizationId: string, revision?: string, signal?: AbortSignal) {
  return tenancyRequest<GameProfileCatalogue>('tenants/' + encodeURIComponent(organizationId) + '/game-profile-catalogue', { revision, signal })
}

export function listGameProfiles(organizationId: string, personId: string, options: PageOptions = {}) {
  return tenancyRequest<Page<GameProfile>>(profilePath(organizationId, personId) + pageQuery(options), options)
}

export function getGameProfile(organizationId: string, personId: string, profileId: string, revision?: string) {
  return tenancyRequest<ApiSuccessResponse<GameProfile>>(profilePath(organizationId, personId, profileId), { revision })
}

export function createGameProfile(organizationId: string, personId: string, body: GameProfileCreateInput, revision?: string) {
  return tenancyRequest<ApiSuccessResponse<GameProfile>>(profilePath(organizationId, personId), { method: 'POST', body, revision })
}

export function updateGameProfile(organizationId: string, personId: string, profileId: string, body: GameProfilePatchInput, revision?: string) {
  return tenancyRequest<ApiSuccessResponse<GameProfile>>(profilePath(organizationId, personId, profileId), { method: 'PATCH', body, revision })
}

export function listExternalGameAccounts(organizationId: string, personId: string, profileId: string, options: PageOptions = {}) {
  return tenancyRequest<Page<ExternalGameAccount>>(accountPath(organizationId, personId, profileId) + pageQuery(options), options)
}

export function getExternalGameAccount(organizationId: string, personId: string, profileId: string, accountId: string, revision?: string) {
  return tenancyRequest<ApiSuccessResponse<ExternalGameAccount>>(accountPath(organizationId, personId, profileId, accountId), { revision })
}

export function createExternalGameAccount(organizationId: string, personId: string, profileId: string, body: ExternalGameAccountCreateInput, revision?: string) {
  return tenancyRequest<ApiSuccessResponse<ExternalGameAccount>>(accountPath(organizationId, personId, profileId), { method: 'POST', body, revision })
}

export function updateExternalGameAccount(organizationId: string, personId: string, profileId: string, accountId: string, body: ExternalGameAccountPatchInput, revision?: string) {
  return tenancyRequest<ApiSuccessResponse<ExternalGameAccount>>(accountPath(organizationId, personId, profileId, accountId), { method: 'PATCH', body, revision })
}
