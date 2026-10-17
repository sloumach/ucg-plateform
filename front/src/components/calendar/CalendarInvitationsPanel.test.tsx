import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ActiveOrganization } from '../../api/tenancy'
import type { CalendarActivity } from '../../api/calendar'
import type { CalendarInvitation } from '../../api/calendarInvitations'
import { ApiClientError } from '../../api/errors'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { CalendarInvitationsPanel } from './CalendarInvitationsPanel'
const { request, invalid } = vi.hoisted(() => ({ request: vi.fn(), invalid: vi.fn() }))
vi.mock('../../api/tenancy', async (original) => ({ ...await original<typeof import('../../api/tenancy')>(), tenancyRequest: request }))
const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222'
const version = '33333333-3333-4333-8333-333333333333', person = '44444444-4444-4444-8444-444444444444', invitationId = '55555555-5555-4555-8555-555555555555'
const root = 'tenants/' + org + '/calendar'
const active: ActiveOrganization = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'],
  permissions: ['calendar.read', 'calendar.write', 'calendar.invitations.manage', 'calendar.invitations.respond'], actor_user_id: 42, is_owner: false, membership_id: id }, revision: version, confirmed: false }
const activity: CalendarActivity = { id, version, title: '<script>Activité</script>', description: null, kind: 'training', starts_at: '2026-10-18T08:00:00+00:00', ends_at: '2026-10-18T09:00:00+00:00',
  origin_timezone: 'Europe/Paris', starts_at_origin: '2026-10-18T10:00:00+02:00', ends_at_origin: '2026-10-18T11:00:00+02:00', team_id: null, team_label: null, location: null, url: null, visibility: 'private',
  participants: [{ id: person, label: 'Joueur' }], archived_at: null }
const invitation: CalendarInvitation = { id: invitationId, activity_id: id, activity_version: version, title: activity.title, starts_at: activity.starts_at, origin_timezone: activity.origin_timezone,
  person_id: person, person_label: '<img>Joueur</img>', response: 'pending', response_deadline: '2026-10-18T07:00:00+00:00', reminder_offsets: [60], responded_at: null, sent_at: '2026-10-17T10:00:00+00:00',
  current: true, expired: false, blocked_reason: null, delivery_count: 1, can_respond: true, version }
