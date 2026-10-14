import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { tenancyRequest, type ActiveOrganization } from '../../api/tenancy'
import { ApiClientError } from '../../api/errors'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { ExternalOpponentsPanel } from './ExternalOpponentsPanel'
import type { ExternalOpponent, OpponentSnapshot } from '../../api/externalOpponents'

vi.mock('../../api/tenancy', () => ({ tenancyRequest: vi.fn() }))
const request = vi.mocked(tenancyRequest)
const org = '11111111-1111-4111-8111-111111111111', revision = '22222222-2222-4222-8222-222222222222', id = '33333333-3333-4333-8333-333333333333'
const active: ActiveOrganization = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr',
  roles: ['member'], permissions: ['sports.read', 'teams.manage'], is_owner: false, actor_user_id: 42, membership_id: 'member' }, revision, confirmed: false }
const opponent: ExternalOpponent = { id, key: 'adverse', kind: 'team', name: 'Adversaire', short_name: null, organization_name: null,
  game_id: null, game_key: null, game_name: null, source_label: 'Liste publique', source_url: 'https://example.test/teams',
  observed_at: '2026-01-01T00:00:00+00:00', status: 'active', version: revision, publication_status: 'private' }
const snapshot: OpponentSnapshot = { id, opponent_id: id, opponent_version: revision, context_label: 'Compétition pilote',
  kind: 'team', name: 'Ancien nom', short_name: null, organization_name: null, game_key: null, game_name: null,
  source_label: 'Liste publique', source_url: 'https://example.test/teams', observed_at: opponent.observed_at,
  captured_at: '2026-01-02T00:00:00+00:00', created_by: 42, publication_status: 'private' }
