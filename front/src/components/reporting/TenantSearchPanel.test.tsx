import { beforeEach, expect, it, vi } from 'vitest'
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ActiveOrganization } from '../../api/tenancy'
import type { SearchPage, SearchType } from '../../api/search'
import { ApiClientError } from '../../api/errors'
import { TenantSearchPanel } from './TenantSearchPanel'
const fake = vi.hoisted(() => ({ request: vi.fn(), invalid: vi.fn() }))
vi.mock('../../api/tenancy', async (original) => ({ ...await original<typeof import('../../api/tenancy')>(), tenancyRequest: fake.request }))
const active: ActiveOrganization = { context: { organization_id: '11111111-1111-4111-8111-111111111111', name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'], permissions: ['sports.read'], actor_user_id: 42, is_owner: false, membership_id: 'membership' }, revision: 'revision-1', confirmed: true }
let response: SearchPage, types: SearchType[], failure: ApiClientError | null
beforeEach(() => {
  vi.resetAllMocks(); types = ['people', 'teams', 'matches']; failure = null
  response = { type: 'people', items: [{ id: 'record-1', label: 'Alpha player', reference: null, parent_id: null }], meta: { per_page: 10, examined: 1, scan_limit: 100, has_more: false, next_cursor: null } }
  fake.request.mockImplementation(async (path: string) => {
    if (failure) throw failure
    return { data: path.endsWith('/types') ? { types } : structuredClone(response), meta: { request_id: 'search-test' } }
  })
})
function show(selection = active) { return render(<TenantSearchPanel active={selection} disabled={false} onInvalid={fake.invalid} />) }
async function open() { await userEvent.click(screen.getByRole('button', { name: 'Ouvrir la recherche' })); await screen.findByRole('combobox', { name: 'Catégorie' }) }
async function search(value = 'Al') { fireEvent.change(screen.getByLabelText('Texte à rechercher'), { target: { value } }); fireEvent.submit(screen.getByRole('form', { name: 'Filtres de recherche' })) }
it('loads only allowed categories when opened and makes no write requests', async () => {
  show(); expect(fake.request).not.toHaveBeenCalled(); await open()
  expect(screen.queryByRole('option', { name: 'Documents contractuels' })).toBeNull()
  expect(fake.request).toHaveBeenCalledTimes(1); expect(fake.request.mock.calls.every(([, options]) => !options?.method)).toBe(true)
})
it('sends exact filters and tenant revision and renders labels as text', async () => {
  response.items[0].label = 'Alpha <script>alert(1)</script>'; show(); await open(); await search()
  expect(await screen.findByText('Alpha <script>alert(1)</script>')).toBeVisible(); expect(document.querySelector('section script')).toBeNull()
  const [path, options] = fake.request.mock.calls.find(([path]) => path.includes('q=Al'))!
  expect(path).toContain('type=people'); expect(path).toContain('per_page=10'); expect(options.revision).toBe(active.revision)
})
it('debounces suggestions and lets keyboard users select a suggestion', async () => {
  show(); await open(); const input = screen.getByLabelText('Texte à rechercher'); await userEvent.click(input); await userEvent.type(input, 'Al')
  expect(fake.request.mock.calls.filter(([path]) => path.includes('mode=suggest'))).toHaveLength(0)
  const suggestion = await screen.findByRole('button', { name: 'Alpha player' })
  expect(fake.request.mock.calls.filter(([path]) => path.includes('mode=suggest'))).toHaveLength(1)
  suggestion.focus(); await userEvent.keyboard('{Enter}')
  await screen.findByRole('region', { name: 'Résultats de recherche' }); expect(fake.request.mock.calls.some(([path]) => path.includes('q=Alpha+player') && !path.includes('mode=suggest'))).toBe(true)
})
it('does not claim no results when an empty scan segment can be continued', async () => {
  response.items = []; response.meta = { ...response.meta, examined: 100, has_more: true, next_cursor: 'opaque-cursor' }
  show(); await open(); await search(); await screen.findByText('Aucun résultat dans cette partie des fiches. Vous pouvez poursuivre la recherche.')
  expect(screen.queryByText('Aucun résultat accessible ne correspond à cette recherche.')).toBeNull()
  response.items = [{ id: 'record-2', label: 'Alpha next', reference: null, parent_id: null }]; response.meta.has_more = false; response.meta.next_cursor = null
  await userEvent.click(screen.getByRole('button', { name: 'Continuer la recherche' })); await screen.findByText('Alpha next')
  expect(fake.request.mock.calls.some(([path]) => path.includes('cursor=opaque-cursor'))).toBe(true)
  expect(screen.getByRole('button', { name: 'Partie précédente' })).toBeEnabled()
})
it('clears previous results immediately after a query or category change', async () => {
  show(); await open(); await search(); await screen.findByText('Alpha player')
  fireEvent.change(screen.getByLabelText('Texte à rechercher'), { target: { value: 'Other' } }); expect(screen.queryByRole('region', { name: 'Résultats de recherche' })).toBeNull()
  await search(); await screen.findByText('Alpha player'); await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Catégorie' }), 'teams')
  expect(screen.queryByRole('region', { name: 'Résultats de recherche' })).toBeNull()
})
it('discards old tenant responses and removes all data when permissions are revoked', async () => {
  const view = show(); await open(); await search(); await screen.findByText('Alpha player')
  view.rerender(<TenantSearchPanel active={{ ...active, revision: 'revision-2' }} disabled={false} onInvalid={fake.invalid} />)
  expect(screen.queryByText('Alpha player')).toBeNull(); expect(screen.getByRole('button', { name: 'Ouvrir la recherche' })).toBeVisible()
  view.rerender(<TenantSearchPanel active={{ ...active, context: { ...active.context!, permissions: [] } }} disabled={false} onInvalid={fake.invalid} />)
  expect(screen.queryByRole('region', { name: 'Rechercher dans l’organisation' })).toBeNull()
})
it('ignores a late suggestion response after another term is entered', async () => {
  let finish: ((value: unknown) => void) | undefined
  fake.request.mockImplementation(async (path: string) => path.endsWith('/types') ? { data: { types } } : new Promise((resolve) => { finish = resolve }))
  show(); await open(); const input = screen.getByLabelText('Texte à rechercher'); await userEvent.click(input); fireEvent.change(input, { target: { value: 'Al' } })
  await waitFor(() => expect(finish).toBeDefined()); fireEvent.change(input, { target: { value: 'Other' } })
  await act(async () => { finish!({ data: response }) }); expect(screen.queryByRole('button', { name: 'Alpha player' })).toBeNull()
  expect(fake.request.mock.calls.find(([path]) => path.includes('q=Al'))![1].signal.aborted).toBe(true)
})
it('shows localized failures and reloads context on authorization revocation', async () => {
  show(); await open(); failure = new ApiClientError('Accès révoqué.', { status: 403 }); await search()
  await screen.findByText('Accès révoqué.'); await waitFor(() => expect(fake.invalid).toHaveBeenCalledTimes(1)); expect(screen.queryByText('Alpha player')).toBeNull()
})
it('shows terminal empty results and retries after a read failure', async () => {
  show(); await open(); failure = new ApiClientError('Recherche indisponible.', { status: 409 }); await search(); await screen.findByText('Recherche indisponible.')
  failure = null; response.items = []; await userEvent.click(screen.getByRole('button', { name: 'Réessayer' })); await screen.findByText('Aucun résultat accessible ne correspond à cette recherche.')
})
