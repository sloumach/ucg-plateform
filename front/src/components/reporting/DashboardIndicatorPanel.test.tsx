import { beforeEach, afterEach, expect, it, vi } from 'vitest'
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ActiveOrganization } from '../../api/tenancy'
import type { DashboardIndicator } from '../../api/dashboards'
import { ApiClientError } from '../../api/errors'
import { NotificationContext } from '../feedback/notificationContext'
import { DashboardIndicatorPanel } from './DashboardIndicatorPanel'
import { DashboardPanel } from './DashboardPanel'
const fake = vi.hoisted(() => ({ request: vi.fn(), notify: vi.fn(), invalid: vi.fn() }))
vi.mock('../../api/tenancy', async (original) => ({ ...await original<typeof import('../../api/tenancy')>(), tenancyRequest: fake.request }))
const org = '11111111-1111-4111-8111-111111111111', revision = '22222222-2222-4222-8222-222222222222'
const active: ActiveOrganization = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'], permissions: ['reporting.direction', 'sports.read'], actor_user_id: 42, is_owner: false, membership_id: org }, revision, confirmed: true }
const root = 'tenants/' + org + '/dashboards/active_people/indicator', query = { view: 'direction', from: '2026-10-20', to: '2026-10-25' }
let response: DashboardIndicator, failure: ApiClientError | null
beforeEach(() => {
  vi.resetAllMocks(); failure = null
  response = { enabled: true, checked_at: '2026-10-20T10:00:00Z', engine_version: 'rep-002.v1', id: null, version: null, state: 'missing', evaluated_at: null, expires_at: null, revision: null, result: null, history: null }
  fake.request.mockImplementation(async (_path: string, options: { method?: string } = {}) => {
    if (failure) throw failure
    return { data: structuredClone(response), meta: { request_id: 'rep002-test' }, ...(options.method === 'POST' ? { notification: { type: 'success', message: 'Demande prise en compte.' } } : {}) }
  })
})
afterEach(() => vi.useRealTimers())
function wrapper(children: React.ReactNode) { return <NotificationContext.Provider value={{ notify: fake.notify, dismiss: vi.fn() }}>{children}</NotificationContext.Provider> }
function show(selection = active) { return render(wrapper(<DashboardIndicatorPanel active={selection} disabled={false} onInvalid={fake.invalid} root={root} query={query} />)) }
function fresh() {
  response = { ...response, id: revision, version: revision, state: 'fresh', revision: 1, evaluated_at: response.checked_at, expires_at: '2026-10-20T10:05:00Z', result: { total: 0, groups: [{ state: 'active', count: 0 }] } }
}
it('only reads metadata on consultation and never requests a calculation automatically', async () => {
  show(); expect(await screen.findByText('Aucun calcul enregistré pour ces filtres.')).toBeVisible()
  expect(fake.request).toHaveBeenCalledTimes(1); expect(fake.request.mock.calls[0][0]).toContain(root + '?')
  expect(fake.request.mock.calls.every(([, options]) => !options?.method)).toBe(true)
})
it('requires explicit confirmation and sends exact filters and revision while notifying success', async () => {
  show(); await screen.findByText('Aucun calcul enregistré pour ces filtres.')
  fireEvent.submit(screen.getByRole('checkbox').closest('form')!); expect(fake.request.mock.calls.some(([, options]) => options?.method === 'POST')).toBe(false)
  await userEvent.click(screen.getByRole('checkbox')); response.state = 'queued'
  await userEvent.click(screen.getByRole('button', { name: 'Demander le calcul de cet indicateur' }))
  await screen.findByText('Calcul demandé, résultat en attente.')
  const [path, options] = fake.request.mock.calls.find(([, options]) => options?.method === 'POST')!
  expect(path).toBe(root); expect(options.body).toEqual({ ...query, confirm: true }); expect(options.revision).toBe(revision)
  expect(fake.notify).toHaveBeenCalledWith({ type: 'success', message: 'Demande prise en compte.', requestId: 'rep002-test' })
})
it('shows a valid zero with version and freshness dates without a mutation', async () => {
  fresh(); show(); expect(await screen.findByText('Total calculé accessible : 0')).toBeVisible()
  expect(screen.getByText(/rep-002.v1/)).toBeVisible(); expect(screen.getByText(/Données évaluées le/)).toBeVisible(); expect(screen.getByText(/Validité maximale jusqu’au/)).toBeVisible()
  expect(screen.queryByRole('checkbox')).toBeNull()
})
it('never exposes a stale or failed payload as the current total and retries with optimistic version', async () => {
  fresh(); response.state = 'stale'; response.result!.total = 99999
  show(); await screen.findByText('Résultat périmé : un nouveau calcul est nécessaire.')
  expect(screen.queryByText(/99999/)).toBeNull(); await userEvent.click(screen.getByRole('checkbox'))
  response.state = 'queued'; await userEvent.click(screen.getByRole('button', { name: 'Demander le calcul de cet indicateur' }))
  await screen.findByText('Calcul demandé, résultat en attente.')
  expect(fake.request.mock.calls.find(([, options]) => options?.method === 'POST')![1].body.version).toBe(revision)
})
it('hides totals when their displayed validity expires even without another server request', async () => {
  vi.useFakeTimers(); fresh()
  await act(async () => { show(); await Promise.resolve() })
  expect(screen.getByText('Total calculé accessible : 0')).toBeVisible()
  await act(async () => { vi.advanceTimersByTime(300001) })
  expect(screen.queryByText('Total calculé accessible : 0')).toBeNull()
  expect(screen.getByText('Résultat périmé : un nouveau calcul est nécessaire.')).toBeVisible()
  expect(fake.request).toHaveBeenCalledTimes(1)
})
it('deducts request and rendering time from the validity interval', async () => {
  vi.useFakeTimers(); fresh()
  fake.request.mockImplementation(async () => {
    await new Promise<void>((resolve) => setTimeout(resolve, 5000))
    return { data: structuredClone(response) }
  })
  await act(async () => { show() })
  await act(async () => { await vi.advanceTimersByTimeAsync(5000) })
  expect(screen.getByText('Total calculé accessible : 0')).toBeVisible()
  await act(async () => { vi.advanceTimersByTime(295001) })
  expect(screen.queryByText('Total calculé accessible : 0')).toBeNull()
})
it('blocks processing when disabled or the active organization has not been confirmed', async () => {
  response.enabled = false; const view = show(); await screen.findByText('Le calcul différé est actuellement indisponible.'); expect(screen.queryByRole('checkbox')).toBeNull(); view.unmount()
  response.enabled = true; show({ ...active, confirmed: false }); await screen.findByText('Confirmez l’organisation active avant de demander un calcul.')
  expect(screen.getByRole('checkbox')).toBeDisabled(); expect(screen.getByRole('button', { name: 'Demander le calcul de cet indicateur' })).toBeDisabled()
})
it('removes the old total on refresh failure and reloads context on revocation', async () => {
  fresh(); show(); await screen.findByText('Total calculé accessible : 0')
  failure = new ApiClientError('Accès révoqué.', { status: 403 })
  await userEvent.click(screen.getByRole('button', { name: 'Vérifier la fraîcheur et le résultat' }))
  await waitFor(() => expect(fake.invalid).toHaveBeenCalledTimes(1)); expect(screen.queryByText('Total calculé accessible : 0')).toBeNull()
})
it('paginates revision metadata independently without showing historical results as current', async () => {
  fresh(); response.history = { items: [{ number: 1, engine_version: 'rep-002.v1', evaluated_at: response.checked_at, expires_at: response.expires_at! }], meta: { current_page: 1, last_page: 2, total: 11 } }
  show(); await screen.findByText('Total calculé accessible : 0'); await userEvent.click(screen.getByText('Historique des versions et dates de calcul'))
  await userEvent.click(screen.getByRole('button', { name: /^Page 2$/ })); await waitFor(() => expect(fake.request.mock.calls.some(([path]) => path.endsWith('page=2'))).toBe(true))
})
it('defers live detail queries for supported cards until the reader explicitly opens them', async () => {
  const card = { id: 'active_people', indicator_supported: true, label: 'Effectif actif', definition: 'Fiches actives', period_kind: 'snapshot', scope: 'organization', status: 'available', reason: null }
  fake.request.mockImplementation(async (path: string) => {
    if (path.endsWith('/dashboards')) return { data: { organization_id: org, timezone: 'UTC', max_days: 31, views: [{ id: 'direction', label: 'Direction', cards: [card] }] } }
    if (path.includes('/indicator?')) return { data: response }
    return { data: { ...card, captured_at: response.checked_at, total: 0, items: [], meta: null } }
  })
  render(wrapper(<DashboardPanel active={active} disabled={false} onInvalid={fake.invalid} />))
  await userEvent.click(screen.getByRole('button', { name: 'Ouvrir les tableaux de bord' })); fireEvent.submit(await screen.findByRole('form', { name: 'Filtres du tableau de bord' }))
  await screen.findByText('Aucun calcul enregistré pour ces filtres.')
  expect(fake.request.mock.calls.some(([path]) => path.includes('/active_people?'))).toBe(false)
  await userEvent.click(screen.getByRole('button', { name: 'Consulter les détails actuels' }))
  expect(await screen.findByText('Total accessible : 0')).toBeVisible()
})
