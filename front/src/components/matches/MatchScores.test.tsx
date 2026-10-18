import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ActiveOrganization } from '../../api/tenancy'
import type { MatchRecord, MatchScorecard, MatchGame } from '../../api/matches'
import { ApiClientError } from '../../api/errors'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { MatchScores } from './MatchScores'

const { request, invalid, saved } = vi.hoisted(() => ({ request: vi.fn(), invalid: vi.fn(), saved: vi.fn() }))
vi.mock('../../api/tenancy', async (original) => ({ ...await original<typeof import('../../api/tenancy')>(), tenancyRequest: request }))
const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222', version = '33333333-3333-4333-8333-333333333333'
const a = '44444444-4444-4444-8444-444444444444', b = '55555555-5555-4555-8555-555555555555'
const active: ActiveOrganization = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'],
  permissions: ['sports.read', 'sports.write'], actor_user_id: 42, is_owner: false, membership_id: id }, revision: version, confirmed: false }
const record: MatchRecord = { id, version: id, title: 'Scrim', kind: 'scrim', format: 'bo3', game_id: id, game_key: 'pilot', game_name: 'Jeu pilote',
  status: 'in_progress', visibility: 'private', starts_at: '2026-10-19T10:00:00Z', ends_at: '2026-10-19T12:00:00Z', origin_timezone: 'UTC',
  starts_at_origin: '2026-10-19T10:00:00+00:00', ends_at_origin: '2026-10-19T12:00:00+00:00', responsible_person_id: a, responsible_label: 'Alex', editable: false, available_transitions: ['awaiting_validation'],
  participants: [{ id: a, kind: 'person', source_id: a, reference_version: version, slot: 1, label: 'Alex' }, { id: b, kind: 'person', source_id: b, reference_version: version, slot: 2, label: 'Robin' }] }
const card: MatchScorecard = { match_version: version, rules: { format: 'bo3', game_limit: 3, allow_draw: false }, series: { complete: false, played: 1, draw: false, winner_ids: [], standings: [{ participant_id: a, score: 1 }, { participant_id: b, score: 0 }] } }
const game: MatchGame = { id, number: 1, match_version: version, outcome: 'played', map: '<img>Carte</img>', mode: null, game_patch: '1.0', actor_user_id: 42, occurred_at: '2026-10-19T11:00:00Z',
  reason: '<script>Motif retenu</script>', results: [{ participant_id: a, label: 'Alex', score: 13, lineup_revision_id: null }, { participant_id: b, label: 'Robin', score: 7, lineup_revision_id: null }] }
