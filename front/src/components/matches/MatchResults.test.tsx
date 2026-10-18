import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ActiveOrganization } from '../../api/tenancy'
import type { MatchRecord, MatchResult, MatchResultEvent } from '../../api/matches'
import { ApiClientError } from '../../api/errors'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { MatchResults } from './MatchResults'

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
const event: MatchResultEvent = { id: a, number: 1, action: 'submit', outcome: 'played', from_status: 'in_progress', to_status: 'awaiting_validation', match_version: version,
  submission_id: null, proof_reference: '<script>Rapport conservé R-42</script>', reason: 'Résultat relevé et proposé.', actor_user_id: 42, occurred_at: '2026-10-19T11:00:00Z',
  snapshot: { outcome: 'played', winner_ids: [a], participants: [{ participant_id: a, label: 'Alex' }, { participant_id: b, label: 'Robin' }], rules: { format: 'bo3', game_limit: 3, allow_draw: false },
    series: { complete: true, draw: false, played: 2, winner_ids: [a], standings: [{ participant_id: a, score: 2 }, { participant_id: b, score: 0 }] }, game_ids: [id] } }
const result: MatchResult = { match_version: version, status: 'awaiting_validation', locked: true, current: event, submission: event, allowed_actions: ['approve', 'dispute'] }
const root = 'tenants/' + org + '/matches', path = root + '/' + id + '/results'
function page(current: MatchResult = result, n = 1) { return { data: [event], result: current, meta: { current_page: n, last_page: 2, total: 2 } } }
function show(selection = active) {
  return render(<NotificationProvider><MatchResults active={selection} item={record} root={root} management={false} disabled={false} onInvalid={invalid} onSaved={saved} onClose={() => {}} /></NotificationProvider>)
}
beforeEach(() => {
  vi.resetAllMocks()
  request.mockImplementation(async (target, options) => {
    if (target === 'active-organization/confirmation') return { data: { ...active, confirmed: true } }
    if (options?.method) return { data: result }
    return page(result, target.includes('page=2') ? 2 : 1)
  })
})
describe('soumission et validation des résultats', () => {
  it('affiche le calcul scellé et la preuve référencée en texte échappé sans l’ouvrir', async () => {
    const view = show()
    await screen.findByRole('region', { name: 'Proposition courante' })
    expect(screen.getAllByText(/Rapport conservé R-42/)).toHaveLength(2)
    expect(view.container.querySelector('script,img,a')).toBeNull()
    expect(request.mock.calls.some(([, options]) => options?.method)).toBe(false)
    const signal = request.mock.calls[0][1].signal as AbortSignal
    view.unmount(); expect(signal.aborted).toBe(true)
  })
  it('soumet une référence et un motif sans imposer le calcul serveur', async () => {
    request.mockImplementation(async (_target, options) => options?.method ? { data: result } : page({ ...result, current: null, submission: null, locked: false, allowed_actions: ['submit'] }))
    show(); const user = userEvent.setup()
    const form = await screen.findByRole('form', { name: 'Soumettre le résultat' })
    await user.type(within(form).getByLabelText('Référence de preuve'), 'Rapport privé conservé R-42')
    await user.click(within(form).getByLabelText('Je confirme que cette preuve est conservée et consultable par l’approbateur habilité.'))
    await user.type(within(form).getByLabelText('Motif de la proposition ou de la décision'), 'Proposition vérifiée selon le rapport.')
    fireEvent.submit(form)
    expect(request.mock.calls.some(([, options]) => options?.method)).toBe(false)
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    expect(request).toHaveBeenCalledWith(path, expect.objectContaining({ method: 'POST', body: { version, outcome: 'played', proof_reference: 'Rapport privé conservé R-42', confirm_proof: true, reason: 'Proposition vérifiée selon le rapport.' } }))
    expect(saved).toHaveBeenCalledOnce()
  })
  it('approuve uniquement après déclaration de vérification et confirmation du tenant', async () => {
    show(); const user = userEvent.setup()
    const form = await screen.findByRole('form', { name: 'Approuver le résultat' })
    await user.click(within(form).getByLabelText('J’ai personnellement consulté la preuve et vérifié le résultat proposé.'))
    await user.type(within(form).getByLabelText('Motif de la proposition ou de la décision'), 'Preuve personnellement vérifiée et conforme.')
    fireEvent.submit(form); await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    expect(request).toHaveBeenCalledWith(path + '/approvals', expect.objectContaining({ method: 'POST', body: { version, submission_id: a, confirm_evidence: true, reason: 'Preuve personnellement vérifiée et conforme.' } }))
  })
  it('conteste avec motif sans réaffirmer une vérification de preuve', async () => {
    show(); const user = userEvent.setup()
    const form = await screen.findByRole('form', { name: 'Contester le résultat' })
    await user.type(within(form).getByLabelText('Motif de la proposition ou de la décision'), 'Le rapport et les scores doivent être revus.')
    fireEvent.submit(form); await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    expect(request).toHaveBeenCalledWith(path + '/disputes', expect.objectContaining({ body: { version, submission_id: a, reason: 'Le rapport et les scores doivent être revus.' } }))
  })
  it('respecte les actions serveur et masque les décisions sur une ancienne page', async () => {
    request.mockResolvedValue(page({ ...result, allowed_actions: [] }))
    const view = show()
    await screen.findByRole('region', { name: 'Proposition courante' }); expect(screen.queryByRole('form')).toBeNull(); view.unmount()
    request.mockImplementation(async (target) => page(result, target.includes('page=2') ? 2 : 1))
    show(); const user = userEvent.setup()
    await screen.findByRole('form', { name: 'Approuver le résultat' })
    await user.click(screen.getByRole('button', { name: 'Page suivante' }))
    await screen.findByRole('region', { name: 'Proposition courante' }); expect(screen.queryByRole('form')).toBeNull()
  })
  it('demande le bénéficiaire pour un forfait sans fabriquer de score', async () => {
    request.mockImplementation(async (_target, options) => options?.method ? { data: result } : page({ ...result, current: null, submission: null, locked: false, allowed_actions: ['submit'] }))
    show(); const user = userEvent.setup()
    const form = await screen.findByRole('form', { name: 'Soumettre le résultat' })
    await user.selectOptions(within(form).getByLabelText('Nature du résultat'), 'forfeit')
    await user.selectOptions(within(form).getByLabelText('Bénéficiaire du forfait ou de l’abandon'), a)
    await user.type(within(form).getByLabelText('Référence de preuve'), 'Rapport forfait conservé R-42')
    await user.click(within(form).getByLabelText('Je confirme que cette preuve est conservée et consultable par l’approbateur habilité.'))
    await user.type(within(form).getByLabelText('Motif de la proposition ou de la décision'), 'Forfait justifié par le rapport conservé.')
    fireEvent.submit(form); await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    expect(request).toHaveBeenCalledWith(path, expect.objectContaining({ body: expect.objectContaining({ outcome: 'forfeit', winner_participant_id: a }) }))
  })
  it('conserve la saisie après 422 et ne rejoue pas une décision périmée après 409', async () => {
    const impl = request.getMockImplementation()!
    let stale = false
    request.mockImplementation(async (target, options) => {
      if (target.endsWith('/approvals') && options?.method) throw new ApiClientError(stale ? 'Le résultat a changé.' : 'Motif invalide.', { status: stale ? 409 : 422, requestId: 'result-request', fieldErrors: stale ? {} : { reason: ['Motif invalide.'] } })
      return impl(target, options)
    })
    show(); const user = userEvent.setup()
    const form = await screen.findByRole('form', { name: 'Approuver le résultat' })
    await user.type(within(form).getByLabelText('Motif de la proposition ou de la décision'), 'Décision retenue après consultation.')
    fireEvent.submit(form); await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await within(form).findByText('Motif invalide.')
    expect(within(form).getByLabelText('Motif de la proposition ou de la décision')).toHaveValue('Décision retenue après consultation.')
    expect(invalid).not.toHaveBeenCalled()
    stale = true; fireEvent.submit(form); await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await screen.findAllByText('Le résultat a changé.')
    expect(invalid).toHaveBeenCalledOnce(); expect(saved).not.toHaveBeenCalled()
    expect(request.mock.calls.filter(([target]) => target.endsWith('/approvals'))).toHaveLength(2)
  })
})

it('bloque les décisions locales quand le recalcul est en attente', async () => {
  request.mockResolvedValue(page({ ...result, recalculation_pending: true }))
  show(); await screen.findByRole('region', { name: 'Proposition courante' })
  expect(screen.queryByRole('form')).toBeNull()
  expect(screen.getByText(/Recalcul en attente/)).toBeVisible()
})
