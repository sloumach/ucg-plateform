import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ActiveOrganization } from '../../api/tenancy'
import type { MediaItem } from '../../api/media'
import { ApiClientError } from '../../api/errors'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { MediaPanel } from './MediaPanel'
const { request, invalid } = vi.hoisted(() => ({ request: vi.fn(), invalid: vi.fn() }))
vi.mock('../../api/tenancy', async (original) => ({ ...await original<typeof import('../../api/tenancy')>(), tenancyRequest: request }))
const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222', version = '33333333-3333-4333-8333-333333333333'
const active: ActiveOrganization = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', language: 'fr', timezone: 'UTC', roles: ['member'], permissions: ['media.read', 'media.write', 'media.publish'], actor_user_id: 42, is_owner: false, membership_id: id }, revision: version, confirmed: false }
const base: MediaItem = { id, title: 'Vidéo pilote', team_id: id, team_label: 'Équipe pilote', kind: 'vod', provider: 'youtube', url: 'https://www.youtube.com/watch?v=abcdefghijk', status: 'ended', status_source: 'manual', status_observed_at: '2026-10-16T12:00:00+00:00', is_scrim: false, visibility: 'private', effective_visibility: 'private', publish_at: null, archived_at: null, version }
let rows: MediaItem[]
function show(selection = active) { return render(<NotificationProvider><MediaPanel active={selection} disabled={false} onInvalid={invalid} /></NotificationProvider>) }
async function open(user: ReturnType<typeof userEvent.setup>) { await user.click(screen.getByRole('button', { name: 'Ouvrir les médias' })); await screen.findByText(rows[0]?.title ?? 'Aucun média accessible dans cette sélection.') }
beforeEach(() => {
  vi.resetAllMocks(); rows = [{ ...base }]
  request.mockImplementation(async (path, options) => {
    if (path === 'active-organization/confirmation') return { data: { ...active, confirmed: true } }
    if (options?.method) return { data: { ...base, ...options.body } }
    if (path.includes('/media/teams')) return { data: [{ id, label: 'Équipe pilote' }], meta: { current_page: 1, last_page: 1, total: 1 } }
    return { data: rows, meta: { current_page: 1, last_page: 1, total: rows.length } }
  })
})
describe('catalogue média', () => {
  it('ne charge rien sans droit ou avant ouverture', () => {
    const { unmount } = show()
    expect(request).not.toHaveBeenCalled(); unmount()
    show({ ...active, context: { ...active.context!, permissions: ['sports.read'] } })
    expect(screen.queryByRole('heading', { name: 'Streams, VOD et clips' })).not.toBeInTheDocument()
    expect(request).not.toHaveBeenCalled()
  })
  it('échappe les titres et garde une ouverture externe explicite sans iframe', async () => {
    rows = [{ ...base, title: '<script>unsafe</script>', url: 'javascript:alert(1)' }]
    const user = userEvent.setup(); const { container } = show(); await open(user)
    expect(screen.getByText('<script>unsafe</script>')).toBeInTheDocument()
    expect(container.querySelector('script,iframe,img')).toBeNull()
    expect(screen.queryByRole('link', { name: 'Ouvrir chez le fournisseur' })).not.toBeInTheDocument()
    expect(screen.getByText(/Observation manuelle/)).toBeInTheDocument()
  })
  it('expose un lien sûr avec protection de référent et origine', async () => {
    const user = userEvent.setup(); show(); await open(user)
    const link = screen.getByRole('link', { name: 'Ouvrir chez le fournisseur' })
    expect(link).toHaveAttribute('href', base.url); expect(link).toHaveAttribute('rel', 'noopener noreferrer')
    expect(link).toHaveAttribute('referrerpolicy', 'no-referrer')
  })
  it('crée un scrim privé après confirmation tenant et envoie un booléen', async () => {
    const user = userEvent.setup(); show(); await open(user)
    await user.click(screen.getByRole('button', { name: 'Nouveau média' }))
    const form = within(screen.getByRole('form', { name: 'Enregistrer le média' }))
    await user.type(form.getByLabelText('Titre du média'), 'Analyse scrim')
    await user.selectOptions(await form.findByLabelText('Équipe associée'), id)
    await user.type(form.getByLabelText('Lien HTTPS du média'), base.url)
    await user.selectOptions(form.getByLabelText('Contexte du média'), 'true')
    await user.click(form.getByRole('button', { name: 'Enregistrer le média' }))
    expect(request.mock.calls.some(([, options]) => options?.method === 'POST')).toBe(false)
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/' + org + '/media', expect.objectContaining({ method: 'POST', revision: version, body: expect.objectContaining({ is_scrim: true, visibility: 'private', title: 'Analyse scrim', team_id: id }) })))
    expect(request).toHaveBeenCalledWith('active-organization/confirmation', expect.objectContaining({ body: expect.objectContaining({ confirm: true }) }))
  })
  it('omet les observations inchangées lors du renommage et conserve la version', async () => {
    const user = userEvent.setup(); show(); await open(user)
    await user.click(screen.getByRole('button', { name: 'Modifier le média' }))
    await user.clear(screen.getByLabelText('Titre du média')); await user.type(screen.getByLabelText('Titre du média'), 'Nouveau titre')
    await user.click(screen.getByRole('button', { name: 'Enregistrer le média' }))
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/' + org + '/media/' + id, expect.objectContaining({ body: { title: 'Nouveau titre', version }, method: 'PATCH' })))
  })
  it('ne propose pas de partage sans permission publish et conserve la lecture seule', async () => {
    const user = userEvent.setup(); const { unmount } = show({ ...active, context: { ...active.context!, permissions: ['media.read','media.write'] } })
    await open(user); await user.click(screen.getByRole('button', { name: 'Nouveau média' }))
    expect(within(screen.getByLabelText('Visibilité autorisée')).getAllByRole('option')).toHaveLength(1)
    unmount(); show({ ...active, context: { ...active.context!, permissions: ['media.read'] } }); await open(user)
    expect(screen.queryByRole('button', { name: 'Nouveau média' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Modifier le média' })).not.toBeInTheDocument()
  })
  it('propose date différée et confirmation pour un partage de scrim', async () => {
    const user = userEvent.setup(); show(); await open(user); await user.click(screen.getByRole('button', { name: 'Nouveau média' }))
    await user.selectOptions(screen.getByLabelText('Contexte du média'), 'true')
    await user.selectOptions(screen.getByLabelText('Visibilité autorisée'), 'organization')
    expect(screen.getByLabelText('Date de partage différé (heure locale)')).toBeRequired()
    expect(screen.getByLabelText('Je confirme que le partage de ce média est autorisé')).not.toBeChecked()
  })
  it('invalide le contexte sur révocation et annule la lecture au démontage', async () => {
    request.mockRejectedValue(new ApiClientError('Accès refusé', { status: 403 }))
    const user = userEvent.setup(); const { unmount } = show()
    await user.click(screen.getByRole('button', { name: 'Ouvrir les médias' }))
    await waitFor(() => expect(invalid).toHaveBeenCalled())
    const signal = request.mock.calls[0][1].signal as AbortSignal
    unmount(); expect(signal.aborted).toBe(true)
  })
})
