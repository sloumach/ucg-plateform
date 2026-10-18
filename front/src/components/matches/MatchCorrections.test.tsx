import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ActiveOrganization } from '../../api/tenancy'
import type { MatchRecord, MatchGame, MatchCorrection, MatchCorrectionPage } from '../../api/matches'
import { ApiClientError } from '../../api/errors'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { MatchCorrections } from './MatchCorrections'

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

const root = 'tenants/' + org + '/matches', path = root + '/' + id + '/corrections'
const game: MatchGame = { id: b, number: 1, match_version: version, outcome: 'played', map: '<script>Carte pilote</script>', mode: null, game_patch: '1.0',
  reason: 'Source conservée.', actor_user_id: 42, occurred_at: '2026-10-19T11:00:00Z', results: [
    { participant_id: a, label: 'Alex', score: 13, lineup_revision_id: null }, { participant_id: b, label: 'Robin', score: 7, lineup_revision_id: null }] }
const correction: MatchCorrection = { id: a, number: 1, match_version: version, state: 'applied', reason: '<script>Motif conservé</script>', actor_user_id: 42, occurred_at: game.occurred_at, applied_at: game.occurred_at,
  before: [{ game_id: b, number: 1, outcome: 'played', results: game.results }], after: [{ game_id: b, number: 1, outcome: 'played', results: game.results.map((r) => ({ ...r, score: r.participant_id === a ? 0 : 13 })) }] }
const selection = { ...active, context: { ...active.context!, permissions: ['sports.read', 'sports.write', 'sports.correct'] } }
function page(can = true, pending = false, n = 1): MatchCorrectionPage { return { data: [{ ...correction, state: pending ? 'pending' : 'applied' }], meta: { current_page: n, last_page: 2, total: 2, request_id: 'correction-test' },
  corrections: { match_version: version, score_revision: a, pending, can_correct: can } } }
