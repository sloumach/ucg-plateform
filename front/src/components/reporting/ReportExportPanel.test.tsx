import { beforeEach, afterEach, expect, it, vi } from 'vitest'
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ActiveOrganization } from '../../api/tenancy'
import type { ReportExportPage } from '../../api/reportExports'
import { ApiClientError } from '../../api/errors'
import { NotificationContext } from '../feedback/notificationContext'
import { ReportExportPanel } from './ReportExportPanel'
const fake = vi.hoisted(() => ({ request: vi.fn(), notify: vi.fn(), invalid: vi.fn() }))
vi.mock('../../api/tenancy', async (original) => ({ ...await original<typeof import('../../api/tenancy')>(), tenancyRequest: fake.request }))
const org = '11111111-1111-4111-8111-111111111111', revision = '22222222-2222-4222-8222-222222222222', id = '33333333-3333-4333-8333-333333333333'
const active: ActiveOrganization = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'], permissions: ['reporting.export', 'reporting.direction', 'sports.read'], actor_user_id: 42, is_owner: false, membership_id: org }, revision, confirmed: true }
const cards = [{ id: 'active_people', label: 'Effectif actif', definition: 'Fiches actives', indicator_supported: true, scope: 'organization' as const, period_kind: 'snapshot' as const, status: 'available' as const, reason: null }]
const query = { view: 'direction', from: '2026-10-21', to: '2026-10-25' }
let response: ReportExportPage, failure: ApiClientError | null
beforeEach(() => {
  vi.resetAllMocks(); failure = null
  response = { checked_at: '2026-10-21T10:00:00Z', enabled: true, max_rows: 500, lifetime_minutes: 60, items: [], meta: { current_page: 1, last_page: 1, total: 0 } }
  fake.request.mockImplementation(async (_path: string, options: { method?: string } = {}) => {
    if (failure) throw failure
    return { data: structuredClone(response), meta: { request_id: 'rep004-test' }, ...(options.method === 'POST' ? { notification: { type: 'success', message: 'Demande enregistrée.' } } : {}) }
  })
})
afterEach(() => { vi.restoreAllMocks(); vi.useRealTimers() })
function show(selection = active) {
  return render(<NotificationContext.Provider value={{ notify: fake.notify, dismiss: vi.fn() }}><ReportExportPanel active={selection} disabled={false} onInvalid={fake.invalid} cards={cards} query={query} /></NotificationContext.Provider>)
}
async function open() { await userEvent.click(screen.getByRole('button', { name: 'Ouvrir mes exports' })); await screen.findByText(/Lignes maximales/) }
function completed() {
  response.items = [{ id, card: 'active_people', label: 'Effectif actif', view_label: 'Direction', period_kind: 'snapshot', view: 'direction', from: query.from, to: query.to, timezone: 'UTC', state: 'completed', row_count: 0, created_at: response.checked_at, generated_at: response.checked_at, expires_at: '2026-10-21T11:00:00Z' }]
}
it('stays hidden without export permission and performs no automatic request', () => {
  const view = show(); expect(fake.request).not.toHaveBeenCalled(); view.unmount()
  show({ ...active, context: { ...active.context!, permissions: ['reporting.direction'] } })
  expect(screen.queryByText('Exports privés de pilotage')).toBeNull()
})
it('requires confirmation and submits the exact tenant, filters, revision and stable request key', async () => {
  show(); await open()
  fireEvent.submit(screen.getByRole('form', { name: 'Demande d’export' }))
  expect(fake.request.mock.calls.some(([, options]) => options?.method === 'POST')).toBe(false)
  await userEvent.click(screen.getByRole('checkbox'))
  await userEvent.click(screen.getByRole('button', { name: 'Demander l’export CSV' }))
  await waitFor(() => expect(fake.notify).toHaveBeenCalledWith(expect.objectContaining({ type: 'success', message: 'Demande enregistrée.' })))
  const [path, options] = fake.request.mock.calls.find(([, options]) => options?.method === 'POST')!
  expect(path).toBe('tenants/' + org + '/exports')
  expect(options.revision).toBe(revision)
  expect(options.body).toEqual({ ...query, card: 'active_people', confirm: true, request_key: expect.any(String) })
  expect(options.body.request_key).toMatch(/^[a-f0-9-]{36}$/)
})
it('keeps the idempotency key on an ambiguous request failure', async () => {
  show(); await open(); await userEvent.click(screen.getByRole('checkbox'))
  failure = new ApiClientError('Transport indisponible.')
  await userEvent.click(screen.getByRole('button', { name: 'Demander l’export CSV' }))
  await screen.findByText('Transport indisponible.')
  failure = null; await userEvent.click(screen.getByRole('button', { name: 'Actualiser mes exports' })); await screen.findByRole('checkbox')
  await userEvent.click(screen.getByRole('checkbox')); await userEvent.click(screen.getByRole('button', { name: 'Demander l’export CSV' }))
  const posts = fake.request.mock.calls.filter(([, options]) => options?.method === 'POST')
  expect(posts).toHaveLength(2); expect(posts[0][1].body.request_key).toBe(posts[1][1].body.request_key)
})
it('blocks requests for unconfirmed context and disabled processing', async () => {
  const view = show({ ...active, confirmed: false }); await open(); expect(screen.getByRole('checkbox')).toBeDisabled(); view.unmount()
  response.enabled = false; show(); await open()
  expect(screen.getByText('La génération des exports est actuellement désactivée.')).toBeVisible()
  expect(screen.queryByRole('checkbox')).toBeNull()
})
it('downloads a ready CSV only on demand and releases its local URL', async () => {
  completed()
  const create = vi.fn(() => 'blob:test'), revoke = vi.fn(), click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
  Object.defineProperty(URL, 'createObjectURL', { value: create, configurable: true })
  Object.defineProperty(URL, 'revokeObjectURL', { value: revoke, configurable: true })
  fake.request.mockImplementation(async (path: string) => path.endsWith('/download') ? new Blob(['id;label'], { type: 'text/csv' }) : { data: response })
  show(); await open(); expect(create).not.toHaveBeenCalled()
  await userEvent.click(screen.getByRole('button', { name: 'Télécharger le CSV' }))
  await waitFor(() => expect(click).toHaveBeenCalledOnce())
  expect(create).toHaveBeenCalledOnce(); expect(revoke).toHaveBeenCalledWith('blob:test')
  expect(fake.request.mock.calls.find(([path]) => path.endsWith('/download'))?.[1]).toEqual(expect.objectContaining({ revision, download: true }))
})
it('hides old results and invalidates context after revocation', async () => {
  completed(); show(); await open()
  failure = new ApiClientError('Accès révoqué.', { status: 403 })
  await userEvent.click(screen.getByRole('button', { name: 'Télécharger le CSV' }))
  await waitFor(() => expect(fake.invalid).toHaveBeenCalledOnce())
  expect(screen.queryByRole('button', { name: 'Télécharger le CSV' })).toBeNull()
})
it('aborts an outstanding download when the organization changes and never saves its late response', async () => {
  completed()
  let resolve: ((blob: Blob) => void) | undefined, signal: AbortSignal | undefined
  const create = vi.fn(); Object.defineProperty(URL, 'createObjectURL', { value: create, configurable: true })
  fake.request.mockImplementation(async (path: string, options: { signal?: AbortSignal }) => {
    if (path.endsWith('/download')) { signal = options.signal; return new Promise<Blob>((done) => { resolve = done }) }
    return { data: response }
  })
  const view = show(); await open(); await userEvent.click(screen.getByRole('button', { name: 'Télécharger le CSV' })); view.unmount()
  expect(signal?.aborted).toBe(true)
  await act(async () => resolve?.(new Blob(['private'], { type: 'text/csv' })))
  expect(create).not.toHaveBeenCalled()
})
it('paginates metadata and retains no ready download after refresh failure', async () => {
  completed(); response.meta.last_page = 2; response.meta.total = 11
  show(); await open(); await userEvent.click(screen.getByRole('button', { name: /^Page 2$/ }))
  await waitFor(() => expect(fake.request.mock.calls.some(([path]) => path.endsWith('page=2'))).toBe(true))
  failure = new ApiClientError('Lecture impossible.')
  await userEvent.click(screen.getByRole('button', { name: 'Actualiser mes exports' }))
  await screen.findByText('Lecture impossible.')
  expect(screen.queryByRole('button', { name: 'Télécharger le CSV' })).toBeNull()
})

it('disables the download at expiry using server time even when the local clock differs', async () => {
  completed(); response.items[0].expires_at = '2026-10-21T10:00:01Z'
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
  show()
  await act(async () => { fireEvent.click(screen.getByRole('button', { name: 'Ouvrir mes exports' })) })
  expect(screen.getByRole('button', { name: 'Télécharger le CSV' })).toBeEnabled()
  await act(async () => { vi.advanceTimersByTime(1001) })
  expect(screen.getByRole('button', { name: 'Télécharger le CSV' })).toBeDisabled()
  expect(screen.getByText('Effectif actif · Direction · Expiré')).toBeVisible()
})
