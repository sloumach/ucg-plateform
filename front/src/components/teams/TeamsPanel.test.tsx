import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { tenancyRequest, type ActiveOrganization } from '../../api/tenancy'
import { ApiClientError } from '../../api/errors'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { TeamsPanel } from './TeamsPanel'
import type { Team } from '../../api/teams'

vi.mock('../../api/tenancy', () => ({ tenancyRequest: vi.fn() }))
const request = vi.mocked(tenancyRequest)
const org = '11111111-1111-4111-8111-111111111111', revision = '22222222-2222-4222-8222-222222222222', id = '33333333-3333-4333-8333-333333333333'
const active: ActiveOrganization = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr',
  roles: ['member'], permissions: ['sports.read', 'teams.manage'], is_owner: false, actor_user_id: 42, membership_id: 'member' }, revision, confirmed: false }
const team: Team = { id, game_id: id, game_key: 'valorant', game_name: 'VALORANT', game_available: true, slug: 'premiere-2026', season: '2026',
  name: 'Première équipe', kind: 'first', custom_category: null, short_name: 'UCG', public_description: null, color: null, status: 'active', version: revision, publication_status: 'private' }
const invalid = vi.fn()
let rows: Team[] = []
function show(selection = active) { return render(<NotificationProvider><TeamsPanel active={selection} disabled={false} onInvalid={invalid} /></NotificationProvider>) }
beforeEach(() => {
  vi.resetAllMocks(); rows = []
  request.mockImplementation(async (path, options) => {
    if (path === 'active-organization/confirmation') return { data: { ...active, confirmed: true } }
    if (options?.method) return { data: team, meta: { request_id: 'saved' } }
    if (path.includes('/games?')) return { data: [{ id, name: 'VALORANT', enabled: true }, { id: 'disabled', name: 'Jeu désactivé', enabled: false }] }
    return { data: rows, meta: { current_page: 1, last_page: 1, total: rows.length, request_id: 'list' } }
  })
})
async function openEditor(user: ReturnType<typeof userEvent.setup>) {
  await user.click(screen.getByRole('button', { name: 'Équipes de l’organisation' }))
  await user.click(await screen.findByRole('button', { name: 'Créer une équipe' }))
  return screen.getByRole('form', { name: 'Enregistrer l’équipe' })
}
describe('équipes tenant', () => {
  it('n’expose aucune équipe au propriétaire sans droits sportifs', () => {
    show({ ...active, context: { ...active.context!, permissions: ['organization.view'], is_owner: true } })
    expect(screen.queryByRole('button', { name: 'Équipes de l’organisation' })).not.toBeInTheDocument()
    expect(request).not.toHaveBeenCalled()
  })
  it('le lecteur consulte la liste sans commandes de gestion ni rendu HTML non échappé', async () => {
    rows = [{ ...team, name: '<script>BAD</script>', public_description: '<img onerror=BAD>' }]
    const user = userEvent.setup(); show({ ...active, context: { ...active.context!, permissions: ['sports.read'] } })
    await user.click(screen.getByRole('button', { name: 'Équipes de l’organisation' }))
    expect(await screen.findByText('<script>BAD</script>')).toBeVisible()
    expect(screen.getByText('<img onerror=BAD>')).toBeVisible()
    expect(document.querySelector('script')).toBeNull()
    expect(screen.queryByRole('button', { name: 'Créer une équipe' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Archiver l’équipe' })).not.toBeInTheDocument()
  })
  it('crée après confirmation Alpha avec uniquement un jeu activé', async () => {
    const user = userEvent.setup(); show()
    const form = await openEditor(user)
    expect(within(form).queryByRole('option', { name: 'Jeu désactivé' })).not.toBeInTheDocument()
    await user.selectOptions(within(form).getByLabelText('Jeu activé'), id)
    await user.type(within(form).getByLabelText('Slug stable'), 'pilote-2026')
    await user.type(within(form).getByLabelText('Saison'), '2026')
    await user.type(within(form).getByLabelText('Nom de l’équipe'), 'Pilote')
    await user.click(within(form).getByRole('button', { name: 'Enregistrer l’équipe' }))
    expect(request.mock.calls.some(([, options]) => options?.method)).toBe(false)
    const confirmation = screen.getByRole('region', { name: 'Confirmer l’action' })
    expect(confirmation).toHaveFocus(); expect(confirmation).toHaveTextContent('Alpha')
    await user.click(within(confirmation).getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/' + org + '/teams', expect.objectContaining({
      method: 'POST', revision, body: { game_id: id, slug: 'pilote-2026', season: '2026', name: 'Pilote', kind: 'first',
        custom_category: null, short_name: null, color: null, public_description: null, status: 'draft' },
    })))
    expect(request).toHaveBeenCalledWith('active-organization/confirmation', expect.objectContaining({ body: { organization_id: org, revision, confirm: true } }))
  })
  it('envoie un patch partiel sans jeu slug ou saison', async () => {
    rows = [team]
    const user = userEvent.setup(); show({ ...active, confirmed: true })
    await user.click(screen.getByRole('button', { name: 'Équipes de l’organisation' }))
    await user.click(await screen.findByRole('button', { name: 'Modifier l’équipe' }))
    const form = screen.getByRole('form', { name: 'Enregistrer l’équipe' })
    expect(within(form).queryByLabelText('Slug stable')).not.toBeInTheDocument()
    const input = within(form).getByLabelText('Nom de l’équipe')
    await user.clear(input); await user.type(input, 'Nouveau nom')
    await user.click(within(form).getByRole('button', { name: 'Enregistrer l’équipe' }))
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/' + org + '/teams/' + id, expect.objectContaining({
      method: 'PATCH', revision, body: { name: 'Nouveau nom', version: revision },
    })))
  })
  it('une archive demeure visible et n’a plus de commandes de modification', async () => {
    rows = [{ ...team, status: 'archived' }]
    const user = userEvent.setup(); show()
    await user.click(screen.getByRole('button', { name: 'Équipes de l’organisation' }))
    await screen.findByRole('heading', { name: 'Première équipe' })
    expect(screen.queryByRole('button', { name: 'Modifier l’équipe' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Archiver l’équipe' })).not.toBeInTheDocument()
  })
  it('l’archivage confirme uniquement le statut et la version', async () => {
    rows = [team]
    const user = userEvent.setup(); show({ ...active, confirmed: true })
    await user.click(screen.getByRole('button', { name: 'Équipes de l’organisation' }))
    await user.click(await screen.findByRole('button', { name: 'Archiver l’équipe' }))
    expect(request.mock.calls.some(([, options]) => options?.method)).toBe(false)
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/' + org + '/teams/' + id, expect.objectContaining({
      method: 'PATCH', body: { version: revision, status: 'archived' },
    })))
  })
  it('conserve le brouillon après 422 et recharge le contexte après conflit', async () => {
    rows = [team]
    const user = userEvent.setup(); show({ ...active, confirmed: true })
    await user.click(screen.getByRole('button', { name: 'Équipes de l’organisation' }))
    await user.click(await screen.findByRole('button', { name: 'Modifier l’équipe' }))
    const form = screen.getByRole('form', { name: 'Enregistrer l’équipe' })
    await user.clear(within(form).getByLabelText('Nom de l’équipe')); await user.type(within(form).getByLabelText('Nom de l’équipe'), 'Conservé')
    request.mockImplementationOnce(async () => { throw new ApiClientError('Invalide', { status: 422, fieldErrors: { name: ['Nom refusé.'] } }) })
    await user.click(within(form).getByRole('button', { name: 'Enregistrer l’équipe' })); await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    expect(await screen.findByText('Nom refusé.')).toBeVisible()
    expect(within(form).getByLabelText('Nom de l’équipe')).toHaveValue('Conservé')
    request.mockImplementationOnce(async () => { throw new ApiClientError('Version obsolète', { status: 409, code: 'TEAM_VERSION_STALE' }) })
    await user.click(within(form).getByRole('button', { name: 'Enregistrer l’équipe' })); await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(invalid).toHaveBeenCalledTimes(1))
  })
  it('sortir de la catégorie spécifique efface son ancien libellé explicitement', async () => {
    rows = [{ ...team, kind: 'custom', custom_category: 'Mixte' }]
    const user = userEvent.setup(); show({ ...active, confirmed: true })
    await user.click(screen.getByRole('button', { name: 'Équipes de l’organisation' }))
    await user.click(await screen.findByRole('button', { name: 'Modifier l’équipe' }))
    const form = screen.getByRole('form', { name: 'Enregistrer l’équipe' })
    await user.selectOptions(within(form).getByLabelText('Type d’équipe'), 'reserve')
    await user.click(within(form).getByRole('button', { name: 'Enregistrer l’équipe' })); await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/' + org + '/teams/' + id, expect.objectContaining({
      body: { kind: 'reserve', custom_category: null, version: revision },
    })))
  })
  it('ignore une liste retardée après changement de tenant', async () => {
    const user = userEvent.setup(); let complete: ((value: unknown) => void) | undefined
    request.mockImplementationOnce(() => new Promise((resolve) => { complete = resolve }))
    const view = show(); await user.click(screen.getByRole('button', { name: 'Équipes de l’organisation' }))
    const signal = request.mock.calls[0][1]?.signal
    view.rerender(<NotificationProvider><TeamsPanel key="other" active={{ ...active, context: { ...active.context!, organization_id: 'other' } }} disabled={false} onInvalid={invalid} /></NotificationProvider>)
    expect(signal?.aborted).toBe(true)
    complete?.({ data: [team], meta: { current_page: 1, last_page: 1, total: 1 } })
    await waitFor(() => expect(screen.queryByText('Première équipe')).not.toBeInTheDocument())
  })
})
