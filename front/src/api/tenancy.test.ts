import { afterEach, describe, expect, it, vi } from 'vitest'
import { tenancyRequest } from './tenancy'
import { ApiClientError } from './errors'

describe('tenancyRequest', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
    document.cookie = 'XSRF-TOKEN=; max-age=0; path=/'
  })

  it('utilise session, CSRF et révision pour les mutations sans contexte fourni dans le payload', async () => {
    document.cookie = 'XSRF-TOKEN=csrf%20token; path=/'
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
      .mockResolvedValueOnce(Response.json({ data: { id: 'new-id' } }))
    vi.stubGlobal('fetch', fetchMock)
    await tenancyRequest('tenants/tenant-id/invitations', { method: 'POST', revision: 'revision-id', body: { email: 'member@example.test' } })
    expect(fetchMock).toHaveBeenNthCalledWith(1, expect.stringContaining('/sanctum/csrf-cookie'), expect.objectContaining({ credentials: 'include' }))
    expect(fetchMock).toHaveBeenNthCalledWith(2, expect.stringContaining('/tenants/tenant-id/invitations'), expect.objectContaining({
      credentials: 'include', headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'csrf token', 'X-Tenant-Revision': 'revision-id' }),
      body: JSON.stringify({ email: 'member@example.test' }),
    }))
  })

  it('propage signal et erreurs structurées pour invalider un ancien contexte', async () => {
    const controller = new AbortController()
    const fetchMock = vi.fn().mockResolvedValue(Response.json({
      message: 'Le contexte a changé.', code: 'TENANT_CONTEXT_CHANGED', errors: {}, meta: { request_id: 'request-id' },
    }, { status: 409 }))
    vi.stubGlobal('fetch', fetchMock)
    await expect(tenancyRequest('tenants/id/memberships', { signal: controller.signal, revision: 'old' }))
      .rejects.toMatchObject<Partial<ApiClientError>>({ status: 409, code: 'TENANT_CONTEXT_CHANGED', requestId: 'request-id' })
    expect(fetchMock).toHaveBeenCalledWith(expect.any(String), expect.objectContaining({ signal: controller.signal }))
  })
})
