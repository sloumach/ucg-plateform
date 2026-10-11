import type { ApiSuccessResponse } from './contracts'
import { tenancyRequest } from './tenancy'

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
export type Person = { id: string; version: string } & Partial<PersonFields>
export type PersonCreateInput = PersonFields
export type PersonPatchInput = Partial<PersonFields> & { version: string }

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
