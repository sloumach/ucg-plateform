import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ActiveOrganization } from '../../api/tenancy'
import type { WorkTask } from '../../api/communication'
import { ApiClientError } from '../../api/errors'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { WorkTasksPanel } from './WorkTasksPanel'

const { request, invalid } = vi.hoisted(() => ({ request: vi.fn(), invalid: vi.fn() }))
vi.mock('../../api/tenancy', async (original) => ({ ...await original<typeof import('../../api/tenancy')>(), tenancyRequest: request }))
const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222', version = '33333333-3333-4333-8333-333333333333'
const active: ActiveOrganization = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', language: 'fr', timezone: 'Pacific/Honolulu', roles: ['member'],
  permissions: ['tasks.read', 'tasks.manage', 'communication.read', 'communication.comment'], actor_user_id: 42, is_owner: false, membership_id: id }, revision: version, confirmed: false }
const task: WorkTask = { id, version, title: '<script>Dossier</script>', description: '<img src=x>', status: 'todo', due_on: '2026-12-15', assignee_id: id, assignee_label: 'Camille' }
const page = (data: unknown[]) => ({ data, meta: { current_page: 1, last_page: 1, total: data.length } })
function show(permissions = active.context!.permissions) {
  return render(<NotificationProvider><WorkTasksPanel active={{ ...active, context: { ...active.context!, permissions } }} disabled={false} onInvalid={invalid} /></NotificationProvider>)
}
beforeEach(() => {
  request.mockReset(); invalid.mockReset()
  request.mockImplementation(async (path: string, options?: { method?: string }) => options?.method ? { data: task, notification: { type: 'success', message: 'Tâche enregistrée' } }
    : path.includes('/people?') ? page([{ id: version, label: 'Alex' }]) : page([task]))
})
describe('tâches internes', () => {
  it('ne charge rien avant ouverture et masque le panneau sans droit de lecture', () => {
    const first = show(); expect(request).not.toHaveBeenCalled(); first.unmount()
    show(['tasks.manage'])
    expect(screen.queryByRole('region', { name: 'Tâches internes' })).not.toBeInTheDocument()
    expect(request).not.toHaveBeenCalled()
  })
  it('affiche les tâches en lecture seule, échappe les textes et conserve le jour de l’échéance', async () => {
    const user = userEvent.setup(); const { container } = show(['tasks.read'])
    await user.click(screen.getByRole('button', { name: 'Ouvrir les tâches' }))
    await screen.findByText(task.title)
    expect(screen.getByText(task.description!)).toBeInTheDocument()
    expect(container.querySelector('script,img,iframe')).toBeNull()
    expect(container.querySelector('time')).toHaveTextContent('15 déc. 2026')
    expect(screen.queryByRole('button', { name: 'Créer une tâche' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Modifier la tâche' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Ouvrir la discussion' })).not.toBeInTheDocument()
  })
  it('crée une tâche uniquement après confirmation dans l’organisation active', async () => {
    const user = userEvent.setup(); show()
    await user.click(screen.getByRole('button', { name: 'Ouvrir les tâches' })); await screen.findByText(task.title)
    await user.click(screen.getByRole('button', { name: 'Créer une tâche' }))
    const form = within(screen.getByRole('form', { name: 'Créer une tâche' }))
    await form.findByRole('option', { name: 'Alex' })
    await user.type(form.getByLabelText('Titre de la tâche'), 'Préparer inscription')
    await user.selectOptions(form.getByLabelText('Responsable'), version)
    await user.selectOptions(form.getByLabelText('Statut de la tâche'), 'in_progress')
    await user.click(form.getByRole('button', { name: 'Enregistrer la tâche' }))
    expect(request.mock.calls.some(([path, options]) => path.endsWith('/tasks') && options?.method)).toBe(false)
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/' + org + '/tasks', expect.objectContaining({ method: 'POST', revision: version,
      body: { title: 'Préparer inscription', description: null, assignee_id: version, due_on: null, status: 'in_progress' } })))
    await waitFor(() => expect(screen.queryByRole('form', { name: 'Créer une tâche' })).not.toBeInTheDocument())
  })
  it('préserve le responsable hors page et transmet seulement le statut modifié et la version', async () => {
    const user = userEvent.setup(); show()
    await user.click(screen.getByRole('button', { name: 'Ouvrir les tâches' })); await screen.findByText(task.title)
    await user.click(screen.getByRole('button', { name: 'Modifier la tâche' }))
    const form = within(screen.getByRole('form', { name: 'Modifier la tâche' }))
    await form.findByRole('option', { name: 'Alex' })
    expect(form.getByLabelText('Responsable')).toHaveValue(id)
    await user.selectOptions(form.getByLabelText('Statut de la tâche'), 'done')
    await user.click(form.getByRole('button', { name: 'Enregistrer la tâche' })); await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/' + org + '/tasks/' + id, expect.objectContaining({ method: 'PATCH', body: { status: 'done', version } })))
  })
  it('garde les valeurs saisies après validation refusée et montre le message du serveur', async () => {
    const user = userEvent.setup(); show()
    await user.click(screen.getByRole('button', { name: 'Ouvrir les tâches' })); await screen.findByText(task.title)
    await user.click(screen.getByRole('button', { name: 'Créer une tâche' }))
    await user.type(screen.getByLabelText('Titre de la tâche'), 'Mon brouillon')
    request.mockImplementation(async (path: string, options?: { method?: string }) => {
      if (path.endsWith('/tasks') && options?.method === 'POST') throw new ApiClientError('Titre refusé', { status: 422, code: 'VALIDATION_FAILED', fieldErrors: { title: ['Choisissez un autre titre.'] } })
      return options?.method ? { data: {} } : page([])
    })
    await user.click(screen.getByRole('button', { name: 'Enregistrer la tâche' })); await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await screen.findByText('Choisissez un autre titre.')
    expect(screen.getByLabelText('Titre de la tâche')).toHaveValue('Mon brouillon')
    expect(invalid).not.toHaveBeenCalled()
  })
  it('retire les tâches après refus de lecture et invalide le contexte', async () => {
    const user = userEvent.setup(); show()
    await user.click(screen.getByRole('button', { name: 'Ouvrir les tâches' })); await screen.findByText(task.title)
    request.mockRejectedValueOnce(new ApiClientError('Accès retiré', { status: 403, code: 'FORBIDDEN' }))
    await user.click(screen.getByRole('button', { name: 'Actualiser les tâches' }))
    await screen.findByText('Accès retiré')
    expect(screen.queryByText(task.title)).not.toBeInTheDocument()
    expect(invalid).toHaveBeenCalledOnce()
  })
})
