import { t } from '../i18n/fr'
import { mutationHeaders, prepareCsrfCookie } from './auth'
import type { ApiSuccessResponse } from './contracts'
import { apiClientErrorFromResponse } from './errors'

export type TenantRole = 'owner' | 'administrator' | 'member'
export type MembershipStatus = 'active' | 'suspended' | 'revoked'
export type InvitationStatus = 'pending' | 'accepted' | 'declined' | 'revoked' | 'expired'
export type AccessibleOrganization = {
  id: string
  name: string
  slug: string
  roles: TenantRole[]
  is_owner: boolean
  membership_id: string | null
}
export type ActiveOrganization = {
  context: {
    organization_id: string
    name: string
    slug: string
    timezone: string
    language: string
    roles: TenantRole[]
    permissions: string[]
    is_owner: boolean
    actor_user_id: number
    membership_id: string | null
  } | null
  revision: string | null
  confirmed: boolean
}
export type Membership = {
  id: string
  organization_id: string
  user_id: number
  roles: Exclude<TenantRole, 'owner'>[]
  status: MembershipStatus
  effective: boolean
  starts_at: string
  ends_at: string | null
}
export type Invitation = {
  id: string
  organization_id: string
  organization_name: string
  email: string
  roles: Exclude<TenantRole, 'owner'>[]
  status: InvitationStatus
  starts_at: string
  ends_at: string | null
  expires_at: string
}
export type Page<T> = ApiSuccessResponse<T[]> & {
  meta: { request_id: string; current_page: number; last_page: number; total: number }
}

const baseUrl = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000/api/v1'

export async function tenancyRequest<T>(
  path: string,
  options: { method?: 'GET' | 'POST' | 'PUT' | 'DELETE'; body?: unknown; revision?: string; signal?: AbortSignal } = {},
): Promise<T> {
  const method = options.method ?? 'GET'
  if (method !== 'GET') await prepareCsrfCookie()
  const response = await fetch(`${baseUrl}/${path}`, {
    method,
    credentials: 'include',
    headers: {
      ...(method === 'GET' ? { Accept: 'application/json' } : mutationHeaders()),
      ...(options.revision ? { 'X-Tenant-Revision': options.revision } : {}),
    },
    body: options.body === undefined ? undefined : JSON.stringify(options.body),
    signal: options.signal,
  })
  if (!response.ok) throw await apiClientErrorFromResponse(response, t('tenant.error'))
  return (await response.json()) as T
}
