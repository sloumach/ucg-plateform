import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { tenancyRequest, type ActiveOrganization } from '../../api/tenancy'
import { ApiClientError } from '../../api/errors'
import type { AcademyObjective, AcademyFeedback } from '../../api/academyCoaching'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { AcademyObjectivesPanel } from './AcademyObjectivesPanel'
import { AcademyPersonalPanel } from './AcademyPersonalPanel'
import { AcademyFeedbackPanel } from './AcademyFeedbackPanel'

vi.mock('../../api/tenancy', () => ({ tenancyRequest: vi.fn() }))
const request = vi.mocked(tenancyRequest), onInvalid = vi.fn()
const active: ActiveOrganization = { context: { organization_id: 'org', name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'], permissions: ['academy.read', 'academy.manage', 'academy.feedback.manage', 'academy.notes.manage'], is_owner: false, actor_user_id: 42, membership_id: 'membership' }, revision: 'revision-a', confirmed: false }
const cohort = { id: 'cohort', name: 'Pilote', status: 'active' as const, version: 'v' }
const cycle = { id: 'cycle', name: 'Automne', status: 'active' as const, version: 'v', cohort_id: 'cohort', starts_on: '2026-10-01', ends_on: '2026-12-31', timezone: 'UTC', timetable: [] }
const objective: AcademyObjective = { id: 'goal', title: 'Placement collectif', description: 'Progresser ensemble', due_on: '2026-11-01', status: 'active', scope: 'collective', version: 'goal-v', target_enrollment_id: null, responsible_enrollment_id: 'mentor', responsible_label: 'Coach' }
const feedback: AcademyFeedback = { id: 'feedback', content: 'Bonne communication', created_at: '2026-10-22T12:00:00Z', version: 'feedback-v', status: 'active', audience: 'shared' }
let goals: AcademyObjective[], feedbackRows: AcademyFeedback[]
function page(data: unknown[]) { return { data, meta: { request_id: 'request', current_page: 1, last_page: 1, total: data.length } } }
function rights(permissions: string[]): ActiveOrganization { return { ...active, context: { ...active.context!, permissions } } }
function show(value = active) { return render(<NotificationProvider><AcademyObjectivesPanel active={value} cohort={cohort} cycle={cycle} disabled={false} onInvalid={onInvalid} /></NotificationProvider>) }
async function open(user: ReturnType<typeof userEvent.setup>) { await user.click(screen.getByRole('button', { name: 'Objectifs et feedback' })); await screen.findByText('Placement collectif') }
async function confirm(user: ReturnType<typeof userEvent.setup>) { await user.click(within(screen.getByRole('region', { name: 'Confirmer l’action' })).getByRole('button', { name: 'Confirmer et continuer' })) }
beforeEach(() => {
  request.mockReset(); onInvalid.mockReset(); goals = [objective]; feedbackRows = [feedback]
  request.mockImplementation(async (path, options) => {
    if (options?.method) return { data: path.endsWith('/objectives') ? objective : feedback, notification: { type: 'success', message: 'Suivi enregistré.' } }
    if (path.includes('/enrollments')) return page([{ id: 'mentor', person_label: 'Coach', role: 'mentor', status: 'active' }, { id: 'student', person_label: 'Camille', role: 'student', status: 'active' }])
    if (path.includes('/history')) return page([{ id: 'revision', kind: 'objective', resource_id: 'goal', actor_user_id: 42, recorded_at: '2026-10-22' }])
    if (path.includes('/feedback') || path.includes('/notes')) return page(feedbackRows)
    return page(goals)
  })
})

describe('objectifs et confidentialité du feedback', () => {
  it('reste inactif avant ouverture et ne donne pas l’accès staff au lecteur personnel', () => {
    const view = show(); expect(request).not.toHaveBeenCalled(); view.unmount()
    show(rights(['academy.self.read'])); expect(screen.queryByRole('button', { name: 'Objectifs et feedback' })).not.toBeInTheDocument(); expect(request).not.toHaveBeenCalled()
  })
  it('ne donne pas au gestionnaire d’objectifs les permissions de feedback et de notes', async () => {
    const user = userEvent.setup(); show(rights(['academy.read', 'academy.manage'])); await open(user)
    expect(screen.queryByRole('button', { name: /Notes internes/ })).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Feedback partagé Placement collectif' })); await screen.findByText('Bonne communication')
    expect(screen.queryByRole('button', { name: 'Publier le feedback partagé' })).not.toBeInTheDocument()
    expect(request.mock.calls.some(([path]) => path.includes('/notes'))).toBe(false)
  })
  it('crée un objectif individuel avec échéance et mentor après confirmation', async () => {
    const user = userEvent.setup(); show(); await open(user); await user.click(screen.getByRole('button', { name: 'Créer un objectif' }))
    const form = screen.getByRole('form', { name: 'Enregistrer l’objectif' }); await screen.findByRole('option', { name: 'Coach' })
    await user.type(within(form).getByLabelText('Titre de l’objectif'), 'Travail personnel')
    await user.selectOptions(within(form).getByLabelText('Mentor responsable'), 'mentor')
    await user.selectOptions(within(form).getByLabelText('Destinataire de l’objectif'), 'student')
    await user.click(within(form).getByRole('button', { name: 'Enregistrer l’objectif' }))
    expect(request.mock.calls.filter(([, options]) => options?.method)).toHaveLength(0); await confirm(user)
    await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/org/academy/cohorts/cohort/cycles/cycle/objectives', expect.objectContaining({ method: 'POST', revision: 'revision-a', body: { title: 'Travail personnel', description: null, due_on: '2026-12-31', responsible_enrollment_id: 'mentor', target_enrollment_id: 'student' } })))
  })
  it('clôture avec une commande limitée au statut et au motif', async () => {
    const user = userEvent.setup(); show(); await open(user); await user.click(screen.getByRole('button', { name: 'Modifier l’objectif Placement collectif' }))
    const form = screen.getByRole('form', { name: 'Enregistrer l’objectif' }); await screen.findByRole('option', { name: 'Coach' })
    await user.selectOptions(within(form).getByLabelText('Statut'), 'completed'); await user.type(within(form).getByLabelText('Motif de la correction ou du retrait'), 'Objectif atteint et confirmé.')
    await user.click(within(form).getByRole('button', { name: 'Enregistrer l’objectif' })); await confirm(user)
    await waitFor(() => expect(request).toHaveBeenCalledWith(expect.stringContaining('/objectives/goal'), expect.objectContaining({ method: 'PATCH', body: { version: 'goal-v', status: 'completed', change_reason: 'Objectif atteint et confirmé.' } })))
  })
  it('conserve le mentor sélectionné quand le destinataire est sur une autre page', async () => {
    const original = request.getMockImplementation()!
    request.mockImplementation(async (path, options) => {
      if (!options?.method && path.includes('/enrollments')) {
        const second = path.includes('page=2&')
        return { data: second ? [{ id: 'student', person_label: 'Camille', role: 'student', status: 'active' }] : [{ id: 'mentor', person_label: 'Coach', role: 'mentor', status: 'active' }], meta: { request_id: 'request', current_page: second ? 2 : 1, last_page: 2, total: 11 } }
      }
      return original(path, options)
    })
    const user = userEvent.setup(); show(); await open(user); await user.click(screen.getByRole('button', { name: 'Créer un objectif' }))
    const form = screen.getByRole('form', { name: 'Enregistrer l’objectif' }); await screen.findByRole('option', { name: 'Coach' })
    await user.type(within(form).getByLabelText('Titre de l’objectif'), 'Objectif individuel')
    await user.selectOptions(within(form).getByLabelText('Mentor responsable'), 'mentor')
    await user.click(within(form).getByRole('button', { name: 'Page suivante' })); await screen.findByRole('option', { name: 'Camille' })
    expect(within(form).getByLabelText('Mentor responsable')).toHaveValue('mentor')
    await user.selectOptions(within(form).getByLabelText('Destinataire de l’objectif'), 'student')
    await user.click(within(form).getByRole('button', { name: 'Enregistrer l’objectif' })); await confirm(user)
    await waitFor(() => expect(request).toHaveBeenCalledWith(expect.stringContaining('/objectives'), expect.objectContaining({ method: 'POST', body: expect.objectContaining({ responsible_enrollment_id: 'mentor', target_enrollment_id: 'student' }) })))
  })
  it('le rédacteur de feedback ne gère pas les objectifs et publie explicitement', async () => {
    const user = userEvent.setup(); show(rights(['academy.read', 'academy.feedback.manage'])); await open(user)
    expect(screen.queryByRole('button', { name: 'Créer un objectif' })).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Feedback partagé Placement collectif' })); await user.click(await screen.findByRole('button', { name: 'Publier le feedback partagé' }))
    const form = screen.getByRole('form', { name: 'Publier le feedback partagé' }); await user.type(within(form).getByLabelText('Contenu pédagogique'), 'Retour publié après échange.')
    await user.click(within(form).getByRole('button', { name: 'Publier le feedback partagé' })); await confirm(user)
    await waitFor(() => expect(request).toHaveBeenCalledWith(expect.stringContaining('/goal/feedback'), expect.objectContaining({ method: 'POST', body: { content: 'Retour publié après échange.' } })))
  })
  it('enregistre une note justifiée dans le parcours interne distinct', async () => {
    feedbackRows = []
    const user = userEvent.setup(); show(rights(['academy.read', 'academy.notes.manage'])); await open(user)
    await user.click(screen.getByRole('button', { name: 'Notes internes Placement collectif' })); await user.click(await screen.findByRole('button', { name: 'Enregistrer la note interne' }))
    const form = screen.getByRole('form', { name: 'Enregistrer la note interne' }); await user.type(within(form).getByLabelText('Contenu pédagogique'), 'Préparer un exercice adapté.')
    await user.type(within(form).getByLabelText('Justification de la note interne'), 'Préparation du prochain entretien.')
    await user.click(within(form).getByRole('button', { name: 'Enregistrer la note interne' })); await confirm(user)
    await waitFor(() => expect(request).toHaveBeenCalledWith(expect.stringContaining('/goal/notes'), expect.objectContaining({ method: 'POST', body: { content: 'Préparer un exercice adapté.', justification: 'Préparation du prochain entretien.' } })))
  })
  it('un panneau interne forcé reste fermé sans sa permission', () => {
    render(<NotificationProvider><AcademyFeedbackPanel active={rights(['academy.read'])} base="notes" title="Privé" mode="internal" writable disabled={false} onInvalid={onInvalid} /></NotificationProvider>)
    expect(request).not.toHaveBeenCalled(); expect(screen.queryByText('Privé')).not.toBeInTheDocument()
  })
  it('corrige ou retire un feedback avec sa version, son motif et une confirmation explicite', async () => {
    const user = userEvent.setup(); show(); await open(user)
    await user.click(screen.getByRole('button', { name: 'Feedback partagé Placement collectif' }))
    await user.click(await screen.findByRole('button', { name: 'Corriger le retour' }))
    const form = screen.getByRole('form', { name: 'Enregistrer la correction' })
    await user.selectOptions(within(form).getByLabelText('Statut'), 'withdrawn')
    await user.type(within(form).getByLabelText('Motif de la correction ou du retrait'), 'Retrait après vérification.')
    await user.click(within(form).getByRole('button', { name: 'Enregistrer la correction' }))
    expect(screen.getByRole('region', { name: 'Confirmer l’action' })).toHaveTextContent('Retirer le retour')
    expect(request.mock.calls.filter(([, options]) => options?.method)).toHaveLength(0); await confirm(user)
    await waitFor(() => expect(request).toHaveBeenCalledWith(expect.stringContaining('/goal/feedback/feedback'), expect.objectContaining({ method: 'PATCH', body: { content: 'Bonne communication', version: 'feedback-v', status: 'withdrawn', change_reason: 'Retrait après vérification.' } })))
  })
  it('le joueur consulte seulement son parcours personnel sans commandes staff', async () => {
    const user = userEvent.setup(); render(<NotificationProvider><AcademyPersonalPanel active={rights(['academy.self.read'])} disabled={false} onInvalid={onInvalid} /></NotificationProvider>)
    await user.click(screen.getByRole('button', { name: 'Mes objectifs et retours' })); await screen.findByText('Placement collectif')
    await user.click(screen.getByRole('button', { name: 'Feedback partagé Placement collectif' })); await screen.findByText('Bonne communication')
    expect(screen.queryByRole('button', { name: /Notes internes|Publier|Modifier|Historique/ })).not.toBeInTheDocument()
    expect(request.mock.calls.every(([path, options]) => path.startsWith('tenants/org/academy/my-objectives') && !options?.method)).toBe(true)
  })
  it('échappe le contenu pédagogique et retire immédiatement l’interface après révocation', async () => {
    feedbackRows = [{ ...feedback, content: '<svg onload=BAD>' }]
    const user = userEvent.setup(); const view = show(); await open(user); await user.click(screen.getByRole('button', { name: 'Feedback partagé Placement collectif' }))
    expect(await screen.findByText('<svg onload=BAD>')).toBeVisible(); expect(document.querySelector('svg')).toBeNull()
    view.rerender(<NotificationProvider><AcademyObjectivesPanel active={rights(['academy.self.read'])} cohort={cohort} cycle={cycle} disabled={false} onInvalid={onInvalid} /></NotificationProvider>)
    expect(screen.queryByText('<svg onload=BAD>')).not.toBeInTheDocument()
  })
  it('invalide le contexte après refus de lecture et propose une reprise', async () => {
    request.mockRejectedValueOnce(new ApiClientError('Accès révoqué', { status: 403 }))
    const user = userEvent.setup(); show(); await user.click(screen.getByRole('button', { name: 'Objectifs et feedback' }))
    await screen.findByText('Accès révoqué'); expect(onInvalid).toHaveBeenCalledOnce(); await user.click(screen.getByRole('button', { name: 'Réessayer' })); await screen.findByText('Placement collectif')
  })
})
