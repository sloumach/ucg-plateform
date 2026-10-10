import { beforeEach, describe, expect, it, vi } from 'vitest'
import { requestPasswordReset, resetPassword } from './auth'

const fetchMock = vi.fn<typeof fetch>()

describe('identity api client', () => {
  beforeEach(() => {
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    document.cookie = 'XSRF-TOKEN=test-csrf-token'
  })

  it('prépare CSRF puis envoie une demande de récupération normalisée', async () => {
    fetchMock
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
      .mockResolvedValueOnce(successResponse('Demande enregistrée.'))

    const notification = await requestPasswordReset('member@ucg.test')

    expect(notification?.message).toBe('Demande enregistrée.')
    expect(fetchMock).toHaveBeenCalledTimes(2)
    expect(fetchMock.mock.calls[1]?.[0]).toBe(
      'http://localhost:8000/api/v1/auth/forgot-password',
    )
    expect(fetchMock.mock.calls[1]?.[1]).toMatchObject({
      method: 'POST',
      credentials: 'include',
      body: JSON.stringify({ email: 'member@ucg.test' }),
    })
  })

  it('transmet le jeton et la confirmation lors de la réinitialisation', async () => {
    fetchMock
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
      .mockResolvedValueOnce(successResponse('Mot de passe modifié.'))

    await resetPassword(
      'signed-token',
      'member@ucg.test',
      'Strong-password-456!',
      'Strong-password-456!',
    )

    expect(fetchMock.mock.calls[1]?.[1]?.body).toBe(
      JSON.stringify({
        token: 'signed-token',
        email: 'member@ucg.test',
        password: 'Strong-password-456!',
        password_confirmation: 'Strong-password-456!',
      }),
    )
  })
})

function successResponse(message: string): Response {
  return new Response(
    JSON.stringify({
      data: null,
      meta: { request_id: 'request-123' },
      notification: { type: 'success', message },
    }),
    { status: 200, headers: { 'Content-Type': 'application/json' } },
  )
}
