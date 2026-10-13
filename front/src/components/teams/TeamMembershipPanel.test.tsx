import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { tenancyRequest, type ActiveOrganization } from '../../api/tenancy'
import { ApiClientError } from '../../api/errors'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { TeamMembershipPanel } from './TeamMembershipPanel'
import type { Team } from '../../api/teams'
import type { TeamMembership } from '../../api/teamMemberships'
vi.mock('../../api/tenancy', () => ({ tenancyRequest: vi.fn() }))
const request = vi.mocked(tenancyRequest)
const org = '11111111-1111-4111-8111-111111111111', revision = '22222222-2222-4222-8222-222222222222', id = '33333333-3333-4333-8333-333333333333'
const destinationId = '44444444-4444-4444-8444-444444444444'
const active: ActiveOrganization = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr',
  roles: ['member'], permissions: ['sports.read', 'teams.manage'], is_owner: false, actor_user_id: 42, membership_id: 'member' }, revision, confirmed: false }
const team: Team = { id, game_id: id, game_key: 'valorant', game_name: 'VALORANT', game_available: true, slug: 'academy-2026', season: '2026',
  name: 'Académie', kind: 'academy', custom_category: null, short_name: null, public_description: null, color: null, status: 'active', version: revision, publication_status: 'private' }
const period: TeamMembership = { id, team_id: id, person_id: id, pseudonym: '<script>PRIVATE_BAD</script>', role_key: 'player', starts_at: '2020-01-01T00:00:00+00:00',
  ends_at: null, status: 'active', effective: true, version: revision }
