import { describe, expect, it } from 'vitest'
import { ApiClientError, apiClientErrorFromResponse } from './errors'

describe('apiClientErrorFromResponse', () => {
  it('conserve le code, les erreurs de champ et la référence de support', async () => {
    const response = new Response(
      JSON.stringify({
        message: 'Les données transmises sont invalides.',
        code: 'VALIDATION_FAILED',
        errors: {
          email: ['L’adresse e-mail est obligatoire.'],
        },
        meta: {
          request_id: 'request-123',
        },
      }),
      { status: 422 },
    )

    const error = await apiClientErrorFromResponse(response, 'Erreur de secours.')

    expect(error).toBeInstanceOf(ApiClientError)
    expect(error.message).toBe('Les données transmises sont invalides.')
    expect(error.code).toBe('VALIDATION_FAILED')
    expect(error.fieldErrors).toEqual({
      email: ['L’adresse e-mail est obligatoire.'],
    })
    expect(error.requestId).toBe('request-123')
    expect(error.status).toBe(422)
  })

  it('utilise le message de secours lorsque la réponse n est pas conforme', async () => {
    const response = new Response('<html>Service unavailable</html>', {
      status: 503,
      headers: { 'Content-Type': 'text/html' },
    })

    const error = await apiClientErrorFromResponse(response, 'Service indisponible.')

    expect(error.message).toBe('Service indisponible.')
    expect(error.fieldErrors).toEqual({})
    expect(error.status).toBe(503)
  })
})
