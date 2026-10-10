import { t } from '../i18n/fr'
import type { ApiNotification, ApiSuccessResponse } from './contracts'
import { apiClientErrorFromResponse } from './errors'

export type AuthenticatedUser = {
  id: number
  name: string
  email: string
  email_verified: boolean
}

const apiBaseUrl =
  import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000/api/v1'
const backendOrigin = new URL(apiBaseUrl).origin

export async function getCurrentUser(
  signal?: AbortSignal,
): Promise<AuthenticatedUser | null> {
  const response = await fetch(`${apiBaseUrl}/auth/me`, {
    credentials: 'include',
    headers: { Accept: 'application/json' },
    signal,
  })

  if (response.status === 401) {
    return null
  }

  if (!response.ok) {
    throw await apiClientErrorFromResponse(
      response,
      t('auth.sessionUnavailableMessage'),
    )
  }

  return ((await response.json()) as ApiSuccessResponse<AuthenticatedUser>).data
}

export async function login(email: string, password: string): Promise<AuthenticatedUser> {
  await prepareCsrfCookie()

  const response = await fetch(`${apiBaseUrl}/auth/login`, {
    method: 'POST',
    credentials: 'include',
    headers: mutationHeaders(),
    body: JSON.stringify({ email, password }),
  })

  if (!response.ok) {
    throw await apiClientErrorFromResponse(response, t('auth.loginUnavailable'))
  }

  return ((await response.json()) as ApiSuccessResponse<AuthenticatedUser>).data
}

export async function logout(): Promise<ApiNotification | null> {
  await prepareCsrfCookie()

  const response = await fetch(`${apiBaseUrl}/auth/logout`, {
    method: 'POST',
    credentials: 'include',
    headers: mutationHeaders(),
  })

  if (!response.ok) {
    throw await apiClientErrorFromResponse(response, t('auth.logoutFailed'))
  }

  const payload = (await response.json()) as ApiSuccessResponse<null>

  return payload.notification ?? null
}

export async function requestPasswordReset(email: string): Promise<ApiNotification | null> {
  return authMutation('/auth/forgot-password', { email }, t('auth.recoveryUnavailable'))
}

export async function resetPassword(
  token: string,
  email: string,
  password: string,
  passwordConfirmation: string,
): Promise<ApiNotification | null> {
  return authMutation(
    '/auth/reset-password',
    { token, email, password, password_confirmation: passwordConfirmation },
    t('auth.resetUnavailable'),
  )
}

export async function sendEmailVerification(): Promise<ApiNotification | null> {
  return authMutation(
    '/auth/email/verification-notification',
    {},
    t('auth.verificationUnavailable'),
  )
}

export async function revokeOtherSessions(password: string): Promise<ApiNotification | null> {
  return authMutation(
    '/auth/sessions/revoke-others',
    { password },
    t('auth.sessionsRevokeUnavailable'),
  )
}

async function authMutation(
  path: string,
  body: Record<string, string>,
  fallbackMessage: string,
): Promise<ApiNotification | null> {
  await prepareCsrfCookie()

  const response = await fetch(`${apiBaseUrl}${path}`, {
    method: 'POST',
    credentials: 'include',
    headers: mutationHeaders(),
    body: JSON.stringify(body),
  })

  if (!response.ok) {
    throw await apiClientErrorFromResponse(response, fallbackMessage)
  }

  const payload = (await response.json()) as ApiSuccessResponse<null>

  return payload.notification ?? null
}

export async function prepareCsrfCookie(): Promise<void> {
  const response = await fetch(`${backendOrigin}/sanctum/csrf-cookie`, {
    credentials: 'include',
    headers: { Accept: 'application/json' },
  })

  if (!response.ok) {
    throw await apiClientErrorFromResponse(response, t('auth.loginUnavailable'))
  }
}

export function mutationHeaders(): HeadersInit {
  const csrfToken = document.cookie
    .split('; ')
    .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
    ?.split('=')[1]

  return {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    ...(csrfToken ? { 'X-XSRF-TOKEN': decodeURIComponent(csrfToken) } : {}),
  }
}
