import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { tenancyRequest, type ActiveOrganization } from '../../api/tenancy'
import { ApiClientError } from '../../api/errors'
import type { Team } from '../../api/teams'
import type { TeamMembership } from '../../api/teamMemberships'
import type { TeamLineup } from '../../api/teamLineups'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { TeamLineupPanel } from './TeamLineupPanel'

vi.mock('../../api/tenancy', () => ({ tenancyRequest: vi.fn() }))
const request = vi.mocked(tenancyRequest)
const org = '11111111-1111-4111-8111-111111111111', revision = '22222222-2222-4222-8222-222222222222', id = '33333333-3333-4333-8333-333333333333'
const secondId = '44444444-4444-4444-8444-444444444444'
const active: ActiveOrganization = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'], permissions: ['sports.read', 'teams.manage'], is_owner: false, actor_user_id: 42, membership_id: 'member' }, revision, confirmed: false }
const team: Team = { id, game_id: id, game_key: 'valorant', game_name: 'VALORANT', game_available: true, slug: 'pilote', season: '2026', name: 'Équipe pilote', kind: 'first', custom_category: null, short_name: null, public_description: null, color: null, status: 'active', version: revision, publication_status: 'private' }
const period: TeamMembership = { id, team_id: id, person_id: id, pseudonym: 'Camille', role_key: 'player', starts_at: '2020-01-01T00:00:00+00:00', ends_at: null, status: 'active', effective: true, version: revision }
const draft: TeamLineup = { id, team_id: id, game_id: id, purpose: 'match', label: 'Scrim pilote', effective_at: '2026-10-13T11:00:00+00:00', status: 'draft', version: revision, team_name: team.name, game_name: 'VALORANT', game_key: 'valorant', season: '2026', created_by: 42, finalized_by: null, finalized_at: null,
  entries: [{ id, membership_id: id, membership_version: revision, person_id: id, pseudonym: '<script>SNAPSHOT_BAD</script>', membership_role_key: 'player', slot_key: 'starter', position: 1 }] }
