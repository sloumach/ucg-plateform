import { afterEach, describe, expect, it, vi } from 'vitest'
import { tenancyRequest } from './tenancy'
import { ApiClientError } from './errors'

describe('tenancyRequest', () => {
  it('télécharge un PDF privé avec session, révision et redirections refusées', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response('%PDF-1.4\n%%EOF', { headers: { 'Content-Type': 'application/pdf' } }))
    vi.stubGlobal('fetch', fetchMock)
    const result = await tenancyRequest<Blob>('tenants/id/contracts/id/documents/id/versions/id/download?signature=test', { download: 'pdf', revision: 'current' })
    expect(result.type).toBe('application/pdf')
    expect(fetchMock.mock.calls[0][1]).toEqual(expect.objectContaining({ credentials: 'include', redirect: 'error', headers: { Accept: 'application/pdf', 'X-Tenant-Revision': 'current' } }))
  })
  it('envoie un CSV multipart avec CSRF et sans imposer une frontière Content-Type', async () => {
    document.cookie = 'XSRF-TOKEN=csrf%20token; path=/'
    const fetchMock = vi.fn().mockResolvedValueOnce(new Response(null, { status: 204 })).mockResolvedValueOnce(Response.json({ data: { id: 'import-id' } }))
    vi.stubGlobal('fetch', fetchMock)
    const body = new FormData()
    body.set('file', new File(['pseudo\nAlex'], 'people.csv', { type: 'text/csv' }))
    body.set('delimiter', ',')
    await tenancyRequest('tenants/tenant-id/people-imports', { method: 'POST', body, revision: 'revision-id' })
    const options = fetchMock.mock.calls[1][1]
    expect(options.body).toBe(body)
    expect(options.headers['X-XSRF-TOKEN']).toBe('csrf token')
    expect(options.headers['X-Tenant-Revision']).toBe('revision-id')
    expect(options.headers).not.toHaveProperty('Content-Type')
    expect(options.credentials).toBe('include')
  })
  it('télécharge un rapport avec session et signal sans interpréter du CSV comme du JSON', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response('line,status\r\n2,invalid', { headers: { 'Content-Type': 'text/csv' } }))
    vi.stubGlobal('fetch', fetchMock)
    const controller = new AbortController()
    const result = await tenancyRequest<Blob>('tenants/tenant-id/people-imports/import-id/report', { download: true, signal: controller.signal })
    expect(result.type).toBe('text/csv')
    expect(await result.text()).toContain('2,invalid')
    expect(fetchMock.mock.calls[0][1]).toEqual(expect.objectContaining({ signal: controller.signal, credentials: 'include', headers: { Accept: 'text/csv' } }))
  })
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
