import { beforeEach, describe, expect, it, vi } from 'vitest'
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ActiveOrganization } from '../../api/tenancy'
import { ApiClientError } from '../../api/errors'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { ContextDiscussion } from './ContextDiscussion'

const { request, invalid } = vi.hoisted(() => ({ request: vi.fn(), invalid: vi.fn() }))
vi.mock('../../api/tenancy', async (original) => ({ ...await original<typeof import('../../api/tenancy')>(), tenancyRequest: request }))
const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222'
const version = '33333333-3333-4333-8333-333333333333', revision = '44444444-4444-4444-8444-444444444444'
const active: ActiveOrganization = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', language: 'fr', timezone: 'UTC', roles: ['member'],
  permissions: ['communication.read', 'communication.comment', 'calendar.read', 'sports.read', 'academy.read'], actor_user_id: 42, is_owner: false, membership_id: id }, revision, confirmed: false }
const response = () => ({ data: [{ id, announcement_id: id, body: '<script>Réponse</script>', is_mine: false, created_at: '2026-11-05T10:00:00Z' }],
  meta: { current_page: 1, last_page: 1, total: 1 }, context: { id, type: 'event', label: '<img src=x>', version, thread_version: null, can_comment: true } })
function show(type: 'event' | 'match' | 'session' = 'event', selected = active) {
  return render(<NotificationProvider><ContextDiscussion active={selected} type={type} id={id} disabled={false} onInvalid={invalid} /></NotificationProvider>)
}
beforeEach(() => {
  request.mockReset(); invalid.mockReset()
  request.mockImplementation(async (_path: string, options?: { method?: string }) => options?.method ? { data: { id }, notification: { type: 'success', message: 'Enregistré' } } : response())
})

describe('discussion de contexte', () => {
  it('ne charge rien avant ouverture et exige la permission du module', () => {
    const first = show(); expect(request).not.toHaveBeenCalled(); first.unmount()
    show('event', { ...active, context: { ...active.context!, permissions: ['communication.read', 'communication.comment'] } })
    expect(screen.queryByRole('button', { name: 'Ouvrir la discussion' })).not.toBeInTheDocument()
    expect(request).not.toHaveBeenCalled()
  })
  it.each(['event', 'match', 'session'] as const)('charge les commentaires du contexte %s et échappe son contenu', async (type) => {
    const user = userEvent.setup(); const { container } = show(type)
    await user.click(screen.getByRole('button', { name: 'Ouvrir la discussion' }))
    await screen.findByText('<script>Réponse</script>')
    expect(request).toHaveBeenCalledWith('tenants/' + org + '/communication/discussions/' + type + '/' + id + '?per_page=10&page=1', expect.objectContaining({ revision }))
    expect(container.querySelector('script,img,iframe')).toBeNull()
  })
  it('confirme avant envoi et transmet les versions du contexte et du fil', async () => {
    const user = userEvent.setup(); show()
    await user.click(screen.getByRole('button', { name: 'Ouvrir la discussion' }))
    await screen.findByText('<script>Réponse</script>')
    await user.type(screen.getByLabelText('Message'), 'Mon commentaire')
    await user.click(screen.getByLabelText('Notifier en interne l’auteur et les participants qui ont activé les réponses'))
    await user.click(screen.getByRole('button', { name: 'Ajouter le commentaire' }))
    expect(request.mock.calls.some(([, options]) => options?.method === 'POST')).toBe(false)
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/' + org + '/communication/discussions/event/' + id, expect.objectContaining({ method: 'POST', revision,
      body: { body: 'Mon commentaire', notify_members: true, version, thread_version: null } })))
    await waitFor(() => expect(screen.getByLabelText('Message')).toHaveValue(''))
  })
  it('préserve le brouillon pendant une actualisation et bloque la soumission tant que la lecture est en cours', async () => {
    const user = userEvent.setup(); show()
    await user.click(screen.getByRole('button', { name: 'Ouvrir la discussion' }))
    await screen.findByText('<script>Réponse</script>')
    await user.type(screen.getByLabelText('Message'), 'Texte conservé')
    let resolveRead!: (value: ReturnType<typeof response>) => void
    request.mockImplementationOnce(() => new Promise((resolve) => { resolveRead = resolve }))
    await user.click(screen.getByRole('button', { name: 'Actualiser les communications' }))
    expect(screen.getByLabelText('Message')).toHaveValue('Texte conservé')
    expect(screen.getByRole('button', { name: 'Ajouter le commentaire' })).toBeDisabled()
    fireEvent.submit(screen.getByRole('form', { name: 'Ajouter le commentaire' }))
    expect(screen.queryByRole('button', { name: 'Confirmer et continuer' })).not.toBeInTheDocument()
    await act(async () => { resolveRead(response()) })
    expect(screen.getByLabelText('Message')).toHaveValue('Texte conservé')
  })
  it('retire les commentaires après un refus de lecture et ne propose aucun envoi', async () => {
    const user = userEvent.setup(); show()
    await user.click(screen.getByRole('button', { name: 'Ouvrir la discussion' }))
    await screen.findByText('<script>Réponse</script>')
    request.mockRejectedValueOnce(new ApiClientError('Élément inaccessible', { status: 404, code: 'NOT_FOUND' }))
    await user.click(screen.getByRole('button', { name: 'Actualiser les communications' }))
    await screen.findByText('Élément inaccessible')
    expect(screen.queryByText('<script>Réponse</script>')).not.toBeInTheDocument()
    expect(screen.queryByRole('form', { name: 'Ajouter le commentaire' })).not.toBeInTheDocument()
    expect(invalid).toHaveBeenCalledOnce()
  })
})
