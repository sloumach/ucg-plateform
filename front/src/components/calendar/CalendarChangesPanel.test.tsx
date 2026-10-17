import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ActiveOrganization } from '../../api/tenancy'
import type { CalendarActivity } from '../../api/calendar'
import type { CalendarChange, CalendarChangeNotice } from '../../api/calendarChanges'
import { ApiClientError } from '../../api/errors'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { CalendarChangesPanel } from './CalendarChangesPanel'
import { CalendarPanel } from './CalendarPanel'
const { request, invalid } = vi.hoisted(() => ({ request: vi.fn(), invalid: vi.fn() }))
vi.mock('../../api/tenancy', async (original) => ({ ...await original<typeof import('../../api/tenancy')>(), tenancyRequest: request }))
const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222', version = '33333333-3333-4333-8333-333333333333'
const root = 'tenants/' + org + '/calendar'
const active: ActiveOrganization = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'],
  permissions: ['calendar.read', 'calendar.write'], actor_user_id: 42, is_owner: false, membership_id: id }, revision: version, confirmed: false }
const activity: CalendarActivity = { id, version, title: 'Séance pilote', description: null, kind: 'training', starts_at: '2026-10-18T08:00:00+00:00', ends_at: '2026-10-18T09:00:00+00:00',
  origin_timezone: 'UTC', starts_at_origin: '2026-10-18T08:00:00+00:00', ends_at_origin: '2026-10-18T09:00:00+00:00', team_id: null, team_label: null, location: null, url: null,
  visibility: 'private', participants: [], archived_at: null }
const change: CalendarChange = { id, activity_id: id, activity_version: version, kind: 'rescheduled', title: '<img>Activité</img>', reason: '<script>Motif</script>',
  before: { starts_at: activity.starts_at, ends_at: activity.ends_at, origin_timezone: 'UTC' },
  after: { starts_at: '2026-10-18T10:00:00+00:00', ends_at: '2026-10-18T11:00:00+00:00', origin_timezone: 'UTC' }, occurred_at: '2026-10-17T10:00:00Z',
  recipients_count: 1, delivered_count: 1, blocked_count: 0 }