const invalid = vi.fn(), close = vi.fn()
let rows: TeamMembership[] = []
function page<T>(data: T[]) { return { data, meta: { current_page: 1, last_page: 1, total: data.length, request_id: 'roster' } } }
function show(selection = active, selectedTeam = team) { return render(<NotificationProvider><TeamMembershipPanel active={selection} team={selectedTeam} disabled={false} onInvalid={invalid} onClose={close} /></NotificationProvider>) }
beforeEach(() => {
  vi.resetAllMocks(); rows = []
  request.mockImplementation(async (path, options) => {
    if (path === 'active-organization/confirmation') return { data: { ...active, confirmed: true } }
    if (options?.method) return { data: period, notification: { type: 'success', message: 'Appartenance enregistrée.' } }
    if (path.includes('/people?')) return page([{ id, pseudonym: 'Camille', status: 'active', version: revision }, { id: 'former', pseudonym: 'Ancien', status: 'former' }])
    if (path.includes('/teams?')) return page([{ ...team, id: destinationId, name: 'Première', kind: 'first' }, { ...team, id: 'archive', name: 'Archive', status: 'archived' }])
    if (path.includes('/movements?')) return page([])
    return page(rows)
  })
})
describe('appartenances d’équipe', () => {
  it('n’effectue aucun accès sans permission sportive', () => {
    show({ ...active, context: { ...active.context!, permissions: ['organization.view'] } })
    expect(request).not.toHaveBeenCalled(); expect(screen.queryByRole('button', { name: 'Ajouter une appartenance' })).not.toBeInTheDocument()
  })
  it('affiche les périodes avec texte échappé et sans commandes pour un lecteur', async () => {
    rows = [period]; show({ ...active, context: { ...active.context!, permissions: ['sports.read'] } })
    expect(await screen.findByRole('heading', { name: '<script>PRIVATE_BAD</script> · Titulaire' })).toBeInTheDocument()
    expect(document.querySelector('script')).toBeNull(); expect(screen.queryByRole('button', { name: 'Changer d’équipe' })).not.toBeInTheDocument()
    expect(screen.getByText('Une fonction sportive ne donne aucun droit automatiquement. Les permissions d’équipe restent attribuées séparément et liées à une période.')).toBeInTheDocument()
  })
  it('crée avec une personne choisie et confirmation humaine avant toute écriture', async () => {
    const user = userEvent.setup(); show()
    await user.click(await screen.findByRole('button', { name: 'Ajouter une appartenance' }))
    const form = screen.getByRole('form', { name: 'Enregistrer l’appartenance' })
    await user.selectOptions(await within(form).findByLabelText('Personne de l’organisation'), id)
    expect(within(form).queryByRole('option', { name: 'Ancien' })).not.toBeInTheDocument()
    await user.clear(within(form).getByLabelText('Début de la période')); await user.type(within(form).getByLabelText('Début de la période'), '2026-10-13T12:00:00')
    await user.click(within(form).getByRole('button', { name: 'Enregistrer l’appartenance' }))
    expect(request.mock.calls.filter(([, options]) => options?.method)).toHaveLength(0)
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith(expect.stringContaining('/memberships'), expect.objectContaining({ method: 'POST', revision, body: expect.objectContaining({ person_id: id, role_key: 'player', reason_key: 'initial', ends_at: null }) })))
    expect(request).toHaveBeenCalledWith('active-organization/confirmation', expect.objectContaining({ body: { organization_id: org, revision, confirm: true } }))
  })
  it('propose une promotion avec version destination et sans date pour un effet immédiat', async () => {
    rows = [period]; const user = userEvent.setup(); show()
    await user.click(await screen.findByRole('button', { name: 'Promouvoir en équipe première' }))
    const form = screen.getByRole('form', { name: 'Enregistrer l’appartenance' })
    await user.selectOptions(await within(form).findByLabelText('Équipe de destination'), destinationId)
    await user.click(within(form).getByRole('button', { name: 'Enregistrer l’appartenance' })); await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith(expect.stringContaining('/memberships/' + id), expect.objectContaining({ method: 'PATCH', body: { version: revision, action: 'promotion', destination_team_id: destinationId, destination_version: revision, role_key: 'player', reason_key: 'promotion' } })))
    expect(request).toHaveBeenCalledWith(expect.stringContaining('kind=first'), expect.anything())
  })
  it('annule une période future sans transmettre de date client ou réaffecter son identité', async () => {
    rows = [{ ...period, starts_at: '2099-01-01T00:00:00+00:00', effective: false }]; const user = userEvent.setup(); show()
    await user.click(await screen.findByRole('button', { name: 'Annuler la période future' }))
    await user.click(screen.getByRole('button', { name: 'Enregistrer l’appartenance' })); await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith(expect.stringContaining('/memberships/' + id), expect.objectContaining({ body: { version: revision, action: 'cancel', reason_key: 'other' } })))
  })
  it('conserve la saisie après 422 et recharge le contexte après 409', async () => {
    rows = [period]; const original = request.getMockImplementation()!; request.mockImplementation(async (path, options) => {
      if (options?.method === 'PATCH') throw new ApiClientError('Conflit de période', { status: 422, fieldErrors: { effective_at: ['Date invalide.'] } })
      return original(path, options)
    })
    const user = userEvent.setup(); show(); await user.click(await screen.findByRole('button', { name: 'Clôturer la période' }))
    await user.click(screen.getByRole('button', { name: 'Enregistrer l’appartenance' })); await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    expect(await screen.findByText('Date invalide.')).toBeInTheDocument(); expect(screen.getByRole('form', { name: 'Enregistrer l’appartenance' })).toBeInTheDocument(); expect(invalid).not.toHaveBeenCalled()
    request.mockImplementation(async (path, options) => { if (options?.method === 'PATCH') throw new ApiClientError('Version périmée', { status: 409 }); return original(path, options) })
    await user.click(screen.getByRole('button', { name: 'Enregistrer l’appartenance' })); await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(invalid).toHaveBeenCalledOnce())
  })
  it('garde les archives et périodes terminales lisibles sans commandes', async () => {
    rows = [{ ...period, status: 'closed', ends_at: '2021-01-01T00:00:00+00:00', effective: false }]; show(active, { ...team, status: 'archived' })
    expect(await screen.findByText('Clôturée · Non effective à la date consultée')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Ajouter une appartenance' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Clôturer la période' })).not.toBeInTheDocument()
  })
  it('filtre l’effectif à une date avec fuseau au lieu de transmettre une date locale brute', async () => {
    const user = userEvent.setup(); show(); await screen.findByText('Aucune appartenance pour ces critères.')
    const form = screen.getByRole('form', { name: 'Filtrer les appartenances' })
    await user.type(within(form).getByLabelText('Effectif à la date'), '2026-10-13T12:00:00'); await user.click(within(form).getByRole('button', { name: 'Filtrer les appartenances' }))
    await waitFor(() => expect(request.mock.calls.some(([path]) => path.includes('at=') && decodeURIComponent(path).includes('+00:00'))).toBe(true))
  })
  it('ignore une lecture retardée après fermeture du panneau', async () => {
    let resolve!: (value: unknown) => void
    request.mockImplementation(() => new Promise((done) => { resolve = done }))
    const view = show(); view.unmount(); resolve(page([period])); await Promise.resolve()
    expect(screen.queryByRole('heading', { name: '<script>PRIVATE_BAD</script> · Titulaire' })).not.toBeInTheDocument()
  })
})
