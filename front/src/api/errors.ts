import type { ApiErrorResponse, ApiFieldErrors } from './contracts'

type ApiClientErrorOptions = {
  code?: string
  fieldErrors?: ApiFieldErrors
  requestId?: string
  status?: number
  retryAfter?: number
}

export class ApiClientError extends Error {
  readonly code?: string
  readonly fieldErrors: ApiFieldErrors
  readonly requestId?: string
  readonly status?: number
  readonly retryAfter?: number

  constructor(message: string, options: ApiClientErrorOptions = {}) {
    super(message)
    this.name = 'ApiClientError'
    this.code = options.code
    this.fieldErrors = options.fieldErrors ?? {}
    this.requestId = options.requestId
    this.status = options.status
    this.retryAfter = options.retryAfter
  }
}

export async function apiClientErrorFromResponse(
  response: Response,
  fallbackMessage: string,
): Promise<ApiClientError> {
  const payload = await readErrorPayload(response)
  const retryHeader = response.headers.get('Retry-After')
  const retryAfter = retryHeader !== null && /^\d+$/.test(retryHeader) ? Number(retryHeader) : undefined

  return new ApiClientError(payload?.message ?? fallbackMessage, {
    code: payload?.code,
    fieldErrors: payload?.errors,
    requestId: payload?.meta.request_id,
    status: response.status,
    retryAfter: retryAfter !== undefined && Number.isSafeInteger(retryAfter) ? retryAfter : undefined,
  })
}

export function isAbortError(error: unknown): boolean {
  return error instanceof DOMException && error.name === 'AbortError'
}

async function readErrorPayload(response: Response): Promise<ApiErrorResponse | null> {
  try {
    const payload: unknown = await response.json()

    return isApiErrorResponse(payload) ? payload : null
  } catch {
    return null
  }
}

function isApiErrorResponse(value: unknown): value is ApiErrorResponse {
  if (!isRecord(value) || !isRecord(value.meta) || !isRecord(value.errors)) {
    return false
  }

  return (
    typeof value.message === 'string' &&
    typeof value.code === 'string' &&
    typeof value.meta.request_id === 'string' &&
    Object.values(value.errors).every(
      (messages) =>
        Array.isArray(messages) &&
        messages.every((message) => typeof message === 'string'),
    )
  )
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}