function show(shared = false) { return render(<NotificationProvider><MatchCorrections active={selection} item={{ ...record, ...(shared ? { visibility: 'organization' as const } : {}) }} root={root} management={false} disabled={false} onInvalid={invalid} onSaved={saved} onClose={() => {}} /></NotificationProvider>) }
beforeEach(() => {
  vi.resetAllMocks()
  request.mockImplementation(async (target, options) => {
    if (target === 'active-organization/confirmation') return { data: { ...selection, confirmed: true } }
    if (options?.method) return { data: correction }
    if (target.includes('/games?')) return { data: [game], scorecard: { match_version: version, score_revision: a, rules: { format: 'bo3', game_limit: 3, allow_draw: false }, series: null, recalculation_pending: false }, meta: { current_page: 1, last_page: 1, total: 1 } }
    return page(true, false, target.includes('page=2') ? 2 : 1)
  })
})
async function editor() {
  const user = userEvent.setup(); await user.click(await screen.findByRole('button', { name: 'Préparer une correction' }))
  const form = await screen.findByRole('form', { name: 'Demander la correction et le recalcul' })
  await user.click(within(form).getByLabelText('Corriger cette partie #1'))
  const scores = within(form).getAllByRole('spinbutton')
  await user.clear(scores[0]); await user.type(scores[0], '0'); await user.clear(scores[1]); await user.type(scores[1], '13')
  await user.type(within(form).getByLabelText('Motif de la correction'), 'Correction justifiée par le rapport source.')
  await user.click(within(form).getByLabelText('Je confirme ces corrections et la remise en validation du résultat.'))
  return { user, form }
}
describe('correction des scores', () => {
  it('échappe le motif et distingue les valeurs avant et après sans écriture implicite', async () => {
    const view = show(); await screen.findByText(correction.reason)
    expect(screen.getByText('Avant correction')).toBeVisible(); expect(screen.getByText('Après correction')).toBeVisible()
    expect(view.container.querySelector('script,img,iframe,a')).toBeNull()
    expect(request.mock.calls.some(([, options]) => options?.method)).toBe(false)
  })
  it('respecte les droits serveur et interdit une nouvelle correction pendant le recalcul', async () => {
    request.mockResolvedValue(page(false)); const view = show(); await screen.findByText(correction.reason)
    expect(screen.queryByRole('button', { name: 'Préparer une correction' })).toBeNull(); view.unmount()
    request.mockResolvedValue(page(true, true)); show(); await screen.findByText(correction.reason)
    expect(screen.queryByRole('button', { name: 'Préparer une correction' })).toBeNull()
    expect(screen.getByRole('button', { name: 'Relancer le recalcul' })).toBeVisible()
  })
  it('transmet seulement les scores choisis, les deux versions et une clé de répétition stable', async () => {
    show(); const { user, form } = await editor(); fireEvent.submit(form)
    expect(request.mock.calls.some(([, options]) => options?.method)).toBe(false)
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    expect(request).toHaveBeenCalledWith(path, expect.objectContaining({ method: 'POST', body: { version, score_revision: a, idempotency_key: expect.any(String), reason: 'Correction justifiée par le rapport source.', confirm_correction: true,
      games: [{ game_id: b, outcome: 'played', results: [{ participant_id: a, score: 0 }, { participant_id: b, score: 13 }] }] } }))
    expect(saved).toHaveBeenCalledOnce()
  })
  it('exige une confirmation supplémentaire pour un match partagé', async () => {
    show(true); const { user, form } = await editor()
    await user.click(within(form).getByLabelText('Je confirme le partage des scores corrigés avec les lecteurs autorisés du match.'))
    fireEvent.submit(form); await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    expect(request).toHaveBeenCalledWith(path, expect.objectContaining({ body: expect.objectContaining({ confirm_sharing: true }) }))
  })
  it('relance uniquement le recalcul enregistré après confirmation humaine', async () => {
    request.mockImplementation(async (_target, options) => options?.method ? { data: correction } : page(true, true))
    show(); const user = userEvent.setup(); const form = await screen.findByRole('form', { name: 'Relancer le recalcul' })
    fireEvent.submit(form); expect(request.mock.calls.some(([, options]) => options?.method)).toBe(false)
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    expect(request).toHaveBeenCalledWith(path + '/' + a + '/retries', expect.objectContaining({ method: 'POST', body: {} }))
  })
  it('conserve la saisie et la clé après 422 puis ne rejoue pas un conflit de version', async () => {
    const base = request.getMockImplementation()!, keys: string[] = []; let stale = false
    request.mockImplementation(async (target, options) => {
      if (target === path && options?.method) { keys.push(options.body.idempotency_key); throw new ApiClientError(stale ? 'Les scores ont changé.' : 'Correction incohérente.', { status: stale ? 409 : 422, fieldErrors: stale ? {} : { games: ['Correction incohérente.'] } }) }
      return base(target, options)
    })
    show(); const { user, form } = await editor(); fireEvent.submit(form); await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await within(form).findByText('Correction incohérente.'); expect(within(form).getAllByRole('spinbutton')[0]).toHaveValue(0)
    stale = true; fireEvent.submit(form); await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' })); await screen.findAllByText('Les scores ont changé.')
    expect(keys).toHaveLength(2); expect(keys[0]).toBe(keys[1]); expect(invalid).toHaveBeenCalledOnce(); expect(saved).not.toHaveBeenCalled()
  })
  it('masque la préparation sur les anciennes pages et transmet la révision de contexte', async () => {
    show(); const user = userEvent.setup(); await screen.findByText(correction.reason)
    await user.click(screen.getByRole('button', { name: 'Page suivante' })); await screen.findByText(correction.reason)
    expect(screen.queryByRole('button', { name: 'Préparer une correction' })).toBeNull()
    expect(request).toHaveBeenCalledWith(path + '?page=2&per_page=5', expect.objectContaining({ revision: version, signal: expect.any(AbortSignal) }))
  })
})
