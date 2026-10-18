import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ActiveOrganization } from '../../api/tenancy'
import type { MatchLineupRevision, MatchParticipant, MatchRecord } from '../../api/matches'
import { ApiClientError } from '../../api/errors'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { MatchLineups } from './MatchLineups'

const { request, invalid, saved } = vi.hoisted(() => ({ request: vi.fn(), invalid: vi.fn(), saved: vi.fn() }))
vi.mock('../../api/tenancy', async (original) => ({ ...await original<typeof import('../../api/tenancy')>(), tenancyRequest: request }))
const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222', version = '33333333-3333-4333-8333-333333333333'
const person = '44444444-4444-4444-8444-444444444444', sub = '55555555-5555-4555-8555-555555555555', lineup = '66666666-6666-4666-8666-666666666666'
const active: ActiveOrganization = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'],
  permissions: ['sports.read', 'sports.write'], actor_user_id: 42, is_owner: false, membership_id: id }, revision: version, confirmed: false }
const participant: MatchParticipant = { id, source_id: id, reference_version: version, kind: 'team', slot: 1, label: 'Équipe Alpha' }
const record: MatchRecord = { id, version, title: 'Scrim', kind: 'scrim', format: 'bo1', game_id: id, game_key: 'pilot', game_name: 'Jeu pilote',
  status: 'confirmed', visibility: 'private', starts_at: '2026-10-19T10:00:00Z', ends_at: '2026-10-19T12:00:00Z', origin_timezone: 'UTC',
  starts_at_origin: '2026-10-19T10:00:00+00:00', ends_at_origin: '2026-10-19T12:00:00+00:00', responsible_person_id: person, responsible_label: 'Alex',
  editable: false, available_transitions: ['in_progress'], participants: [participant] }
const revision: MatchLineupRevision = { id, participant_id: id, match_version: version, number: 1, action: 'assign', phase: 'confirmed', actor_user_id: 42,
  occurred_at: '2026-10-18T10:00:00Z', reason: '<script>Motif retenu</script>', snapshot: { id: lineup, label: 'Composition pilote', team_name: 'Alpha', ruleset_id: null, ruleset_version: null, eligibility_report_id: null,
    participants: [{ person_id: person, pseudonym: '<img>Alex</img>', slot_key: 'starter', position: 1 }, { person_id: sub, pseudonym: 'Robin', slot_key: 'substitute', position: 2 }] },
  assessment: { status: 'unassessed', effective_at: '2026-10-19T10:00:00Z', evaluated_at: '2026-10-18T10:00:00Z' } }
