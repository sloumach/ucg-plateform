import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ActiveOrganization } from '../../api/tenancy'
import type { MatchRecord } from '../../api/matches'
import { ApiClientError } from '../../api/errors'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { MatchesPanel } from './MatchesPanel'
const { request, invalid } = vi.hoisted(() => ({ request: vi.fn(), invalid: vi.fn() }))
vi.mock('../../api/tenancy', async (original) => ({ ...await original<typeof import('../../api/tenancy')>(), tenancyRequest: request }))
const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222', version = '33333333-3333-4333-8333-333333333333'
const game = '44444444-4444-4444-8444-444444444444', person = '55555555-5555-4555-8555-555555555555', other = '66666666-6666-4666-8666-666666666666', third = '77777777-7777-4777-8777-777777777777'
const root = 'tenants/' + org + '/matches'
const active: ActiveOrganization = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'],
  permissions: ['sports.read', 'sports.write'], actor_user_id: 42, is_owner: false, membership_id: id }, revision: version, confirmed: false }
const record: MatchRecord = { id, version, title: '<script>Scrim pilote</script>', kind: 'scrim', format: 'bo1', game_id: game, game_key: 'pilot', game_name: 'Jeu pilote',
  starts_at: '2026-10-19T10:00:00+00:00', ends_at: '2026-10-19T12:00:00+00:00', starts_at_origin: '2026-10-19T10:00:00+00:00', ends_at_origin: '2026-10-19T12:00:00+00:00',
  origin_timezone: 'UTC', visibility: 'private', status: 'proposed', responsible_person_id: person, responsible_label: 'Alex', editable: true, available_transitions: ['confirmed', 'cancelled'],
  participants: [{ id: person, kind: 'person', source_id: person, label: '<img>Alex</img>', reference_version: version, slot: 1 }, { id: other, kind: 'person', source_id: other, label: 'Robin', reference_version: version, slot: 2 }] }
