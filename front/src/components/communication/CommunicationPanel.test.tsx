import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ActiveOrganization } from '../../api/tenancy'
import type { Announcement } from '../../api/communication'
import { ApiClientError } from '../../api/errors'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { CommunicationPanel } from './CommunicationPanel'
const { request, invalid } = vi.hoisted(() => ({ request: vi.fn(), invalid: vi.fn() }))
vi.mock('../../api/tenancy', async (original) => ({ ...await original<typeof import('../../api/tenancy')>(), tenancyRequest: request }))
const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222', version = '33333333-3333-4333-8333-333333333333'
const root = 'tenants/' + org + '/communication'
const active: ActiveOrganization = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', language: 'fr', timezone: 'UTC', roles: ['member'],
  permissions: ['communication.read', 'communication.write', 'communication.publish', 'communication.comment'], actor_user_id: 42, is_owner: false, membership_id: id }, revision: version, confirmed: false }
const base: Announcement = { id, title: 'Annonce pilote', body: 'Message interne', target_type: 'organization', target_id: null, status: 'published', comments_enabled: true,
  can_comment: true, published_at: '2026-10-16T12:00:00+00:00', expires_at: null, withdrawn_at: null, version }
let rows: Announcement[]
const page = <T,>(data: T[]) => ({ data, meta: { current_page: 1, last_page: 1, total: data.length } })
function show(selection = active) { return render(<NotificationProvider><CommunicationPanel active={selection} disabled={false} onInvalid={invalid} /></NotificationProvider>) }
async function open(user: ReturnType<typeof userEvent.setup>) {
  await user.click(screen.getByRole('button', { name: 'Ouvrir les communications' }))
  await screen.findByRole('heading', { name: rows[0]?.title ?? 'Annonces et discussions' })
}
beforeEach(() => {
  vi.resetAllMocks(); rows = [{ ...base }]
  request.mockImplementation(async (path, options) => {
    if (path === 'active-organization/confirmation') return { data: { ...active, confirmed: true } }
    if (options?.method) return { data: { ...base, ...options.body } }
    if (path.includes('/targets?')) return page([{ id, label: '<script>Équipe</script>' }])
    if (path.endsWith('/preferences')) return { data: { version: null, announcements_enabled: true, comments_enabled: false } }
    if (path.includes('/inbox?')) return page([{ id, announcement_id: id, comment_id: null, title: 'Annonce pilote', kind: 'announcement', read: false, created_at: base.published_at }])
    if (path.includes('/comments?')) return page([{ id, announcement_id: id, body: '<img src=x>', is_mine: false, created_at: base.published_at }])
    if (path === root + '/announcements/' + id) return { data: { ...base } }
    return page(rows)
  })
})
describe('communications ciblées', () => {
  it('ne charge rien avant ouverture ou sans permission', () => {
    const { unmount } = show(); expect(request).not.toHaveBeenCalled(); unmount()
    show({ ...active, context: { ...active.context!, permissions: ['media.read'] } })
    expect(screen.queryByRole('heading', { name: 'Annonces et discussions' })).not.toBeInTheDocument()
    expect(request).not.toHaveBeenCalled()
  })
  it('échappe les titres messages et commentaires', async () => {
    rows = [{ ...base, title: '<script>alert(1)</script>', body: '<img src=x onerror=alert(1)>' }]
    const user = userEvent.setup(); const { container } = show(); await open(user)
    expect(screen.getByText(rows[0].body)).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Commentaires' }))
    await screen.findByText('<img src=x>')
    expect(container.querySelector('script,iframe,img')).toBeNull()
  })
  it('crée un brouillon ciblé après confirmation avec booléens et révision', async () => {
    const user = userEvent.setup(); show(); await open(user)
    await user.click(screen.getByRole('button', { name: 'Gérer les annonces' }))
    await user.click(screen.getByRole('button', { name: 'Nouvelle annonce' }))
    const form = within(screen.getByRole('form', { name: 'Enregistrer le brouillon' }))
    await user.type(form.getByLabelText('Titre de l’annonce'), 'Annonce d’équipe')
    await user.type(form.getByLabelText('Message'), 'Bonjour')
    await user.selectOptions(form.getByLabelText('Type de destinataires'), 'team')
    await form.findByRole('option', { name: '<script>Équipe</script>' })
    await user.selectOptions(form.getByLabelText('Destinataire', { exact: true }), id)
    await user.click(form.getByRole('button', { name: 'Enregistrer le brouillon' }))
    expect(request.mock.calls.some(([, options]) => options?.method === 'POST')).toBe(false)
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith(root + '/announcements', expect.objectContaining({ method: 'POST', revision: version,
      body: { title: 'Annonce d’équipe', body: 'Bonjour', target_type: 'team', target_id: id, comments_enabled: true, expires_at: null } })))
    expect(request).toHaveBeenCalledWith('active-organization/confirmation', expect.anything())
  })
  it('ne publie pas sans confirmation explicite et transmet la version du brouillon', async () => {
    rows = [{ ...base, status: 'draft', can_comment: false, published_at: null }]
    const user = userEvent.setup(); show(); await open(user)
    await user.click(screen.getByRole('button', { name: 'Gérer les annonces' }))
    const form = within(await screen.findByRole('form', { name: 'Publier l’annonce' }))
    expect(form.getByRole('button', { name: 'Publier l’annonce' })).toBeDisabled()
    await user.click(form.getByLabelText('Je confirme le contenu et les destinataires de cette annonce'))
    await user.click(form.getByLabelText('Créer une notification interne pour les lecteurs concernés'))
    await user.click(form.getByRole('button', { name: 'Publier l’annonce' }))
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith(root + '/announcements/' + id, expect.objectContaining({ method: 'PATCH',
      body: { action: 'publish', version, confirm_publication: true, notify_members: true } })))
  })
  it('cache la gestion au lecteur et la publication sans permission spécifique', async () => {
    const user = userEvent.setup(); const { unmount } = show({ ...active, context: { ...active.context!, permissions: ['communication.read'] } })
    await open(user); expect(screen.queryByRole('button', { name: 'Gérer les annonces' })).not.toBeInTheDocument(); unmount()
    rows = [{ ...base, status: 'draft', can_comment: false }]
    show({ ...active, context: { ...active.context!, permissions: ['communication.read', 'communication.write'] } }); await open(user)
    await user.click(screen.getByRole('button', { name: 'Gérer les annonces' }))
    await screen.findByRole('button', { name: 'Modifier le brouillon' })
    expect(screen.queryByRole('button', { name: 'Publier l’annonce' })).not.toBeInTheDocument()
  })
  it('soumet un commentaire au contexte et à la version affichés', async () => {
    const user = userEvent.setup(); show(); await open(user)
    await user.click(screen.getByRole('button', { name: 'Commentaires' }))
    const form = within(await screen.findByRole('form', { name: 'Ajouter le commentaire' }))
    await user.type(form.getByLabelText('Message'), 'Réponse')
    await user.click(form.getByRole('button', { name: 'Ajouter le commentaire' }))
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith(root + '/announcements/' + id + '/comments', expect.objectContaining({ method: 'POST', body: { body: 'Réponse', notify_members: false, version } })))
  })
  it('n’autorise pas la participation lorsque le serveur refuse le contexte', async () => {
    rows = [{ ...base, can_comment: false }]
    const user = userEvent.setup(); show(); await open(user)
    await user.click(screen.getByRole('button', { name: 'Commentaires' }))
    await screen.findByText('Vous ne pouvez pas ajouter de commentaire dans ce contexte.')
    expect(screen.queryByRole('form', { name: 'Ajouter le commentaire' })).not.toBeInTheDocument()
  })
  it('ouvre une notification via une nouvelle lecture autorisée et marque sa lecture', async () => {
    const user = userEvent.setup(); show(); await open(user)
    await user.click(screen.getByRole('button', { name: 'Mes notifications' }))
    await user.click(await screen.findByRole('button', { name: 'Ouvrir l’annonce' }))
    await screen.findByRole('region', { name: 'Détail de l’annonce' })
    await waitFor(() => expect(request).toHaveBeenCalledWith(root + '/announcements/' + id, expect.objectContaining({ signal: expect.any(AbortSignal), revision: version })))
    await user.click(screen.getByRole('button', { name: 'Marquer comme lue' }))
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith(root + '/inbox/' + id + '/read', expect.objectContaining({ method: 'POST', body: {} })))
  })
  it('sauve les préférences personnelles sans inventer d’identifiant utilisateur', async () => {
    const user = userEvent.setup(); show(); await open(user)
    await user.click(screen.getByRole('button', { name: 'Mes préférences' }))
    const form = within(await screen.findByRole('form', { name: 'Enregistrer mes préférences' }))
    await user.click(form.getByLabelText('Recevoir les réponses aux discussions auxquelles je participe'))
    await user.click(form.getByRole('button', { name: 'Enregistrer mes préférences' }))
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith(root + '/preferences', expect.objectContaining({ method: 'PATCH', body: { version: null, announcements_enabled: true, comments_enabled: true } })))
  })
  it('annule les lectures et invalide le contexte révoqué', async () => {
    request.mockRejectedValue(new ApiClientError('Droit révoqué', { status: 403 }))
    const user = userEvent.setup(); const { unmount } = show()
    await user.click(screen.getByRole('button', { name: 'Ouvrir les communications' }))
    await waitFor(() => expect(invalid).toHaveBeenCalled())
    const signal = request.mock.calls[0][1].signal as AbortSignal; unmount(); expect(signal.aborted).toBe(true)
  })
})
