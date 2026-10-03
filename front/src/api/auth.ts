export type AuthenticatedUser = {
  id: number
  name: string
  email: string
}

type UserResponse = {
  data: AuthenticatedUser
}

type ValidationErrorResponse = {
  message?: string
  errors?: Record<string, string[]>
}

const apiBaseUrl =
  import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000/api/v1'
const backendOrigin = new URL(apiBaseUrl).origin

export class AuthenticationError extends Error {}

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
    throw new Error(`Current user request failed with status ${response.status}`)
  }

  return ((await response.json()) as UserResponse).data
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
    const payload = (await response.json()) as ValidationErrorResponse
    const firstFieldError = Object.values(payload.errors ?? {}).flat()[0]

    throw new AuthenticationError(
      firstFieldError ?? payload.message ?? 'Connexion impossible.',
    )
  }

  return ((await response.json()) as UserResponse).data
}

export async function logout(): Promise<void> {
  await prepareCsrfCookie()

  const response = await fetch(`${apiBaseUrl}/auth/logout`, {
    method: 'POST',
    credentials: 'include',
    headers: mutationHeaders(),
  })

  if (!response.ok) {
    throw new Error(`Logout request failed with status ${response.status}`)
  }
}

async function prepareCsrfCookie(): Promise<void> {
  const response = await fetch(`${backendOrigin}/sanctum/csrf-cookie`, {
    credentials: 'include',
    headers: { Accept: 'application/json' },
  })

  if (!response.ok) {
    throw new Error(`CSRF cookie request failed with status ${response.status}`)
  }
}

function mutationHeaders(): HeadersInit {
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