const invalid = vi.fn(), close = vi.fn()
let rows: TeamLineup[] = [], periods: TeamMembership[] = []
function page<T>(data: T[]) { return { data, meta: { current_page: 1, last_page: 1, total: data.length, request_id: 'lineups' } } }
function show(selection = active, selectedTeam = team) { return render(<NotificationProvider><TeamLineupPanel active={selection} team={selectedTeam} disabled={false} onInvalid={invalid} onClose={close} /></NotificationProvider>) }
beforeEach(() => {
  vi.resetAllMocks(); rows = []; periods = [period]
  request.mockImplementation(async (path, options) => {
    if (path === 'active-organization/confirmation') return { data: { ...active, confirmed: true } }
    if (options?.method) return { data: draft, notification: { type: 'success', message: 'Composition enregistrée.' } }
    if (path.includes('/memberships?')) return page(periods)
    return page(rows)
  })
})
describe('compositions historiques', () => {
  it('ne charge rien sans lecture sportive', () => {
    show({ ...active, context: { ...active.context!, permissions: ['organization.view'] } })
    expect(request).not.toHaveBeenCalled(); expect(screen.queryByRole('region')).not.toBeInTheDocument()
  })
  it('affiche uniquement le snapshot échappé et garde une composition figée sans commandes', async () => {
    rows = [{ ...draft, status: 'finalized', finalized_at: draft.effective_at, finalized_by: 42 }]
    show()
    expect(await screen.findByText('1. <script>SNAPSHOT_BAD</script> · Titulaire')).toBeInTheDocument()
    expect(document.querySelector('script')).toBeNull()
    expect(screen.queryByRole('button', { name: 'Modifier le brouillon' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Figer la composition' })).not.toBeInTheDocument()
    expect(request.mock.calls.every(([path]) => !path.includes('/people'))).toBe(true)
  })
  it('prépare des titulaires et remplaçants avec versions et confirmation avant toute écriture', async () => {
    periods = [period, { ...period, id: secondId, person_id: secondId, pseudonym: 'Noé', role_key: 'substitute' }, { ...period, id: 'coach', pseudonym: 'Coach', role_key: 'coach' }]
    const user = userEvent.setup(); show()
    await user.click(await screen.findByRole('button', { name: 'Préparer une composition' }))
    await user.click(await screen.findByRole('checkbox', { name: 'Retenir Camille' })); await user.click(screen.getByRole('checkbox', { name: 'Retenir Noé' }))
    expect(screen.queryByRole('checkbox', { name: 'Retenir Coach' })).not.toBeInTheDocument()
    await user.selectOptions(screen.getByLabelText('Place dans la composition Noé'), 'substitute')
    const form = screen.getByRole('form', { name: 'Enregistrer le brouillon' })
    await user.type(within(form).getByLabelText('Libellé du match ou de l’inscription'), 'Inscription pilote')
    await user.selectOptions(within(form).getByLabelText('Usage de la composition'), 'registration')
    await user.click(within(form).getByRole('button', { name: 'Enregistrer le brouillon' }))
    expect(request.mock.calls.filter(([, options]) => options?.method)).toHaveLength(0)
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith(expect.stringContaining('/lineups'), expect.objectContaining({ method: 'POST', revision, body: expect.objectContaining({ purpose: 'registration', label: 'Inscription pilote', team_version: revision,
      entries: [{ membership_id: id, membership_version: revision, slot_key: 'starter' }, { membership_id: secondId, membership_version: revision, slot_key: 'substitute' }] }) })))
    expect(request).toHaveBeenCalledWith('active-organization/confirmation', expect.objectContaining({ body: { organization_id: org, revision, confirm: true } }))
  })
  it('fige après confirmation avec la version du brouillon et de l’équipe', async () => {
    rows = [draft]; const user = userEvent.setup(); show()
    await user.click(await screen.findByRole('button', { name: 'Figer la composition' }))
    expect(request.mock.calls.filter(([, options]) => options?.method)).toHaveLength(0)
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith(expect.stringContaining('/lineups/' + id), expect.objectContaining({ method: 'PATCH', body: { version: revision, action: 'finalize', team_version: revision } })))
  })
  it('une équipe archivée permet seulement l’annulation de ses brouillons', async () => {
    rows = [draft]; const user = userEvent.setup(); show(active, { ...team, status: 'archived' })
    await user.click(await screen.findByRole('button', { name: 'Annuler le brouillon' })); await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith(expect.stringContaining('/lineups/' + id), expect.objectContaining({ body: { action: 'cancel', version: revision } })))
    expect(screen.queryByRole('button', { name: 'Préparer une composition' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Figer la composition' })).not.toBeInTheDocument()
  })
  it('changer de date efface les participants et recharge l’effectif à cette date', async () => {
    rows = [draft]; const user = userEvent.setup(); show()
    await user.click(await screen.findByRole('button', { name: 'Modifier le brouillon' }))
    expect(screen.getByRole('button', { name: 'Enregistrer le brouillon' })).toBeEnabled()
    await user.clear(screen.getByLabelText('Date de la composition')); await user.type(screen.getByLabelText('Date de la composition'), '2026-10-14T12:00:00')
    expect(screen.getByRole('button', { name: 'Enregistrer le brouillon' })).toBeDisabled()
    await waitFor(() => expect(request.mock.calls.some(([path]) => path.includes('/memberships?') && new URL('http://test/' + path).searchParams.get('at')?.startsWith('2026-10-14'))).toBe(true))
  })
  it('conserve le brouillon sur 422 puis recharge sur conflit sans rejeu automatique', async () => {
    rows = [draft]; const original = request.getMockImplementation()!
    request.mockImplementation(async (path, options) => { if (options?.method === 'PATCH') throw new ApiClientError('Titre invalide', { status: 422, fieldErrors: { label: ['Libellé refusé.'] } }); return original(path, options) })
    const user = userEvent.setup(); show(); await user.click(await screen.findByRole('button', { name: 'Modifier le brouillon' }))
    await user.click(screen.getByRole('button', { name: 'Enregistrer le brouillon' })); await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    expect(await screen.findByText('Libellé refusé.')).toBeInTheDocument(); expect(screen.getByLabelText('Libellé du match ou de l’inscription')).toHaveValue('Scrim pilote'); expect(invalid).not.toHaveBeenCalled()
    request.mockImplementation(async (path, options) => { if (options?.method === 'PATCH') throw new ApiClientError('Effectif modifié', { status: 409, code: 'TEAM_LINEUP_MEMBERSHIP_STALE' }); return original(path, options) })
    await user.click(screen.getByRole('button', { name: 'Enregistrer le brouillon' })); await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(invalid).toHaveBeenCalledTimes(1))
    expect(request.mock.calls.filter(([, options]) => options?.method === 'PATCH')).toHaveLength(2)
  })
  it('un lecteur filtre les compositions sans commande de préparation', async () => {
    const user = userEvent.setup(); show({ ...active, context: { ...active.context!, permissions: ['sports.read'] } })
    await screen.findByText('Aucune composition pour ces filtres.')
    await user.selectOptions(screen.getByLabelText('Statut de la composition'), 'finalized'); await user.click(screen.getByRole('button', { name: 'Filtrer les compositions' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith(expect.stringContaining('status=finalized'), expect.anything()))
    expect(screen.queryByRole('button', { name: 'Préparer une composition' })).not.toBeInTheDocument()
  })
})
