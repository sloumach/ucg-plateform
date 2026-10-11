import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mfaRequest } from './mfa'
import { ApiClientError } from './errors'

const fetchMock = vi.fn<typeof fetch>()

describe('MFA API client', () => {
  beforeEach(() => {
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    document.cookie = 'XSRF-TOKEN=test-csrf-token'
  })

  it('envoie le challenge après CSRF avec les cookies et le code de récupération', async () => {
    fetchMock.mockResolvedValueOnce(new Response(null, { status: 204 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ data: { verified: true }, meta: { request_id: 'mfa' } }), { status: 200 }))
    await mfaRequest('challenge', { recovery_code: '01234567-89abcdef-01234567-89abcdef' })
    expect(fetchMock.mock.calls[1]).toEqual([
      'http://localhost:8000/api/v1/auth/mfa/challenge',
      expect.objectContaining({
        method: 'POST', credentials: 'include',
        headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'test-csrf-token' }),
        body: JSON.stringify({ recovery_code: '01234567-89abcdef-01234567-89abcdef' }),
      }),
    ])
  })

  it('conserve Retry-After et le code d’erreur pour un challenge limité', async () => {
    fetchMock.mockResolvedValueOnce(new Response(null, { status: 204 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({
        message: 'Trop de tentatives.', code: 'RATE_LIMIT_EXCEEDED', errors: {}, meta: { request_id: 'limited' },
      }), { status: 429, headers: { 'Retry-After': '60' } }))
    const failure = await mfaRequest('challenge', { code: '012345' }).catch((error: unknown) => error)
    expect(failure).toBeInstanceOf(ApiClientError)
    expect(failure).toMatchObject({ status: 429, code: 'RATE_LIMIT_EXCEEDED', retryAfter: 60 })
  })
})