let rows: (CalendarChange | CalendarChangeNotice)[]
function page<T>(data: T[]) { return { data, meta: { current_page: 1, last_page: 1, total: data.length }, calendar_changes: { delivery_enabled: true } } }
function show(selection = active, history?: string) {
  return render(<NotificationProvider><CalendarChangesPanel active={selection} root={root} activity={history} disabled={false} onInvalid={invalid} /></NotificationProvider>)
}
function showCalendar() { return render(<NotificationProvider><CalendarPanel active={active} disabled={false} onInvalid={invalid} /></NotificationProvider>) }
beforeEach(() => {
  vi.resetAllMocks(); rows = [{ id: version, change, delivered_at: '2026-10-17T10:01:00Z', current: false }]
  request.mockImplementation(async () => page(rows))
})
describe('reports et annulations', () => {
  it('charge les messages à ouverture, échappe le motif et distingue un changement historique', async () => {
    const user = userEvent.setup(), view = show()
    expect(request).not.toHaveBeenCalled()
    await user.click(screen.getByRole('button', { name: 'Consulter les changements de planning' }))
    await screen.findByText('Activité reportée · <img>Activité</img>')
    expect(screen.getByText('Motif : <script>Motif</script>')).toBeVisible()
    expect(screen.getByText('Ce message décrit un changement passé. Consultez le planning actuel.')).toBeVisible()
    expect(view.container.querySelector('img,script,iframe')).toBeNull()
    expect(request).toHaveBeenCalledWith(root + '/changes?page=1&per_page=10', expect.objectContaining({ revision: version }))
  })
  it('réserve l’historique et les compteurs à la gestion explicite', async () => {
    rows = [change]; const view = show(active, id)
    await screen.findByText('Personnes concernées : 1 · Notifications remises : 1 · Remises empêchées par les accès actuels : 0')
    expect(request).toHaveBeenCalledWith(root + '/activities/' + id + '/changes?page=1&per_page=10', expect.anything())
    view.unmount(); request.mockClear()
    show({ ...active, context: { ...active.context!, permissions: ['calendar.read'] } }, id)
    expect(screen.queryByRole('region', { name: 'Historique des reports et annulations' })).not.toBeInTheDocument()
    expect(request).not.toHaveBeenCalled()
  })
  it('affiche les annulations sans nouveau créneau et signale une remise désactivée', async () => {
    rows = [{ ...change, kind: 'cancelled' }]
    request.mockResolvedValue({ ...page(rows), calendar_changes: { delivery_enabled: false } })
    const user = userEvent.setup(); show(); await user.click(screen.getByRole('button', { name: 'Consulter les changements de planning' }))
    await screen.findByText('Activité annulée · <img>Activité</img>')
    expect(screen.queryByText(/Nouveau créneau/)).not.toBeInTheDocument()
    expect(screen.getByText('Les notifications automatiques de planning ne sont pas activées.')).toBeVisible()
  })
  it('invalide les accès révoqués et annule la lecture au démontage', async () => {
    request.mockRejectedValue(new ApiClientError('Accès retiré', { status: 403 }))
    const user = userEvent.setup(), view = show(); await user.click(screen.getByRole('button', { name: 'Consulter les changements de planning' }))
    await waitFor(() => expect(invalid).toHaveBeenCalledOnce())
    const signal = request.mock.calls[0][1].signal as AbortSignal
    view.unmount(); expect(signal.aborted).toBe(true)
  })
  it('demande un motif puis confirme l’organisation avant une annulation avec la version courante', async () => {
    let cancelled = false
    request.mockImplementation(async (path, options) => {
      if (path === 'active-organization/confirmation') return { data: { ...active, confirmed: true } }
      if (options?.method === 'PATCH') { cancelled = true; return { data: { ...activity, archived_at: '2026-10-17T10:00:00Z', cancelled_at: '2026-10-17T10:00:00Z' } } }
      return { ...page(cancelled ? [] : [activity]), calendar: { viewer_timezone: 'UTC', display_timezone: 'UTC' } }
    })
    const user = userEvent.setup(); showCalendar(); await user.click(screen.getByRole('button', { name: 'Ouvrir le calendrier' }))
    await screen.findByText('Séance pilote'); await user.click(screen.getByRole('button', { name: 'Annuler l’activité' }))
    const form = within(screen.getByRole('form', { name: 'Annulation de l’activité' }))
    await user.type(form.getByLabelText('Motif opérationnel du changement'), 'Créneau annulé par les participants.')
    await user.click(form.getByRole('button', { name: 'Annuler l’activité' }))
    expect(request.mock.calls.filter(([, options]) => options?.method === 'PATCH')).toHaveLength(0)
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await screen.findByText('Aucune activité accessible dans cette période.')
    expect(request).toHaveBeenCalledWith(root + '/activities/' + id, expect.objectContaining({ method: 'PATCH', revision: version,
      body: { version, archive: true, change_reason: 'Créneau annulé par les participants.' } }))
  })
  it('transmet le motif et les instants UTC du report lors du contrôle puis de la sauvegarde', async () => {
    request.mockImplementation(async (path, options) => {
      if (path === 'active-organization/confirmation') return { data: { ...active, confirmed: true } }
      if (path.endsWith('/activity-conflicts')) return { data: { conflicts: [], truncated: false, availability_checked: false, can_override: false, review_token: 'review-report', expires_in: 300 } }
      if (options?.method === 'PATCH') return { data: { ...activity, ...options.body } }
      return { ...page(path.includes('/resources?') || path.includes('/people?') || path.includes('/teams?') ? [] : [activity]), calendar: { viewer_timezone: 'UTC', display_timezone: 'UTC' } }
    })
    const user = userEvent.setup(); showCalendar(); await user.click(screen.getByRole('button', { name: 'Ouvrir le calendrier' })); await screen.findByText('Séance pilote')
    await user.click(screen.getByRole('button', { name: 'Modifier l’activité' }))
    const form = within(screen.getByRole('form', { name: 'Enregistrer l’activité' }))
    fireEvent.change(form.getByLabelText('Début dans le fuseau d’origine'), { target: { value: '2026-10-18T10:00' } })
    fireEvent.change(form.getByLabelText('Fin dans le fuseau d’origine'), { target: { value: '2026-10-18T11:00' } })
    await user.type(form.getByLabelText('Motif opérationnel du changement'), 'Adaptation du créneau de préparation.')
    await user.click(form.getByRole('button', { name: 'Vérifier les conflits' })); await form.findByText('Aucun conflit détecté dans le périmètre contrôlé.')
    await user.click(form.getByRole('button', { name: 'Enregistrer l’activité' })); await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith(root + '/activities/' + id, expect.objectContaining({ method: 'PATCH', body: expect.objectContaining({
      starts_at: '2026-10-18T10:00:00+00:00', ends_at: '2026-10-18T11:00:00+00:00', change_reason: 'Adaptation du créneau de préparation.', version, conflict_review: 'review-report',
    }) })))
  })
})
