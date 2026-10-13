import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { tenancyRequest, type ActiveOrganization } from '../../api/tenancy'
import { ApiClientError } from '../../api/errors'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { OrganizationGamesPanel } from './OrganizationGamesPanel'
import type { OrganizationGameDetail } from '../../api/gameCatalogue'
vi.mock('../../api/tenancy', () => ({ tenancyRequest: vi.fn() }))
const request = vi.mocked(tenancyRequest)
const org = '11111111-1111-4111-8111-111111111111', revision = '22222222-2222-4222-8222-222222222222', id = '33333333-3333-4333-8333-333333333333'
const active: ActiveOrganization = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'],
  permissions: ['sports.read', 'games.manage'], is_owner: false, actor_user_id: 42, membership_id: 'member' }, revision, confirmed: false }
const game: OrganizationGameDetail = { id, key: 'valorant', name: 'VALORANT', available: true, enabled: true, configured_enabled: true,
  restrictions: {}, version: null, catalogue_version: revision,
  options: { platform: [{ key: 'pc', name: 'PC' }], provider: [{ key: 'riot', name: 'Riot Games' }], region: [{ key: 'eu', name: 'Europe' }], mode: [], role: [], map: [], release: [] },
  effective_options: { platform: [], provider: [], region: [], mode: [], role: [], map: [], release: [] } }
const invalid = vi.fn()
function show(selection = active) { return render(<NotificationProvider><OrganizationGamesPanel active={selection} disabled={false} onInvalid={invalid} /></NotificationProvider>) }
beforeEach(() => {
  vi.resetAllMocks()
  request.mockImplementation(async (path, options) => {
    if (path === 'active-organization/confirmation') return { data: { ...active, confirmed: true } }
    if (path.endsWith('/' + id) || options?.method) return { data: game, meta: { request_id: 'detail' } }
    return { data: [game], meta: { request_id: 'list', current_page: 1, last_page: 1, total: 1, catalogue_version: revision } }
  })
})
describe('choix de jeux par organisation', () => {
  it('présente explicitement la désactivation locale lorsque le jeu commun est retiré', async () => {
    request.mockImplementation(async (path) => path.endsWith('/' + id)
      ? { data: { ...game, available: false, enabled: false }, meta: { request_id: 'retired' } }
      : { data: [{ ...game, available: false, enabled: false }], meta: { current_page: 1, last_page: 1, total: 1 } })
    const user = userEvent.setup(); show()
    await user.click(screen.getByRole('button', { name: 'Jeux de l’organisation' }))
    await user.click(await screen.findByRole('button', { name: 'Modifier' }))
    const form = await screen.findByRole('form', { name: 'Enregistrer les choix du jeu' })
    expect(within(form).getByLabelText('Activer ce jeu pour cette organisation')).toHaveValue('no')
    expect(within(form).getByRole('option', { name: 'Activé' })).toBeDisabled()
    expect(within(form).getByText(/Enregistrer ici le désactive aussi localement/)).toBeVisible()
  })
  it('ne donne aucun panneau au propriétaire sans permission sportive', () => {
    show({ ...active, context: { ...active.context!, permissions: ['organization.view'], is_owner: true } })
    expect(screen.queryByRole('button', { name: 'Jeux de l’organisation' })).not.toBeInTheDocument()
    expect(request).not.toHaveBeenCalled()
  })
  it('ne permet aucune écriture au lecteur sportif', async () => {
    const user = userEvent.setup(); show({ ...active, context: { ...active.context!, permissions: ['sports.read'] } })
    await user.click(screen.getByRole('button', { name: 'Jeux de l’organisation' }))
    await user.click(await screen.findByRole('button', { name: 'Disponible' }))
    expect(await screen.findByRole('button', { name: 'Enregistrer les choix du jeu' })).toBeDisabled()
    expect(screen.queryByRole('button', { name: 'Modifier' })).not.toBeInTheDocument()
  })
  it('confirme le tenant et distingue une liste vide de l’héritage', async () => {
    const user = userEvent.setup(); show()
    await user.click(screen.getByRole('button', { name: 'Jeux de l’organisation' }))
    await user.click(await screen.findByRole('button', { name: 'Modifier' }))
    const form = await screen.findByRole('form', { name: 'Enregistrer les choix du jeu' })
    const platforms = within(form).getByRole('group', { name: 'Plateformes' })
    await user.click(within(platforms).getByLabelText('Utiliser tous les choix disponibles'))
    await user.selectOptions(within(form).getByLabelText('Activer ce jeu pour cette organisation'), 'no')
    await user.click(within(form).getByRole('button', { name: 'Enregistrer les choix du jeu' }))
    const confirmation = screen.getByRole('region', { name: 'Confirmer l’action' })
    expect(confirmation).toHaveFocus(); expect(confirmation).toHaveTextContent('Alpha')
    expect(request.mock.calls.some(([, options]) => options?.method)).toBe(false)
    await user.click(within(confirmation).getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(invalid).toHaveBeenCalledTimes(1))
    expect(request).toHaveBeenCalledWith('active-organization/confirmation', expect.objectContaining({ body: { organization_id: org, revision, confirm: true } }))
    expect(request).toHaveBeenCalledWith('tenants/' + org + '/games/' + id, expect.objectContaining({
      method: 'PATCH', revision, body: { enabled: false, restrictions: { platform: [] }, version: null, catalogue_version: revision },
    }))
    expect(request.mock.calls.some(([path]) => path === 'game-catalogue')).toBe(false)
  })
  it('recharge le contexte après conflit sans rejouer l’écriture', async () => {
    const user = userEvent.setup(); show({ ...active, confirmed: true })
    await user.click(screen.getByRole('button', { name: 'Jeux de l’organisation' }))
    await user.click(await screen.findByRole('button', { name: 'Modifier' }))
    const form = await screen.findByRole('form', { name: 'Enregistrer les choix du jeu' })
    request.mockImplementationOnce(async () => { throw new ApiClientError('Catalogue modifié.', { status: 409, code: 'GAME_CATALOGUE_VERSION_STALE', requestId: 'stale' }) })
    await user.click(within(form).getByRole('button', { name: 'Enregistrer les choix du jeu' }))
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(invalid).toHaveBeenCalledTimes(1))
    expect(within(screen.getByRole('region', { name: 'Jeux de l’organisation' })).getByRole('alert')).toHaveTextContent('Catalogue modifié.')
    expect(request.mock.calls.filter(([, options]) => options?.method === 'PATCH')).toHaveLength(1)
  })
  it('ignore une réponse retardée après changement d’organisation', async () => {
    const user = userEvent.setup()
    let resolveRead: ((result: unknown) => void) | undefined
    request.mockImplementationOnce(() => new Promise((resolve) => { resolveRead = resolve }))
    const view = show()
    await user.click(screen.getByRole('button', { name: 'Jeux de l’organisation' }))
    const options = request.mock.calls[0][1]
    view.rerender(<NotificationProvider><OrganizationGamesPanel key="other" active={{ ...active, context: { ...active.context!, organization_id: 'other', name: 'Beta' } }} disabled={false} onInvalid={invalid} /></NotificationProvider>)
    expect(options?.signal?.aborted).toBe(true)
    resolveRead?.({ data: [game], meta: { current_page: 1, last_page: 1, total: 1 } })
    await waitFor(() => expect(screen.queryByText('VALORANT')).not.toBeInTheDocument())
  })
})
