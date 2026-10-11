import type { ApiSuccessResponse } from './contracts'
import { mutationHeaders, prepareCsrfCookie } from './auth'
import { apiClientErrorFromResponse } from './errors'
import { t } from '../i18n/fr'

export type MfaStatus = { required: boolean; enabled: boolean; verified: boolean }
export type MfaEnrollment = { secret: string; provisioning_uri: string; expires_in: number }

const baseUrl = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000/api/v1'

export async function mfaRequest<T>(
  action: 'enrollment' | 'confirmation' | 'challenge' | 'recovery-codes' | 'disable',
  body: Record<string, string>,
): Promise<ApiSuccessResponse<T>> {
  await prepareCsrfCookie()
  const response = await fetch(baseUrl + '/auth/mfa' + (action === 'disable' ? '' : '/' + action), {
    method: action === 'disable' ? 'DELETE' : 'POST',
    credentials: 'include',
    headers: mutationHeaders(),
    body: JSON.stringify(body),
  })
  if (!response.ok) throw await apiClientErrorFromResponse(response, t('mfa.error'))
  return (await response.json()) as ApiSuccessResponse<T>
}