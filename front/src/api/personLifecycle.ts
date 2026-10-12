import type { ApiSuccessResponse } from './contracts'
import { tenancyRequest, type Page } from './tenancy'

export type PersonPeriod = { id: string; version: string; person_id: string; kind: 'assignment' | 'unavailability'; role_key: string | null; starts_at: string; ends_at: string | null; status: 'active' | 'closed' | 'cancelled'; effective: boolean }
export type DepartureItem = { id: string; version: string; person_id: string; kind: 'equipment' | 'obligation'; title: string; notes: string | null; status: 'open' | 'resolved' | 'waived'; due_at: string | null; completed_at: string | null }
export type PersonDeparture = { departed: boolean; person_version: string; id?: string; person_id?: string; departed_at?: string; reason_key?: string; pending_items_count?: number }
export type PersonPeriodInput = Pick<PersonPeriod, 'kind' | 'starts_at'> & Partial<Pick<PersonPeriod, 'role_key' | 'ends_at'>> & { status?: 'active' | 'cancelled' }
export type DepartureItemInput = Pick<DepartureItem, 'kind' | 'title'> & Partial<Pick<DepartureItem, 'notes' | 'status' | 'due_at'>>
export type PersonDepartureInput = { version: string; reason_key: 'resignation' | 'end_of_contract' | 'decision' | 'other'; confirm: true; acknowledge_pending?: boolean }
type Options = { revision?: string; signal?: AbortSignal; page?: number }
export function personLifecyclePath(org: string, person: string, suffix: string) { return 'tenants/' + encodeURIComponent(org) + '/people/' + encodeURIComponent(person) + '/' + suffix }
export function listPersonPeriods(org: string, person: string, options: Options = {}) {
  return tenancyRequest<Page<PersonPeriod> & { meta: { unavailable_now: boolean } }>(personLifecyclePath(org, person, 'periods') + '?per_page=10&page=' + (options.page ?? 1), options)
}
export function listDepartureItems(org: string, person: string, options: Options = {}) {
  return tenancyRequest<Page<DepartureItem> & { meta: { pending_items_count: number } }>(personLifecyclePath(org, person, 'departure-items') + '?per_page=10&page=' + (options.page ?? 1), options)
}
export function getPersonDeparture(org: string, person: string, options: Options = {}) {
  return tenancyRequest<ApiSuccessResponse<PersonDeparture>>(personLifecyclePath(org, person, 'departure'), options)
}
export function createPersonPeriod(org: string, person: string, body: PersonPeriodInput, revision?: string) {
  return tenancyRequest<ApiSuccessResponse<Partial<PersonPeriod> & Pick<PersonPeriod, 'id' | 'version'>>>(personLifecyclePath(org, person, 'periods'), { method: 'POST', body, revision })
}
export function updatePersonPeriod(org: string, person: string, id: string, body: Partial<Omit<PersonPeriodInput, 'kind' | 'role_key'>> & { version: string }, revision?: string) {
  return tenancyRequest<ApiSuccessResponse<Partial<PersonPeriod> & Pick<PersonPeriod, 'id' | 'version'>>>(personLifecyclePath(org, person, 'periods/' + encodeURIComponent(id)), { method: 'PATCH', body, revision })
}
export function createDepartureItem(org: string, person: string, body: DepartureItemInput, revision?: string) {
  return tenancyRequest<ApiSuccessResponse<Partial<DepartureItem> & Pick<DepartureItem, 'id' | 'version'>>>(personLifecyclePath(org, person, 'departure-items'), { method: 'POST', body, revision })
}
export function updateDepartureItem(org: string, person: string, id: string, body: Partial<Omit<DepartureItemInput, 'kind'>> & { version: string }, revision?: string) {
  return tenancyRequest<ApiSuccessResponse<Partial<DepartureItem> & Pick<DepartureItem, 'id' | 'version'>>>(personLifecyclePath(org, person, 'departure-items/' + encodeURIComponent(id)), { method: 'PATCH', body, revision })
}
export function departPerson(org: string, person: string, body: PersonDepartureInput, revision?: string) {
  return tenancyRequest<ApiSuccessResponse<PersonDeparture>>(personLifecyclePath(org, person, 'departure'), { method: 'POST', body, revision })
}
