import { beforeEach, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, within, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ActiveOrganization } from '../../api/tenancy'
import type { DashboardCatalogue, DashboardCard, DashboardCardDefinition } from '../../api/dashboards'
import { ApiClientError } from '../../api/errors'
import { DashboardPanel } from './DashboardPanel'

const fake = vi.hoisted(() => ({ request: vi.fn(), invalid: vi.fn() }))
vi.mock('../../api/tenancy', async (original) => ({ ...await original<typeof import('../../api/tenancy')>(), tenancyRequest: fake.request }))
const org = '11111111-1111-4111-8111-111111111111', revision = '22222222-2222-4222-8222-222222222222'
const active: ActiveOrganization = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'Europe/Paris', language: 'fr', roles: ['member'], permissions: ['reporting.direction', 'sports.read'], actor_user_id: 42, is_owner: false, membership_id: org }, revision, confirmed: false }
const definition: DashboardCardDefinition = { id: 'active_people', label: 'Effectif actif', definition: 'Personnes actives, sans déduire une éligibilité.', period_kind: 'snapshot', scope: 'organization', status: 'available', reason: null }
let catalogue: DashboardCatalogue, failure: ApiClientError | null, response: DashboardCard
beforeEach(() => {
  vi.resetAllMocks(); failure = null
  catalogue = { organization_id: org, timezone: 'Europe/Paris', max_days: 31, views: [{ id: 'direction', label: 'Direction', cards: [definition] }] }
  response = { ...definition, organization_id: org, view: 'direction', captured_at: '2026-10-19T10:00:00Z', total: 1, period: { kind: 'snapshot', from: null, to: null, timezone: 'Europe/Paris' }, items: [{ id: 'person', label: '<script>Joueur</script>', state: 'active', at: null, value: '0' }], meta: { current_page: 1, last_page: 1, per_page: 5, total: 1 } }
  fake.request.mockImplementation(async (path: string) => { if (path.endsWith('/dashboards')) return { data: catalogue }; if (failure) throw failure; return { data: response } })
})
function show(selection = active) { return render(<DashboardPanel active={selection} disabled={false} onInvalid={fake.invalid} />) }
async function filters() {
  await userEvent.click(screen.getByRole('button', { name: 'Ouvrir les tableaux de bord' }))
  const form = await screen.findByRole('form', { name: 'Filtres du tableau de bord' })
  fireEvent.change(within(form).getByLabelText('Début de la fenêtre'), { target: { value: '2026-10-19' } })
  fireEvent.change(within(form).getByLabelText('Fin de la fenêtre'), { target: { value: '2026-10-25' } })
  return form
}
async function consult() { fireEvent.submit(await filters()); return screen.findByRole('article', { name: 'Effectif actif' }) }
it('requires an explicit reporting permission and performs no request before opening', async () => {
  const view = show(); expect(fake.request).not.toHaveBeenCalled(); view.unmount()
  show({ ...active, context: { ...active.context!, roles: ['owner'], is_owner: true, permissions: ['sports.read'] } })
  expect(screen.queryByRole('button')).toBeNull(); expect(fake.request).not.toHaveBeenCalled()
})
it('uses only server-authorized views and sends bounded dates and the tenant revision with GET', async () => {
  show(); const form = await filters()
  expect(within(form).getAllByRole('option')).toHaveLength(1); expect(within(form).queryByRole('option', { name: 'Coach' })).toBeNull()
  fireEvent.submit(form); await screen.findByText('Valeur calculée : 0')
  const [path, options] = fake.request.mock.calls.find(([path]) => path.includes('/active_people?'))!
  expect(path.startsWith('tenants/' + org + '/dashboards/')).toBe(true)
  expect(new URLSearchParams(path.split('?')[1])).toEqual(new URLSearchParams({ view: 'direction', from: '2026-10-19', to: '2026-10-25', page: '1' }))
  expect(options.revision).toBe(revision); expect(fake.request.mock.calls.every(([, options]) => !options.method)).toBe(true)
})
it('shows the definition, actual scope, snapshot, timestamp and zero, escaping source labels', async () => {
  const view = show(); const card = await consult()
  expect(await within(card).findByText('Valeur calculée : 0')).toBeVisible()
  expect(within(card).getByText(definition.definition)).toBeVisible(); expect(within(card).getByText('Périmètre : Organisation active')).toBeVisible()
  expect(within(card).getByText(/Instantané actuel/)).toBeVisible(); expect(within(card).getByText(/Lecture effectuée le/)).toBeVisible()
  expect(within(card).getByText('<script>Joueur</script>')).toBeVisible(); expect(view.container.querySelector('script,img,iframe')).toBeNull()
})
it('distinguishes empty feeds from unavailable indicators without reading unsupported sources', async () => {
  catalogue.views[0].cards.push({ ...definition, id: 'budgets', label: 'Budgets', status: 'unavailable', reason: 'Module financier requis.' })
  response.total = 0; response.items = []; response.meta!.total = 0
  show(); const card = await consult(); expect(await within(card).findByText('Total accessible : 0')).toBeVisible()
  const budget = screen.getByRole('article', { name: 'Budgets' }); expect(within(budget).getByText('Module financier requis.')).toBeVisible(); expect(within(budget).queryByText(/Total accessible/)).toBeNull()
  expect(fake.request.mock.calls.some(([path]) => path.includes('/budgets?'))).toBe(false)
})
it('hides all previous results immediately on any period or view change', async () => {
  catalogue.views.push({ id: 'coach', label: 'Coach', cards: [] })
  show(); await consult(); await screen.findByText('<script>Joueur</script>')
  fireEvent.change(screen.getByLabelText('Début de la fenêtre'), { target: { value: '2026-10-18' } })
  expect(screen.queryByText('<script>Joueur</script>')).toBeNull()
  fireEvent.submit(screen.getByRole('form')); await screen.findByText('<script>Joueur</script>')
  await userEvent.selectOptions(screen.getByLabelText('Vue autorisée'), 'coach'); expect(screen.queryByText('<script>Joueur</script>')).toBeNull()
})
it('paginates each card separately and never retains rows from the preceding page while loading', async () => {
  response.meta!.last_page = 2; response.meta!.total = 6
  show(); const card = await consult(); await within(card).findByText('<script>Joueur</script>')
  response = { ...response, items: [{ ...response.items[0], id: 'second', label: 'Deuxième page' }], meta: { ...response.meta!, current_page: 2 } }
  await userEvent.click(within(card).getByRole('button', { name: /^Page 2$/ }))
  expect(await within(card).findByText('Deuxième page')).toBeVisible(); expect(within(card).queryByText('<script>Joueur</script>')).toBeNull()
  expect(fake.request.mock.calls.some(([path]) => path.endsWith('page=2'))).toBe(true)
})
it('shows a card failure independently, removes its old values and permits a read-only retry', async () => {
  show(); const card = await consult(); await within(card).findByText('<script>Joueur</script>')
  failure = new ApiClientError('Source temporairement indisponible.', { status: 409, code: 'REPORTING_SOURCE_UNAVAILABLE', requestId: 'rep001-test' })
  await userEvent.click(within(card).getByRole('button', { name: 'Actualiser cette carte' }))
  expect(await within(card).findByText('Source temporairement indisponible.')).toBeVisible(); expect(within(card).queryByText(/Total accessible/)).toBeNull()
  expect(fake.invalid).not.toHaveBeenCalled(); failure = null
  await userEvent.click(within(card).getByRole('button', { name: 'Réessayer' })); expect(await within(card).findByText('<script>Joueur</script>')).toBeVisible()
})
it('reloads context on authorization denial and never leaves a stale value displayed', async () => {
  show(); const card = await consult(); await within(card).findByText('<script>Joueur</script>')
  failure = new ApiClientError('Accès révoqué.', { status: 403 })
  await userEvent.click(within(card).getByRole('button', { name: 'Actualiser cette carte' }))
  await waitFor(() => expect(fake.invalid).toHaveBeenCalledTimes(1)); expect(within(card).queryByText('<script>Joueur</script>')).toBeNull()
})
it('resets all private results when the organization, actor or revision changes', async () => {
  const view = show(); await consult(); await screen.findByText('<script>Joueur</script>')
  view.rerender(<DashboardPanel active={{ ...active, revision: 'new-revision' }} disabled={false} onInvalid={fake.invalid} />)
  expect(screen.queryByText('<script>Joueur</script>')).toBeNull(); expect(screen.getByRole('button', { name: 'Ouvrir les tableaux de bord' })).toBeVisible()
  view.rerender(<DashboardPanel active={{ ...active, context: { ...active.context!, permissions: [] } }} disabled={false} onInvalid={fake.invalid} />)
  expect(screen.queryByRole('region')).toBeNull()
})
it('displays an explicit window, current audience and personal scope from card metadata', async () => {
  catalogue.views[0].cards = [{ ...definition, period_kind: 'window', scope: 'current_audience' }, { ...definition, id: 'invitations', label: 'Mes invitations', scope: 'self' }]
  show(); const card = await consult()
  expect(within(card).getByText(/Fenêtre incluse : 2026-10-19 → 2026-10-25 · Europe\/Paris/)).toBeVisible()
  expect(within(card).getByText('Périmètre : Vos audiences autorisées')).toBeVisible()
  expect(within(screen.getByRole('article', { name: 'Mes invitations' })).getByText('Périmètre : Vos seules données personnelles')).toBeVisible()
})