const root = 'tenants/' + org + '/matches', path = root + '/' + id + '/participants/' + id + '/lineups'
function page<T>(data: T[], current = 1) { return { data, meta: { current_page: current, last_page: 2, total: 2 } } }
function show(item = record, selection = active) {
  return render(<NotificationProvider><MatchLineups active={selection} item={item} participant={participant} root={root} management={false} disabled={false} onInvalid={invalid} onSaved={saved} onClose={() => {}} /></NotificationProvider>)
}
beforeEach(() => {
  vi.resetAllMocks()
  request.mockImplementation(async (target, options) => {
    if (target === 'active-organization/confirmation') return { data: { ...active, confirmed: true } }
    if (options?.method) return { data: { ...revision, number: 2 } }
    if (target.includes('/choices?')) return page([{ id: lineup, label: 'Composition pilote' }])
    return page([revision], target.includes('page=2') ? 2 : 1)
  })
})
describe('compositions de match', () => {
  it('affiche les noms figés et l’auteur sans charger les choix avant une action', async () => {
    const view = show(), user = userEvent.setup()
    await screen.findByText('<script>Motif retenu</script>')
    expect(screen.getByText(/Compte auteur #42/)).toBeInTheDocument()
    expect(view.container.querySelector('script,img')).toBeNull()
    expect(request.mock.calls.some(([target]) => target.includes('/choices?'))).toBe(false)
    const signal = request.mock.calls[0][1].signal as AbortSignal
    await user.click(screen.getByRole('button', { name: 'Affecter une composition' }))
    await screen.findByRole('option', { name: 'Composition pilote' })
    expect(request.mock.calls.some(([target]) => target.includes('/choices?'))).toBe(true)
    view.unmount(); expect(signal.aborted).toBe(true)
  })
  it('confirme l’organisation puis envoie seulement la sélection, le motif et la version', async () => {
    show(); const user = userEvent.setup()
    await screen.findByText('<script>Motif retenu</script>')
    await user.click(screen.getByRole('button', { name: 'Affecter une composition' }))
    const form = screen.getByRole('form', { name: 'Affecter une composition' })
    await user.selectOptions(await within(form).findByLabelText('Composition finalisée'), lineup)
    await user.type(within(form).getByLabelText('Motif de la composition ou du remplacement'), 'Sélection approuvée pour ce match.')
    await user.click(within(form).getByRole('button', { name: 'Enregistrer la composition' }))
    expect(request.mock.calls.filter(([, options]) => options?.method)).toHaveLength(0)
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    expect(saved).toHaveBeenCalledOnce()
    const writes = request.mock.calls.filter(([, options]) => options?.method)
    expect(writes.map(([target]) => target)).toEqual(['active-organization/confirmation', path])
    expect(writes[1][1]).toMatchObject({ method: 'POST', revision: version, body: { version, lineup_id: lineup, reason: 'Sélection approuvée pour ce match.' } })
    expect(Object.keys(writes[1][1].body)).toEqual(['version', 'lineup_id', 'reason'])
  })
  it('pendant le match ne propose que les titulaires et remplaçants retenus', async () => {
    show({ ...record, status: 'in_progress' }); const user = userEvent.setup()
    const form = await screen.findByRole('form', { name: 'Effectuer un remplacement' })
    expect(screen.queryByRole('button', { name: 'Affecter une composition' })).toBeNull()
    expect(within(form).getByLabelText('Titulaire sortant')).toHaveValue(person)
    expect(within(form).getByLabelText('Remplaçant entrant')).toHaveValue(sub)
    await user.type(within(form).getByLabelText('Motif de la composition ou du remplacement'), 'Remplacement demandé par le capitaine.')
    fireEvent.submit(form)
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    expect(request).toHaveBeenCalledWith(path + '/substitutions', expect.objectContaining({ method: 'POST', body: { version, out_person_id: person, in_person_id: sub, reason: 'Remplacement demandé par le capitaine.' } }))
  })
  it('masque les formulaires pour un lecteur, après le match et sur les anciennes pages', async () => {
    const view = show(record, { ...active, context: { ...active.context!, permissions: ['sports.read'] } })
    await screen.findByText('<script>Motif retenu</script>')
    expect(screen.queryByRole('form')).toBeNull(); expect(screen.queryByRole('button', { name: 'Affecter une composition' })).toBeNull()
    view.unmount()
    const ended = show({ ...record, status: 'awaiting_validation' })
    await screen.findByText('<script>Motif retenu</script>'); expect(screen.queryByRole('form')).toBeNull()
    ended.unmount()
    show(); const user = userEvent.setup()
    await screen.findByRole('form', { name: 'Effectuer un remplacement' })
    await user.click(screen.getByRole('button', { name: 'Page suivante' }))
    await screen.findByText('<script>Motif retenu</script>'); expect(screen.queryByRole('form')).toBeNull()
  })
  it('signale un conflit sans rejouer le remplacement', async () => {
    show(); const user = userEvent.setup()
    const form = await screen.findByRole('form', { name: 'Effectuer un remplacement' })
    await user.type(within(form).getByLabelText('Motif de la composition ou du remplacement'), 'Remplacement demandé par le capitaine.')
    const impl = request.getMockImplementation()!
    request.mockImplementation(async (target, options) => {
      if (target.endsWith('/substitutions')) throw new ApiClientError('Le match a changé. Rechargez le dossier.', { status: 409, requestId: 'match-conflict', fieldErrors: {} })
      return impl(target, options)
    })
    fireEvent.submit(form)
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await within(screen.getByRole('region', { name: 'Composition et remplaçants' })).findByRole('alert')
    expect(invalid).toHaveBeenCalledOnce(); expect(saved).not.toHaveBeenCalled()
    expect(request.mock.calls.filter(([target]) => target.endsWith('/substitutions'))).toHaveLength(1)
  })
})
