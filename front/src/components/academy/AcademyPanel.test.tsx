import { render, screen, within, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { tenancyRequest, type ActiveOrganization } from '../../api/tenancy'
import { ApiClientError } from '../../api/errors'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { AcademyPanel } from './AcademyPanel'
import { AcademyCycleForm } from './AcademyCycleForm'

vi.mock('../../api/tenancy', () => ({ tenancyRequest: vi.fn() }))
const request = vi.mocked(tenancyRequest)
const active: ActiveOrganization = { context: { organization_id: 'org-a', name: 'Alpha', slug: 'alpha', timezone: 'Europe/Paris', language: 'fr', roles: ['member'], permissions: ['academy.read', 'academy.manage'], is_owner: false, actor_user_id: 42, membership_id: 'member' }, revision: 'revision-a', confirmed: false }
const cohort = { id: 'cohort-a', name: 'Pilote', status: 'active', version: 'version-a' }
const cycle = { id: 'cycle-a', name: 'Automne', status: 'active', version: 'cycle-version', cohort_id: 'cohort-a', starts_on: '2026-10-01', ends_on: '2026-12-31', timezone: 'Europe/Paris', timetable: [] }
const onInvalid = vi.fn()
function page(data: unknown[]) { return { data, meta: { current_page: 1, last_page: 1, total: data.length, request_id: 'list' } } }
function show(value = active) { return render(<NotificationProvider><AcademyPanel active={value} disabled={false} onInvalid={onInvalid} /></NotificationProvider>) }
async function open(user: ReturnType<typeof userEvent.setup>) { await user.click(screen.getByRole('button', { name: 'Académie : cohortes et cycles' })); await screen.findByText('Pilote') }
async function confirm(user: ReturnType<typeof userEvent.setup>) { await user.click(within(screen.getByRole('region', { name: 'Confirmer l’action' })).getByRole('button', { name: 'Confirmer et continuer' })) }
beforeEach(() => {
  request.mockReset(); onInvalid.mockReset()
  request.mockImplementation(async (path, options) => {
    if (options?.method) return { data: cohort, notification: { type: 'success', message: 'Académie mise à jour.' } }
    if (path.includes('/enrollments')) return page([])
    if (path.includes('/people')) return page([{ id: 'person-a', label: 'Camille' }])
    if (path.includes('/cycles')) return page([cycle])
    if (path.includes('/history')) return page([])
    return page([cohort])
  })
})
describe('académie privée', () => {
  it('ne charge rien sans droit explicite ni avant ouverture', async () => {
    const view = show({ ...active, context: { ...active.context!, is_owner: true, permissions: ['organization.view'] } })
    expect(screen.queryByRole('button', { name: 'Académie : cohortes et cycles' })).not.toBeInTheDocument()
    expect(request).not.toHaveBeenCalled(); view.unmount()
    show(); expect(request).not.toHaveBeenCalled()
  })
  it('affiche le lecteur sans commandes de gestion ni accès aux choix de personnes', async () => {
    const user = userEvent.setup(); show({ ...active, context: { ...active.context!, permissions: ['academy.read'] } }); await open(user)
    expect(screen.queryByRole('button', { name: 'Créer une cohorte' })).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Consulter Pilote' })); await screen.findByText('Automne')
    expect(screen.queryByRole('button', { name: 'Créer un cycle' })).not.toBeInTheDocument()
    expect(request.mock.calls.some(([path]) => path.includes('/people'))).toBe(false)
  })
  it('crée après confirmation de l’organisation avec révision de session', async () => {
    const user = userEvent.setup(); show(); await open(user)
    await user.click(screen.getByRole('button', { name: 'Créer une cohorte' }))
    await user.type(screen.getByLabelText('Nom'), 'Nouvelle cohorte')
    await user.click(screen.getByRole('button', { name: 'Enregistrer la cohorte' }))
    expect(request.mock.calls.filter(([, options]) => options?.method)).toHaveLength(0)
    await confirm(user)
    await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/org-a/academy/cohorts', expect.objectContaining({ method: 'POST', body: { name: 'Nouvelle cohorte' }, revision: 'revision-a' })))
    expect(request).toHaveBeenCalledWith('active-organization/confirmation', expect.objectContaining({ body: { organization_id: 'org-a', revision: 'revision-a', confirm: true } }))
  })
  it('archive avec version et confirmation explicite', async () => {
    const user = userEvent.setup(); show(); await open(user)
    await user.click(screen.getByRole('button', { name: 'Archiver Pilote' })); await confirm(user)
    await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/org-a/academy/cohorts/cohort-a', expect.objectContaining({ method: 'PATCH', body: { status: 'archived', version: 'version-a' } })))
  })
  it('prépare une inscription dans le cycle sélectionné sans donner de permissions', async () => {
    const user = userEvent.setup(); show(); await open(user)
    await user.click(screen.getByRole('button', { name: 'Consulter Pilote' })); await screen.findByText('Automne')
    await user.click(screen.getByRole('button', { name: 'Inscriptions et mentors Automne' }))
    await user.click(await screen.findByRole('button', { name: 'Ajouter une inscription ou un mentor' }))
    await screen.findByRole('option', { name: 'Camille' })
    await user.selectOptions(screen.getByLabelText('Personne'), 'person-a')
    await user.selectOptions(screen.getByLabelText('Rôle dans le cycle'), 'mentor')
    await user.click(screen.getByRole('button', { name: 'Enregistrer l’affectation' })); await confirm(user)
    await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/org-a/academy/cohorts/cohort-a/enrollments', expect.objectContaining({ body: { cycle_id: 'cycle-a', person_id: 'person-a', role: 'mentor', starts_on: '2026-10-01', ends_on: '2026-12-31' } })))
  })
  it('permet de composer plusieurs créneaux hebdomadaires sans JSON libre', async () => {
    const submit = vi.fn((event) => event.preventDefault()), user = userEvent.setup()
    render(<AcademyCycleForm cycle={null} timezone="Europe/Paris" disabled={false} errors={{}} onCancel={vi.fn()} onSubmit={submit} />)
    await user.type(screen.getByLabelText('Nom'), 'Automne')
    await user.type(screen.getByLabelText('Date de début'), '2026-10-01'); await user.type(screen.getByLabelText('Date de fin'), '2026-12-31')
    await user.click(screen.getByRole('button', { name: 'Ajouter un créneau' }))
    await user.click(screen.getByRole('button', { name: 'Enregistrer le cycle' }))
    expect(submit).toHaveBeenCalledWith(expect.anything(), expect.objectContaining({ timetable: [{ day: 1, starts_at: '18:00', ends_at: '20:00' }] }))
  })
  it('échappe les libellés reçus du serveur', async () => {
    request.mockResolvedValue(page([{ ...cohort, name: '<script>BAD</script>' }]))
    const user = userEvent.setup(); show(); await user.click(screen.getByRole('button', { name: 'Académie : cohortes et cycles' }))
    expect(await screen.findByText('<script>BAD</script>')).toBeVisible(); expect(document.querySelector('script')).toBeNull()
  })
  it('invalide le contexte après refus serveur et permet de réessayer', async () => {
    request.mockRejectedValueOnce(new ApiClientError('Accès révoqué', { status: 403 }))
    const user = userEvent.setup(); show(); await user.click(screen.getByRole('button', { name: 'Académie : cohortes et cycles' }))
    await screen.findByText('Accès révoqué'); expect(onInvalid).toHaveBeenCalledOnce()
    await user.click(screen.getByRole('button', { name: 'Réessayer' })); expect(await screen.findByText('Pilote')).toBeVisible()
  })
})