const root = 'tenants/' + org + '/matches', path = root + '/' + id + '/games'
function page(scorecard: MatchScorecard = card, current = 1) { return { data: [game], scorecard, meta: { current_page: current, last_page: 2, total: 2 } } }
function show(item = record, selection = active) {
  return render(<NotificationProvider><MatchScores active={selection} item={item} root={root} management={false} disabled={false} onInvalid={invalid} onSaved={saved} onClose={() => {}} /></NotificationProvider>)
}
beforeEach(() => {
  vi.resetAllMocks()
  request.mockImplementation(async (target, options) => {
    if (target === 'active-organization/confirmation') return { data: { ...active, confirmed: true } }
    if (options?.method) return { data: { ...card, game } }
    return page(card, target.includes('page=2') ? 2 : 1)
  })
})
describe('parties et scores', () => {
  it('affiche les scores calculés et échappe les noms et motifs', async () => {
    const view = show()
    await screen.findByText('<script>Motif retenu</script>')
    expect(screen.getByText('Alex : 1')).toBeInTheDocument()
    expect(view.container.querySelector('script,img')).toBeNull()
    expect(request.mock.calls.filter(([, options]) => options?.method)).toHaveLength(0)
    const signal = request.mock.calls[0][1].signal as AbortSignal
    view.unmount(); expect(signal.aborted).toBe(true)
  })
  it('confirme le tenant puis écrit avec la version fraîche de la feuille', async () => {
    show(); const user = userEvent.setup()
    const form = await screen.findByRole('form', { name: 'Ajouter une partie' })
    await user.type(within(form).getByLabelText('Carte de la partie'), 'Arena')
    await user.type(within(form).getByLabelText('Version du jeu'), '2.0')
    await user.type(within(form).getByLabelText('Score de la partie · Alex'), '13')
    await user.type(within(form).getByLabelText('Score de la partie · Robin'), '8')
    await user.type(within(form).getByLabelText('Motif des règles ou du résultat'), 'Résultat confirmé sur la feuille.')
    fireEvent.submit(form)
    expect(request.mock.calls.some(([, options]) => options?.method)).toBe(false)
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    expect(request).toHaveBeenCalledWith(path, expect.objectContaining({ method: 'POST', body: { version, outcome: 'played', map: 'Arena', mode: null, game_patch: '2.0', reason: 'Résultat confirmé sur la feuille.', results: [{ participant_id: a, score: 13 }, { participant_id: b, score: 8 }] } }))
    expect(saved).toHaveBeenCalledOnce()
  })
  it('propose les règles explicites avant toute première partie', async () => {
    request.mockResolvedValue(page({ match_version: version, rules: null, series: null }))
    show(); const user = userEvent.setup()
    const form = await screen.findByRole('form', { name: 'Fixer les règles de score' })
    expect(screen.queryByRole('form', { name: 'Ajouter une partie' })).toBeNull()
    expect(within(form).getByLabelText('Nombre prévu de parties')).toHaveValue(3)
    await user.click(within(form).getByLabelText('Autoriser les nuls et les égalités finales'))
    await user.type(within(form).getByLabelText('Motif des règles ou du résultat'), 'Règlement interne explicitement retenu.')
    fireEvent.submit(form)
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    expect(request).toHaveBeenCalledWith(root + '/' + id + '/score-rules', expect.objectContaining({ method: 'POST', body: { version, game_limit: 3, allow_draw: true, reason: 'Règlement interne explicitement retenu.' } }))
  })
  it('enregistre un remake sans fabriquer de score', async () => {
    show(); const user = userEvent.setup()
    const form = await screen.findByRole('form', { name: 'Ajouter une partie' })
    await user.selectOptions(within(form).getByLabelText('Résultat de la partie'), 'remake')
    expect(within(form).queryByLabelText('Score de la partie · Alex')).toBeNull()
    await user.type(within(form).getByLabelText('Mode de jeu'), 'Classique')
    await user.type(within(form).getByLabelText('Version du jeu'), '1.0')
    await user.type(within(form).getByLabelText('Motif des règles ou du résultat'), 'Partie rejouée après incident.')
    fireEvent.submit(form); await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    expect(request).toHaveBeenCalledWith(path, expect.objectContaining({ body: expect.objectContaining({ outcome: 'remake', results: [{ participant_id: a, score: null }, { participant_id: b, score: null }] }) }))
  })
  it('masque les formulaires pour un lecteur, un match annulé ou une série complète', async () => {
    const view = show(record, { ...active, context: { ...active.context!, permissions: ['sports.read'] } })
    await screen.findByText('<script>Motif retenu</script>'); expect(screen.queryByRole('form')).toBeNull(); view.unmount()
    const cancelled = show({ ...record, status: 'cancelled' })
    await screen.findByText('<script>Motif retenu</script>'); expect(screen.queryByRole('form')).toBeNull(); cancelled.unmount()
    request.mockResolvedValue(page({ ...card, series: { ...card.series!, complete: true } }))
    show(); await screen.findByText('<script>Motif retenu</script>'); expect(screen.queryByRole('form')).toBeNull()
  })
  it('fige les formulaires de score dès qu’un résultat est soumis', async () => {
    request.mockResolvedValue(page({ ...card, result_locked: true }))
    show(); await screen.findByText('<script>Motif retenu</script>'); expect(screen.queryByRole('form')).toBeNull()
  })
  it('n’écrit pas sans permission de partage sur un match partagé', async () => {
    show({ ...record, visibility: 'organization' })
    await screen.findByText('<script>Motif retenu</script>'); expect(screen.queryByRole('form')).toBeNull()
  })
  it('pagine l’historique et masque la saisie des anciennes pages', async () => {
    show(); const user = userEvent.setup()
    await screen.findByRole('form', { name: 'Ajouter une partie' })
    await user.click(screen.getByRole('button', { name: 'Page suivante' }))
    await screen.findByText('<script>Motif retenu</script>'); expect(screen.queryByRole('form')).toBeNull()
    expect(request.mock.calls.some(([target]) => target.includes('page=2'))).toBe(true)
  })
  it('signale une version obsolète sans rejouer la saisie', async () => {
    const impl = request.getMockImplementation()!
    request.mockImplementation(async (target, options) => {
      if (target === path && options?.method) throw new ApiClientError('La feuille a changé.', { status: 409, requestId: 'score-conflict', fieldErrors: {} })
      return impl(target, options)
    })
    show(); const user = userEvent.setup()
    fireEvent.submit(await screen.findByRole('form', { name: 'Ajouter une partie' }))
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await within(screen.getByRole('region', { name: 'Parties et scores' })).findByRole('alert')
    expect(invalid).toHaveBeenCalledOnce(); expect(saved).not.toHaveBeenCalled()
    expect(request.mock.calls.filter(([target, options]) => target === path && options?.method)).toHaveLength(1)
  })
})
