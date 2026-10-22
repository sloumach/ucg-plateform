import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { tenancyRequest, type ActiveOrganization } from '../../api/tenancy'
import { ApiClientError } from '../../api/errors'
import type { AcademySession, AcademyAttendance } from '../../api/academySessions'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { AcademySessionsPanel } from './AcademySessionsPanel'

vi.mock('../../api/tenancy', () => ({ tenancyRequest: vi.fn() }))
const request = vi.mocked(tenancyRequest), onInvalid = vi.fn()
const active: ActiveOrganization = { context: { organization_id: 'org-a', name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'], permissions: ['academy.read', 'academy.manage', 'academy.attendance.manage', 'calendar.read'], is_owner: false, actor_user_id: 42, membership_id: 'member' }, revision: 'revision-a', confirmed: false }
const cohort = { id: 'cohort-a', name: 'Pilote', status: 'active' as const, version: 'cohort-version' }
const cycle = { id: 'cycle-a', name: 'Automne', status: 'active' as const, version: 'cycle-version', cohort_id: 'cohort-a', starts_on: '2026-10-01', ends_on: '2026-12-31', timezone: 'UTC', timetable: [] }
const activity = { id: 'activity-a', version: 'activity-version', label: 'Coaching partagé', starts_at: '2026-10-22T10:00:00+00:00', ends_at: '2026-10-22T11:00:00+00:00', timezone: 'UTC' }
const session: AcademySession = { id: 'session-a', cycle_id: cycle.id, title: 'Séance pilote', status: 'planned', version: 'session-version', responsible_enrollment_id: 'mentor-a', responsible_label: 'Coach', exercises: [], report: 'Compte rendu partagé', activity, starts_at: activity.starts_at, ends_at: activity.ends_at, schedule_state: 'current', attendance_count: 0 }
const attendance: AcademyAttendance = { id: 'attendance-a', enrollment_id: 'student-a', person_label: 'Camille', status: 'present', late_minutes: null, version: 'attendance-version' }
let rows: AcademySession[], attendanceRows: AcademyAttendance[]
function page(data: unknown[]) { return { data, meta: { request_id: 'test', current_page: 1, last_page: 1, total: data.length } } }
function show(value = active) { return render(<NotificationProvider><AcademySessionsPanel active={value} cohort={cohort} cycle={cycle} disabled={false} onInvalid={onInvalid} /></NotificationProvider>) }
async function open(user: ReturnType<typeof userEvent.setup>) { await user.click(screen.getByRole('button', { name: 'Séances, présences et exercices' })); await screen.findByText('Séance pilote') }
async function confirm(user: ReturnType<typeof userEvent.setup>) { await user.click(within(screen.getByRole('region', { name: 'Confirmer l’action' })).getByRole('button', { name: 'Confirmer et continuer' })) }
beforeEach(() => {
  request.mockReset(); onInvalid.mockReset(); rows = [session]; attendanceRows = []
  request.mockImplementation(async (path, options) => {
    if (options?.method) return { data: path.includes('/attendances') ? attendance : session, notification: { type: 'success', message: 'Suivi enregistré.' } }
    if (path.includes('/session-activities')) return page([activity])
    if (path.includes('/enrollments')) return page([{ id: 'mentor-a', person_label: 'Coach', role: 'mentor', status: 'active' }, { id: 'student-a', person_label: 'Camille', role: 'student', status: 'active' }])
    if (path.includes('/attendances')) return page(attendanceRows)
    if (path.includes('/history')) return page([{ id: 'history-a', kind: 'attendance', resource_id: 'attendance-a', actor_user_id: 42, recorded_at: '2026-10-22T12:00:00Z' }])
    return page(rows)
  })
})
describe('séances et présences Académie', () => {
  it('ne charge rien avant ouverture ni sans permission de lecture', () => {
    const view = show(); expect(request).not.toHaveBeenCalled(); view.unmount()
    show({ ...active, context: { ...active.context!, is_owner: true, permissions: ['organization.view'] } })
    expect(screen.queryByRole('button', { name: 'Séances, présences et exercices' })).not.toBeInTheDocument(); expect(request).not.toHaveBeenCalled()
  })
  it('le lecteur consulte le contenu mais ne peut gérer ni saisir les présences', async () => {
    const user = userEvent.setup(); show({ ...active, context: { ...active.context!, permissions: ['academy.read', 'calendar.read'] } }); await open(user)
    expect(screen.queryByRole('button', { name: 'Créer une séance' })).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Consulter les présences Séance pilote' }))
    expect(await screen.findByText('Aucune présence renseignée.')).toBeVisible()
    expect(screen.queryByRole('button', { name: 'Saisir une présence' })).not.toBeInTheDocument()
  })
  it('crée avec activité versionnée, mentor et exercices après confirmation du tenant', async () => {
    const user = userEvent.setup(); show(); await open(user)
    await user.click(screen.getByRole('button', { name: 'Créer une séance' }))
    const form = screen.getByRole('form', { name: 'Enregistrer la séance' })
    await user.type(within(form).getByLabelText('Titre de la séance'), 'Nouvelle séance')
    await screen.findByRole('option', { name: 'Coach' })
    await user.selectOptions(within(form).getByLabelText('Activité du calendrier'), 'activity-a')
    await user.selectOptions(within(form).getByLabelText('Mentor responsable'), 'mentor-a')
    await user.click(within(form).getByRole('button', { name: 'Ajouter un exercice' }))
    await user.type(within(form).getByLabelText('Nom de l’exercice'), 'Placement collectif')
    await user.click(within(form).getByRole('button', { name: 'Enregistrer la séance' }))
    expect(request.mock.calls.filter(([, options]) => options?.method)).toHaveLength(0)
    await confirm(user)
    await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/org-a/academy/cohorts/cohort-a/cycles/cycle-a/sessions', expect.objectContaining({ method: 'POST', revision: 'revision-a', body: { title: 'Nouvelle séance', activity_id: 'activity-a', activity_version: 'activity-version', responsible_enrollment_id: 'mentor-a', exercises: [{ title: 'Placement collectif', instructions: null, minutes: 20 }], report: null } })))
  })
  it('un gestionnaire de séances ne reçoit pas implicitement le droit de présence', async () => {
    const user = userEvent.setup(); show({ ...active, context: { ...active.context!, permissions: ['academy.read', 'academy.manage', 'calendar.read'] } }); await open(user)
    await user.click(screen.getByRole('button', { name: 'Consulter les présences Séance pilote' })); await screen.findByText('Aucune présence renseignée.')
    expect(screen.queryByRole('button', { name: 'Saisir une présence' })).not.toBeInTheDocument()
  })
  it('le droit de présence suffit sans droit de modifier les séances', async () => {
    const user = userEvent.setup(); show({ ...active, context: { ...active.context!, permissions: ['academy.read', 'academy.attendance.manage', 'calendar.read'] } }); await open(user)
    expect(screen.queryByRole('button', { name: 'Créer une séance' })).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Consulter les présences Séance pilote' }))
    await user.click(await screen.findByRole('button', { name: 'Saisir une présence' }))
    await screen.findByRole('option', { name: 'Camille' }); await user.selectOptions(screen.getByLabelText('Inscription concernée'), 'student-a')
    await user.click(screen.getByRole('button', { name: 'Enregistrer la présence' })); await confirm(user)
    await waitFor(() => expect(request).toHaveBeenCalledWith(expect.stringContaining('/session-a/attendances'), expect.objectContaining({ method: 'POST', body: { enrollment_id: 'student-a', status: 'present' } })))
  })
  it('corrige une présence avec version, retard et motif obligatoire', async () => {
    attendanceRows = [attendance]
    const user = userEvent.setup(); show(); await open(user)
    await user.click(screen.getByRole('button', { name: 'Consulter les présences Séance pilote' }))
    await user.click(await screen.findByRole('button', { name: 'Corriger la présence de Camille' }))
    await user.selectOptions(screen.getByLabelText('Statut de présence'), 'late')
    await user.clear(screen.getByLabelText('Retard (minutes)')); await user.type(screen.getByLabelText('Retard (minutes)'), '15')
    await user.type(screen.getByLabelText('Motif opérationnel de la modification'), 'Correction après vérification.')
    await user.click(screen.getByRole('button', { name: 'Enregistrer la présence' })); await confirm(user)
    await waitFor(() => expect(request).toHaveBeenCalledWith(expect.stringContaining('/attendance-a'), expect.objectContaining({ method: 'PATCH', body: { version: 'attendance-version', status: 'late', late_minutes: 15, change_reason: 'Correction après vérification.' } })))
  })
  it('bloque les commandes de présence quand le calendrier est périmé', async () => {
    rows = [{ ...session, schedule_state: 'changed' }]
    const user = userEvent.setup(); show(); await open(user)
    expect(screen.getByText('Le calendrier a changé. Confirmez les horaires avant une nouvelle saisie de présence.')).toBeVisible()
    await user.click(screen.getByRole('button', { name: 'Consulter les présences Séance pilote' })); await screen.findByText('Aucune présence renseignée.')
    expect(screen.queryByRole('button', { name: 'Saisir une présence' })).not.toBeInTheDocument()
  })
  it('annule avec motif et confirmation, sans mutation automatique', async () => {
    const user = userEvent.setup(); show(); await open(user)
    await user.click(screen.getByRole('button', { name: 'Annuler la séance Séance pilote' }))
    await user.type(screen.getByLabelText('Motif opérationnel de la modification'), 'Annulation du créneau confirmée.')
    await user.click(screen.getByRole('button', { name: 'Annuler la séance' })); await confirm(user)
    await waitFor(() => expect(request).toHaveBeenCalledWith(expect.stringContaining('/session-a'), expect.objectContaining({ method: 'PATCH', body: { version: 'session-version', status: 'cancelled', change_reason: 'Annulation du créneau confirmée.' } })))
  })
  it('échappe exercices et compte rendu sans exposer de HTML actif', async () => {
    rows = [{ ...session, exercises: [{ title: '<script>BAD</script>', instructions: '<img onerror=BAD>', minutes: 20 }], report: '<svg onload=BAD>' }]
    const user = userEvent.setup(); show(); await open(user)
    expect(screen.getByText(/<script>BAD/)).toBeVisible(); expect(screen.getByText('<svg onload=BAD>')).toBeVisible()
    expect(document.querySelector('script, img, svg')).toBeNull()
  })
  it('invalide le contexte après révocation et permet la reprise de lecture', async () => {
    request.mockRejectedValueOnce(new ApiClientError('Accès révoqué', { status: 403 }))
    const user = userEvent.setup(); show(); await user.click(screen.getByRole('button', { name: 'Séances, présences et exercices' }))
    await screen.findByText('Accès révoqué'); expect(onInvalid).toHaveBeenCalledOnce()
    await user.click(screen.getByRole('button', { name: 'Réessayer' })); expect(await screen.findByText('Séance pilote')).toBeVisible()
  })
})