let rows: MatchRecord[]
function page<T>(data: T[]) { return { data, meta: { current_page: 1, last_page: 1, total: data.length } } }
function show(selection = active) { return render(<NotificationProvider><MatchesPanel active={selection} disabled={false} onInvalid={invalid} /></NotificationProvider>) }
beforeEach(() => {
  vi.resetAllMocks(); rows = [record]
  request.mockImplementation(async (path, options) => {
    if (path === 'active-organization/confirmation') return { data: { ...active, confirmed: true } }
    if (path.includes('/references/game')) return page([{ id: game, label: 'Jeu pilote' }])
    if (path.includes('/references/person')) return page([{ id: person, label: 'Alex' }, { id: other, label: 'Robin' }, { id: third, label: 'Sam' }])
    if (path.includes('/history?')) return page([{ id, from_status: 'proposed', to_status: 'confirmed', reason: '<script>Motif conservé</script>', match_version: version, occurred_at: '2026-10-18T10:00:00Z' }])
    if (options?.method) {
      const participants = options.body?.participants?.map((p: { kind: string; source_id: string }, index: number) => ({ ...p, id: p.source_id, slot: index + 1, reference_version: version, label: p.source_id })) ?? record.participants
      rows = [{ ...record, ...options.body, participants }]; return { data: rows[0] }
    }
    return page(rows)
  })
})
describe('scrims et matchs', () => {
  it('charge seulement à ouverture et réserve les commandes aux droits sportifs explicites', async () => {
    const user = userEvent.setup(), view = show({ ...active, context: { ...active.context!, permissions: ['sports.read'] } })
    expect(request).not.toHaveBeenCalled()
    await user.click(screen.getByRole('button', { name: 'Ouvrir les matchs' }))
    await screen.findByRole('heading', { name: record.title })
    expect(screen.queryByRole('button', { name: 'Nouveau match' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Changer le statut du match' })).not.toBeInTheDocument()
    expect(view.container.querySelector('img,script,iframe')).toBeNull()
    view.unmount(); request.mockClear(); show({ ...active, context: { ...active.context!, permissions: ['calendar.read'] } })
    expect(screen.queryByRole('region', { name: 'Scrims et matchs' })).not.toBeInTheDocument(); expect(request).not.toHaveBeenCalled()
  })
  it('crée un lobby de trois personnes après confirmation sans transmettre les libellés', async () => {
    const user = userEvent.setup(); show(); await user.click(screen.getByRole('button', { name: 'Ouvrir les matchs' })); await screen.findByText(record.title)
    await user.click(screen.getByRole('button', { name: 'Nouveau match' }))
    const form = within(screen.getByRole('form', { name: 'Enregistrer le match' }))
    await user.type(form.getByLabelText('Titre du match'), 'Lobby pilote')
    await user.selectOptions(form.getByLabelText('Jeu du match'), game)
    await user.selectOptions(form.getByLabelText('Format du match'), 'lobby')
    await user.selectOptions(form.getByLabelText('Responsable du match'), person)
    fireEvent.change(form.getByLabelText('Début dans le fuseau d’origine'), { target: { value: '2026-10-19T10:00' } })
    fireEvent.change(form.getByLabelText('Fin dans le fuseau d’origine'), { target: { value: '2026-10-19T12:00' } })
    for (const source of [person, other, third]) {
      await user.selectOptions(form.getByLabelText('Participant à ajouter'), source)
      await user.click(form.getByRole('button', { name: 'Ajouter ce participant' }))
    }
    await user.click(form.getByRole('button', { name: 'Enregistrer le match' }))
    expect(request.mock.calls.filter(([path, options]) => path === root && options?.method === 'POST')).toHaveLength(0)
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith(root, expect.objectContaining({ method: 'POST', revision: version, body: {
      title: 'Lobby pilote', kind: 'scrim', format: 'lobby', game_id: game, responsible_person_id: person, visibility: 'private',
      starts_at: record.starts_at, ends_at: record.ends_at, origin_timezone: 'UTC', participants: [person, other, third].map((source_id) => ({ kind: 'person', source_id })),
    } })))
  })
  it('envoie uniquement le titre changé avec la version du dossier proposé', async () => {
    const user = userEvent.setup(); show(); await user.click(screen.getByRole('button', { name: 'Ouvrir les matchs' })); await screen.findByText(record.title)
    await user.click(screen.getByRole('button', { name: 'Modifier le match' }))
    const form = within(screen.getByRole('form', { name: 'Enregistrer le match' }))
    await user.clear(form.getByLabelText('Titre du match')); await user.type(form.getByLabelText('Titre du match'), 'Titre corrigé')
    expect(form.getByLabelText('Jeu du match')).toBeDisabled()
    await user.click(form.getByRole('button', { name: 'Enregistrer le match' })); await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith(root + '/' + id, expect.objectContaining({ method: 'PATCH', body: { title: 'Titre corrigé', version } })))
  })
  it('confirme une transition motivée avec le statut choisi et la version courante', async () => {
    const user = userEvent.setup(); show(); await user.click(screen.getByRole('button', { name: 'Ouvrir les matchs' })); await screen.findByText(record.title)
    await user.click(screen.getByRole('button', { name: 'Changer le statut du match' }))
    const form = within(screen.getByRole('form', { name: 'Changement de statut du match' }))
    await user.selectOptions(form.getByLabelText('Nouveau statut du match'), 'cancelled')
    await user.type(form.getByLabelText('Motif du changement de statut'), 'Annulation explicitement motivée.')
    await user.click(form.getByRole('button', { name: 'Changer le statut du match' }))
    expect(request.mock.calls.filter(([path]) => path.endsWith('/transitions'))).toHaveLength(0)
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith(root + '/' + id + '/transitions', expect.objectContaining({ method: 'POST', body: { version, status: 'cancelled', reason: 'Annulation explicitement motivée.' } })))
  })
  it('échappe l’historique et annule sa lecture à la fermeture', async () => {
    const user = userEvent.setup(), view = show(); await user.click(screen.getByRole('button', { name: 'Ouvrir les matchs' })); await screen.findByText(record.title)
    await user.click(screen.getByRole('button', { name: 'Historique des statuts du match' }))
    await screen.findByText('<script>Motif conservé</script>')
    expect(view.container.querySelector('script,img,iframe')).toBeNull()
    const signal = request.mock.calls.find(([path]) => path.includes('/history?'))![1].signal as AbortSignal
    await user.click(screen.getByRole('button', { name: 'Fermer l’historique du match' })); expect(signal.aborted).toBe(true)
  })
  it('masque les modifications sur une rencontre confirmée et les transitions de résultats indisponibles', async () => {
    rows = [{ ...record, status: 'awaiting_validation', editable: false, available_transitions: [] }]
    const user = userEvent.setup(); show(); await user.click(screen.getByRole('button', { name: 'Ouvrir les matchs' })); await screen.findByText(record.title)
    expect(screen.queryByRole('button', { name: 'Modifier le match' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Changer le statut du match' })).not.toBeInTheDocument()
    expect(screen.getByText('Terminé à valider')).toBeVisible()
  })
  it('préserve le motif après 422 puis invalide un contexte périmé sans rejeu', async () => {
    const normal = request.getMockImplementation()!
    let failure = 422
    request.mockImplementation(async (path, options) => {
      if (path.endsWith('/transitions')) throw new ApiClientError(failure === 422 ? 'Motif refusé' : 'Version périmée', { status: failure, fieldErrors: failure === 422 ? { reason: ['Précisez le motif.'] } : {} })
      return normal(path, options)
    })
    const user = userEvent.setup(); show(); await user.click(screen.getByRole('button', { name: 'Ouvrir les matchs' })); await screen.findByText(record.title)
    await user.click(screen.getByRole('button', { name: 'Changer le statut du match' }))
    const form = within(screen.getByRole('form', { name: 'Changement de statut du match' }))
    await user.type(form.getByLabelText('Motif du changement de statut'), 'Participants et créneau relus.')
    await user.click(form.getByRole('button', { name: 'Changer le statut du match' })); await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await screen.findByText('Précisez le motif.'); expect(form.getByLabelText('Motif du changement de statut')).toHaveValue('Participants et créneau relus.')
    failure = 409
    await user.click(form.getByRole('button', { name: 'Changer le statut du match' })); await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(invalid).toHaveBeenCalledOnce())
    expect(request.mock.calls.filter(([path]) => path.endsWith('/transitions'))).toHaveLength(2)
  })
})
