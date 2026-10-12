import type { ApiSuccessResponse } from './contracts'
import { tenancyRequest, type Page } from './tenancy'

export type PersonStatus = 'candidate' | 'trial' | 'active' | 'unavailable' | 'substitute' | 'former'

export type PersonFields = {
  pseudonym: string
  language: string
  timezone: string
  status: PersonStatus
  biography_public?: string | null
  user_id?: number | null
  first_name?: string | null
  last_name?: string | null
  contact_email?: string | null
  contact_phone?: string | null
  biography_internal?: string | null
}

// Read permissions determine which fields the server includes in each response.
export type Person = { id: string; version: string; game_profiles_count?: number } & Partial<PersonFields>
export type PersonCreateInput = PersonFields
export type PersonPatchInput = Partial<PersonFields> & { version: string }

export type PeopleView = 'general' | 'sports' | 'administrative'
export type PeopleFilters = {
  view?: PeopleView; q?: string; status?: PersonStatus; language?: string; game_key?: string; platform_key?: string
  account_linked?: 'linked' | 'unlinked'; sort?: 'id' | 'pseudonym' | 'status'; direction?: 'asc' | 'desc'
}
export function listPeople(organizationId: string, filters: PeopleFilters = {}, options: { page?: number; perPage?: number; revision?: string; signal?: AbortSignal } = {}) {
  const query = new URLSearchParams({ page: String(options.page ?? 1), per_page: String(options.perPage ?? 20) })
  for (const [key, value] of Object.entries(filters)) if (value !== undefined && value !== '') query.set(key, value)
  return tenancyRequest<Page<Person>>('tenants/' + encodeURIComponent(organizationId) + '/people?' + query, options)
}

export function getPerson(organizationId: string, personId: string, revision?: string, signal?: AbortSignal) {
  return tenancyRequest<ApiSuccessResponse<Person>>(
    'tenants/' + encodeURIComponent(organizationId) + '/people/' + encodeURIComponent(personId),
    { revision, signal },
  )
}

export function createPerson(organizationId: string, body: PersonCreateInput, revision?: string) {
  return tenancyRequest<ApiSuccessResponse<Person>>(
    'tenants/' + encodeURIComponent(organizationId) + '/people',
    { method: 'POST', body, revision },
  )
}

export function updatePerson(organizationId: string, personId: string, body: PersonPatchInput, revision?: string) {
  return tenancyRequest<ApiSuccessResponse<Person>>(
    'tenants/' + encodeURIComponent(organizationId) + '/people/' + encodeURIComponent(personId),
    { method: 'PATCH', body, revision },
  )
}
