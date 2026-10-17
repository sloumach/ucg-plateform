import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ActiveOrganization } from '../../api/tenancy'
import type { CalendarFeed } from '../../api/calendarFeed'
import { ApiClientError } from '../../api/errors'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { CalendarFeedPanel } from './CalendarFeedPanel'
const { request, invalid } = vi.hoisted(() => ({ request: vi.fn(), invalid: vi.fn() }))
vi.mock('../../api/tenancy', async (original) => ({ ...await original<typeof import('../../api/tenancy')>(), tenancyRequest: request }))
const org = '11111111-1111-4111-8111-111111111111', version = '22222222-2222-4222-8222-222222222222', next = '33333333-3333-4333-8333-333333333333'
const root = 'tenants/' + org + '/calendar', url = 'https://calendar.example.test/private.ics?token=' + 'a'.repeat(64)
const active: ActiveOrganization = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'], permissions: ['calendar.read', 'calendar.export'], actor_user_id: 42, is_owner: false, membership_id: version }, revision: version, confirmed: false }
let feed: CalendarFeed | null
function show(selection = active) { return render(<NotificationProvider><CalendarFeedPanel active={selection} root={root} disabled={false} onInvalid={invalid} /></NotificationProvider>) }
async function open(user: ReturnType<typeof userEvent.setup>) { await user.click(screen.getByRole('button', { name: 'Gérer mon lien ICS' })); await screen.findByRole('form', { name: 'Configurer le lien privé' }) }
beforeEach(() => {
  vi.resetAllMocks(); feed = null; sessionStorage.clear(); localStorage.clear()
  request.mockImplementation(async (path, options) => {
    if (path === 'active-organization/confirmation') return { data: { ...active, confirmed: true } }
    if (options?.method === 'POST') {
      feed = { id: org, version: next, before_days: options.body.before_days, after_days: options.body.after_days, status: 'active', revoked_at: null, expires_at: '2026-11-17T10:00:00Z' }
      return { data: { feed, url } }
    }
    if (options?.method === 'PATCH') { feed = { ...feed!, status: 'revoked', revoked_at: '2026-10-17T10:00:00Z', version }; return { data: feed } }
    return { data: feed }
  })
})
describe('lien ICS privé', () => {
  it('charge à la demande et exige les deux permissions', async () => {
    const user = userEvent.setup(), view = show()
    expect(request).not.toHaveBeenCalled(); await open(user)
    expect(request).toHaveBeenCalledWith(root + '/feed', expect.objectContaining({ revision: version }))
    view.unmount(); request.mockClear()
    show({ ...active, context: { ...active.context!, permissions: ['calendar.read', 'calendar.write'] } })
    expect(screen.queryByRole('region', { name: 'Mon calendrier ICS privé' })).not.toBeInTheDocument()
    expect(request).not.toHaveBeenCalled()
  })
  it('confirme le tenant et l’export puis affiche le secret une seule fois sans stockage', async () => {
    const user = userEvent.setup(); show(); await open(user)
    const form = within(screen.getByRole('form', { name: 'Configurer le lien privé' }))
    await user.click(form.getByLabelText('J’autorise l’export des titres et horaires vers mon calendrier externe'))
    await user.click(form.getByRole('button', { name: 'Créer le lien privé' }))
    expect(request.mock.calls.filter(([, options]) => options?.method === 'POST')).toHaveLength(0)
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    expect(await screen.findByLabelText('Lien secret ICS')).toHaveValue(url)
    expect(request).toHaveBeenCalledWith(root + '/feed', expect.objectContaining({ method: 'POST', revision: version, body: { expected_version: null, before_days: 7, after_days: 60, expires_in_days: 30, confirm_export: true } }))
    expect(localStorage.length).toBe(0); expect(sessionStorage.length).toBe(0)
    expect(document.querySelector('a[href*="token="]')).toBeNull()
    await user.click(screen.getByRole('button', { name: 'Fermer le lien ICS' }))
    expect(screen.queryByLabelText('Lien secret ICS')).not.toBeInTheDocument()
    await open(user)
    expect(screen.queryByLabelText('Lien secret ICS')).not.toBeInTheDocument()
    expect(screen.getByText('Lien actif')).toBeVisible()
  })
  it('révoque la version courante et efface le secret après confirmation', async () => {
    const user = userEvent.setup(); show(); await open(user)
    await user.click(screen.getByLabelText('J’autorise l’export des titres et horaires vers mon calendrier externe'))
    await user.click(screen.getByRole('button', { name: 'Créer le lien privé' })); await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await screen.findByLabelText('Lien secret ICS')
    const revoke = within(screen.getByRole('form', { name: 'Révoquer le lien privé' }))
    await user.click(revoke.getByLabelText('Je confirme la révocation immédiate de ce lien'))
    await user.click(revoke.getByRole('button', { name: 'Révoquer le lien privé' })); await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await screen.findByText('Lien révoqué')
    expect(screen.queryByLabelText('Lien secret ICS')).not.toBeInTheDocument()
    expect(request).toHaveBeenCalledWith(root + '/feed', expect.objectContaining({ method: 'PATCH', body: { version: next, confirm_revocation: true } }))
  })
  it('ne réessaie pas une rotation en conflit et invalide le contexte', async () => {
    const user = userEvent.setup(); show(); await open(user)
    request.mockImplementation(async (path) => { if (path === 'active-organization/confirmation') return {}; throw new ApiClientError('Lien modifié', { status: 409, code: 'CALENDAR_FEED_STALE' }) })
    await user.click(screen.getByLabelText('J’autorise l’export des titres et horaires vers mon calendrier externe'))
    await user.click(screen.getByRole('button', { name: 'Créer le lien privé' })); await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(invalid).toHaveBeenCalledOnce())
    expect(request.mock.calls.filter(([, options]) => options?.method === 'POST' && options?.body?.confirm_export)).toHaveLength(1)
    expect(screen.queryByLabelText('Lien secret ICS')).not.toBeInTheDocument()
  })
})