let rows: CalendarInvitation[]
function result() { return { data: rows, meta: { current_page: 1, last_page: 1, total: rows.length }, calendar_invitations: { delivery_enabled: true } } }
function show(item?: CalendarActivity, selection = active, disabled = false) {
  return render(<NotificationProvider><CalendarInvitationsPanel active={selection} root={root} activity={item} disabled={disabled} onInvalid={invalid} /></NotificationProvider>)
}
beforeEach(() => {
  vi.resetAllMocks(); rows = []
  request.mockImplementation(async (path, options) => {
    if (path === 'active-organization/confirmation') return { data: { ...active, confirmed: true } }
    if (options?.method === 'POST') { rows = [{ ...invitation, can_respond: false, sent_at: null }]; return result() }
    if (options?.method === 'PATCH') { rows = rows.map((item) => ({ ...item, response: options.body.response })); return { data: rows[0] } }
    if (path.includes('/deliveries?')) return { data: [{ id, kind: 'reminder', offset_minutes: 60, created_at: '2026-10-18T06:00:00+00:00' }], meta: { current_page: 1, last_page: 1, total: 1 } }
    return result()
  })
})
describe('convocations du calendrier', () => {
  it('charge seulement à ouverture et masque la gestion sans permission explicite', async () => {
    const user = userEvent.setup(), view = show(activity)
    expect(request).not.toHaveBeenCalled()
    await user.click(screen.getByRole('button', { name: 'Consulter les convocations' }))
    await screen.findByText('Aucune convocation accessible.')
    expect(request).toHaveBeenCalledWith(root + '/activities/' + id + '/invitations?page=1&per_page=10', expect.anything())
    view.unmount(); request.mockClear()
    show(activity, { ...active, context: { ...active.context!, permissions: ['calendar.read', 'calendar.write'] } })
    expect(screen.queryByRole('region', { name: 'Convocations de l’activité' })).not.toBeInTheDocument()
    expect(request).not.toHaveBeenCalled()
  })
  it('prépare des rappels numériques et une échéance UTC après confirmation du tenant', async () => {
    const user = userEvent.setup(); show(activity)
    await user.click(screen.getByRole('button', { name: 'Consulter les convocations' }))
    const form = within(await screen.findByRole('form', { name: 'Préparer les convocations' }))
    expect(form.getByLabelText('Échéance de réponse en UTC')).toHaveValue('2026-10-18T07:00')
    await user.type(form.getByLabelText('Rappel facultatif en minutes 2'), '120')
    await user.click(form.getByLabelText('Je confirme les convocations des participants inscrits'))
    await user.click(form.getByRole('button', { name: 'Préparer les convocations' }))
    expect(request.mock.calls.filter(([, options]) => options?.method === 'POST')).toHaveLength(0)
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith(root + '/activities/' + id + '/invitations', expect.objectContaining({ method: 'POST', revision: version,
      body: { activity_version: version, response_deadline: '2026-10-18T07:00:00+00:00', reminder_offsets: [60, 120], confirm_invitations: true } })))
    expect(await screen.findByText('En attente de remise')).toBeInTheDocument()
    expect(screen.queryByRole('form', { name: 'Préparer les convocations' })).not.toBeInTheDocument()
  })
  it('enregistre sa réponse avec sa version et échappe les textes puis consulte les remises', async () => {
    rows = [{ ...invitation }]
    const user = userEvent.setup(), { container } = show()
    await user.click(screen.getByRole('button', { name: 'Consulter les convocations' }))
    const form = within(await screen.findByRole('form', { name: 'Enregistrer ma réponse' }))
    expect(screen.getByRole('heading', { name: '<script>Activité</script>' })).toBeInTheDocument()
    expect(container.querySelector('script,img')).toBeNull()
    await user.selectOptions(form.getByLabelText('Réponse'), 'absent')
    await user.click(form.getByRole('button', { name: 'Enregistrer ma réponse' }))
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith(root + '/invitations/' + invitationId, expect.objectContaining({ method: 'PATCH', revision: version, body: { version, response: 'absent' } })))
    await user.click(screen.getByRole('button', { name: 'Historique des remises' }))
    expect(await screen.findByText(/60 minutes avant l’échéance/)).toBeInTheDocument()
  })
  it('masque la réponse obsolète ou expirée et affiche le blocage sans exposer de compte', async () => {
    rows = [{ ...invitation, can_respond: false, current: false, blocked_reason: 'recipient_unavailable' }]
    const user = userEvent.setup(); show(activity)
    await user.click(screen.getByRole('button', { name: 'Consulter les convocations' }))
    expect(await screen.findByText('Convocation obsolète')).toBeInTheDocument()
    expect(screen.queryByRole('form', { name: 'Enregistrer ma réponse' })).not.toBeInTheDocument()
  })
  it('ne rejoue pas une réponse après conflit et demande le rechargement du contexte', async () => {
    rows = [{ ...invitation }]
    request.mockImplementation(async (_path, options) => {
      if (options?.method === 'PATCH') throw new ApiClientError('Réponse modifiée ailleurs.', { code: 'CALENDAR_INVITATION_STALE', status: 409 })
      return result()
    })
    const user = userEvent.setup(); show(undefined, { ...active, confirmed: true })
    await user.click(screen.getByRole('button', { name: 'Consulter les convocations' }))
    const form = within(await screen.findByRole('form', { name: 'Enregistrer ma réponse' }))
    await user.selectOptions(form.getByLabelText('Réponse'), 'present'); await user.click(form.getByRole('button', { name: 'Enregistrer ma réponse' }))
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(invalid).toHaveBeenCalledTimes(1))
    expect(request.mock.calls.filter(([, options]) => options?.method === 'PATCH')).toHaveLength(1)
  })
  it('signale la remise désactivée et respecte un panneau désactivé', async () => {
    request.mockResolvedValue({ ...result(), calendar_invitations: { delivery_enabled: false } })
    const user = userEvent.setup(), view = show()
    await user.click(screen.getByRole('button', { name: 'Consulter les convocations' }))
    expect(await screen.findByText(/La remise des convocations est désactivée/)).toBeInTheDocument()
    view.unmount(); show(undefined, active, true)
    expect(screen.getByRole('button', { name: 'Consulter les convocations' })).toBeDisabled()
  })
})