const invalid = vi.fn()
let rows: ExternalOpponent[] = [], history: OpponentSnapshot[] = []
function show(selection = active) { return render(<NotificationProvider><ExternalOpponentsPanel active={selection} disabled={false} onInvalid={invalid} /></NotificationProvider>) }
beforeEach(() => {
  vi.resetAllMocks(); rows = []; history = []
  request.mockImplementation(async (path, options) => {
    if (path === 'active-organization/confirmation') return { data: { ...active, confirmed: true } }
    if (options?.method) return { data: path.includes('/snapshots') ? snapshot : opponent, meta: { request_id: 'saved' } }
    if (path.includes('/games?')) return { data: [{ id, name: 'VALORANT', enabled: true }, { id: revision, name: 'Jeu désactivé', enabled: false }] }
    const data = path.includes('/snapshots') ? history : rows
    return { data, meta: { current_page: 1, last_page: 1, total: data.length, request_id: 'list' } }
  })
})
const consent = 'Je confirme que ces informations sont publiques et autorisées pour ce suivi.'
async function open(user: ReturnType<typeof userEvent.setup>) {
  await user.click(screen.getByRole('button', { name: 'Adversaires et organisations externes' }))
  await screen.findByRole('button', { name: 'Ajouter une référence externe' })
}
async function approve(user: ReturnType<typeof userEvent.setup>) {
  const confirmation = screen.getByRole('region', { name: 'Confirmer l’action' })
  expect(confirmation).toHaveTextContent('Alpha')
  await user.click(within(confirmation).getByRole('button', { name: 'Confirmer et continuer' }))
}
describe('références externes privées', () => {
  it('ne charge rien sans permission explicite même pour le propriétaire', () => {
    show({ ...active, context: { ...active.context!, permissions: ['organization.view'], is_owner: true } })
    expect(screen.queryByRole('button', { name: 'Adversaires et organisations externes' })).not.toBeInTheDocument()
    expect(request).not.toHaveBeenCalled()
  })
  it('le lecteur voit les informations échappées et les instantanés sans commandes de gestion', async () => {
    rows = [{ ...opponent, name: '<script>BAD</script>', source_label: '<img onerror=BAD>', source_url: 'javascript:BAD' }]
    const user = userEvent.setup(); show({ ...active, context: { ...active.context!, permissions: ['sports.read'] } })
    await user.click(screen.getByRole('button', { name: 'Adversaires et organisations externes' }))
    expect(await screen.findByText('<script>BAD</script>')).toBeVisible()
    expect(screen.getByText(/<img onerror=BAD>/)).toBeVisible()
    expect(screen.getByText('javascript:BAD')).toBeVisible()
    expect(document.querySelector('script, a[href^="javascript:"]')).toBeNull()
    expect(screen.queryByRole('button', { name: 'Ajouter une référence externe' })).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Instantanés conservés' }))
    expect(await screen.findByText('Aucun instantané conservé pour cette référence.')).toBeVisible()
    expect(screen.queryByRole('button', { name: 'Conserver un instantané' })).not.toBeInTheDocument()
  })
  it('crée une référence sans jeu après déclaration publique et confirmation du tenant', async () => {
    const user = userEvent.setup(); show(); await open(user)
    await user.click(screen.getByRole('button', { name: 'Ajouter une référence externe' }))
    const form = screen.getByRole('form', { name: 'Enregistrer la référence externe' })
    expect(within(form).queryByRole('option', { name: 'Jeu désactivé' })).not.toBeInTheDocument()
    await user.type(within(form).getByLabelText('Clé stable'), 'adverse')
    await user.type(within(form).getByLabelText('Nom public ou pseudonyme'), 'Adversaire')
    await user.type(within(form).getByLabelText('Source des informations'), 'Liste publique')
    await user.type(within(form).getByLabelText('Date d’observation des informations'), '2026-01-01T00:00')
    await user.click(within(form).getByLabelText(consent))
    await user.click(within(form).getByRole('button', { name: 'Enregistrer la référence externe' }))
    expect(request.mock.calls.some(([, options]) => options?.method)).toBe(false)
    await approve(user)
    await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/' + org + '/external-opponents', expect.objectContaining({
      method: 'POST', revision, body: expect.objectContaining({ key: 'adverse', kind: 'team', game_id: null, name: 'Adversaire', confirm_public_data: true }),
    })))
    const body = request.mock.calls.find(([, options]) => options?.method === 'POST' && options?.revision)?.[1]?.body
    expect(body).not.toHaveProperty('organization_id'); expect(body).not.toHaveProperty('user_id')
  })
  it('annuler la confirmation ne déclenche aucune écriture', async () => {
    rows = [opponent]
    const user = userEvent.setup(); show(); await open(user)
    await user.click(screen.getByRole('button', { name: 'Archiver la référence externe' }))
    await user.click(within(screen.getByRole('region', { name: 'Confirmer l’action' })).getByRole('button', { name: 'Annuler' }))
    expect(request.mock.calls.some(([, options]) => options?.method)).toBe(false)
  })
  it('modifie seulement les champs changés sans réenvoyer la date d’observation', async () => {
    rows = [opponent]
    const user = userEvent.setup(); show({ ...active, confirmed: true }); await open(user)
    await user.click(screen.getByRole('button', { name: 'Modifier la référence externe' }))
    const form = screen.getByRole('form', { name: 'Enregistrer la référence externe' })
    expect(within(form).queryByLabelText('Clé stable')).not.toBeInTheDocument()
    await user.clear(within(form).getByLabelText('Nom public ou pseudonyme')); await user.type(within(form).getByLabelText('Nom public ou pseudonyme'), 'Renommée')
    await user.click(within(form).getByLabelText(consent))
    await user.click(within(form).getByRole('button', { name: 'Enregistrer la référence externe' })); await approve(user)
    await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/' + org + '/external-opponents/' + id, expect.objectContaining({
      method: 'PATCH', revision, body: { name: 'Renommée', version: revision, confirm_public_data: true },
    })))
  })
  it('la déclaration seule ne transforme pas un formulaire inchangé en mutation', async () => {
    rows = [opponent]
    const user = userEvent.setup(); show(); await open(user)
    await user.click(screen.getByRole('button', { name: 'Modifier la référence externe' }))
    const form = screen.getByRole('form', { name: 'Enregistrer la référence externe' })
    await user.click(within(form).getByLabelText(consent))
    await user.click(within(form).getByRole('button', { name: 'Enregistrer la référence externe' }))
    expect(screen.queryByRole('region', { name: 'Confirmer l’action' })).not.toBeInTheDocument()
    expect(request.mock.calls.some(([, options]) => options?.method)).toBe(false)
  })
  it('les archives restent consultables avec leur historique sans édition ni nouvelle capture', async () => {
    rows = [{ ...opponent, status: 'archived' }]; history = [snapshot]
    const user = userEvent.setup(); show(); await open(user)
    expect(screen.queryByRole('button', { name: 'Modifier la référence externe' })).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Instantanés conservés' }))
    expect(await screen.findByRole('heading', { name: 'Compétition pilote · Ancien nom' })).toBeVisible()
    expect(screen.queryByRole('button', { name: 'Conserver un instantané' })).not.toBeInTheDocument()
  })
  it('capture avec la version serveur et un contexte nommé sans faits fournis par le client', async () => {
    rows = [opponent]
    const user = userEvent.setup(); show({ ...active, confirmed: true }); await open(user)
    await user.click(screen.getByRole('button', { name: 'Instantanés conservés' }))
    await screen.findByText('Aucun instantané conservé pour cette référence.')
    const form = screen.getByRole('form', { name: 'Conserver un instantané' })
    await user.type(within(form).getByLabelText('Libellé du contexte sportif'), 'Compétition pilote')
    await user.click(within(form).getByLabelText(consent))
    await user.click(within(form).getByRole('button', { name: 'Conserver un instantané' })); await approve(user)
    await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/' + org + '/external-opponents/' + id + '/snapshots', expect.objectContaining({
      method: 'POST', revision, body: { version: revision, context_label: 'Compétition pilote', confirm_public_data: true },
    })))
  })
  it('conserve la saisie après 422 et demande une relecture après 409', async () => {
    rows = [opponent]
    const user = userEvent.setup(); show({ ...active, confirmed: true }); await open(user)
    await user.click(screen.getByRole('button', { name: 'Modifier la référence externe' }))
    const form = screen.getByRole('form', { name: 'Enregistrer la référence externe' })
    await user.clear(within(form).getByLabelText('Nom public ou pseudonyme')); await user.type(within(form).getByLabelText('Nom public ou pseudonyme'), 'Conservé')
    request.mockImplementationOnce(async () => { throw new ApiClientError('Invalide', { status: 422, fieldErrors: { confirm_public_data: ['Confirmez les informations publiques.'] } }) })
    await user.click(within(form).getByRole('button', { name: 'Enregistrer la référence externe' })); await approve(user)
    expect(await screen.findByText('Confirmez les informations publiques.')).toBeVisible()
    expect(within(form).getByLabelText('Nom public ou pseudonyme')).toHaveValue('Conservé')
    expect(within(form).getByLabelText(consent)).toHaveFocus()
    request.mockImplementationOnce(async () => { throw new ApiClientError('Version obsolète', { status: 409, code: 'EXTERNAL_OPPONENT_VERSION_STALE' }) })
    await user.click(within(form).getByRole('button', { name: 'Enregistrer la référence externe' })); await approve(user)
    await waitFor(() => expect(invalid).toHaveBeenCalledTimes(1))
  })
  it('annule les lectures et ignore une liste tardive après changement de tenant', async () => {
    const user = userEvent.setup(); let complete: ((value: unknown) => void) | undefined
    request.mockImplementationOnce(() => new Promise((resolve) => { complete = resolve }))
    const view = show(); await user.click(screen.getByRole('button', { name: 'Adversaires et organisations externes' }))
    const signal = request.mock.calls[0][1]?.signal
    view.rerender(<NotificationProvider><ExternalOpponentsPanel key="other" active={{ ...active, context: { ...active.context!, organization_id: 'other' } }} disabled={false} onInvalid={invalid} /></NotificationProvider>)
    expect(signal?.aborted).toBe(true)
    complete?.({ data: [opponent], meta: { current_page: 1, last_page: 1, total: 1 } })
    await waitFor(() => expect(screen.queryByText('Adversaire', { exact: true })).not.toBeInTheDocument())
  })
  it('annule une lecture d’instantanés à la fermeture', async () => {
    rows = [opponent]
    const user = userEvent.setup(); show(); await open(user)
    let complete: ((value: unknown) => void) | undefined
    request.mockImplementationOnce(() => new Promise((resolve) => { complete = resolve }))
    await user.click(screen.getByRole('button', { name: 'Instantanés conservés' }))
    const signal = request.mock.calls.at(-1)?.[1]?.signal
    await user.click(screen.getByRole('button', { name: 'Fermer les instantanés' }))
    expect(signal?.aborted).toBe(true)
    complete?.({ data: [snapshot], meta: { current_page: 1, last_page: 1, total: 1 } })
    await waitFor(() => expect(screen.queryByText('Compétition pilote · Ancien nom')).not.toBeInTheDocument())
  })
})
