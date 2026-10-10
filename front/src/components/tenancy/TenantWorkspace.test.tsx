import { act, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiClientError } from '../../api/errors'
import { tenancyRequest, type ActiveOrganization } from '../../api/tenancy'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { TenantWorkspace } from './TenantWorkspace'

vi.mock('../../api/tenancy', () => ({ tenancyRequest: vi.fn() }))
const request = vi.mocked(tenancyRequest)
const alpha = '11111111-1111-4111-8111-111111111111'
const beta = '22222222-2222-4222-8222-222222222222'
const revision = '33333333-3333-4333-8333-333333333333'
function active(id: string, admin = true): ActiveOrganization {
  return { context: { organization_id: id, name: id === alpha ? 'Alpha' : 'Beta',
    slug: id === alpha ? 'alpha' : 'beta', timezone: 'UTC', language: 'fr', roles: admin ? ['owner'] : ['member'],
    permissions: admin ? ['organization.view', 'memberships.manage', 'invitations.manage'] : ['organization.view'],
    is_owner: admin, actor_user_id: 42, membership_id: admin ? null : 'membership-id' },
    revision, confirmed: false }
}
const page = (data: unknown[]) => ({ data, meta: { current_page: 1, last_page: 1, total: data.length, request_id: 'request-id' } })
let selection: ActiveOrganization
function mount() {
  return render(<NotificationProvider><TenantWorkspace /></NotificationProvider>)
}

describe('TenantWorkspace', () => {
  beforeEach(() => {
    selection = active(alpha)
    request.mockReset()
    request.mockImplementation(async (path, options) => {
      if (path === 'active-organization' && options?.method === 'POST') {
        const body = options.body as { organization_id: string }
        selection = active(body.organization_id, body.organization_id === alpha)
        return { data: selection }
      }
      if (path === 'active-organization') return { data: selection }
      if (path.startsWith('accessible-organizations')) return page([
        { id: alpha, name: 'Alpha', roles: ['owner'], membership_id: null, is_owner: true, slug: 'alpha' },
        { id: beta, name: 'Beta', roles: ['member'], membership_id: 'membership-id', is_owner: false, slug: 'beta' },
      ])
      if (path.includes('/invitations?')) return page([{
        id: 'invitation-id', organization_id: alpha, organization_name: 'Alpha', email: 'secret-alpha@example.test',
        status: 'pending', roles: ['member'], starts_at: '2026-01-01T00:00:00+00:00', ends_at: null, expires_at: '2027-01-01T00:00:00+00:00',
      }])
      if (path.includes('?')) return page([])
      return { data: null, notification: { type: 'success', message: 'Opération réussie.' } }
    })
  })

  it('change de contexte en effaçant les anciennes données et les commandes administratives', async () => {
    const user = userEvent.setup()
    mount()
    expect(await screen.findByText(/secret-alpha@example.test/)).toBeVisible()
    await user.click(screen.getByRole('button', { name: /Ouvrir Beta/ }))
    expect(screen.queryByText(/secret-alpha@example.test/)).not.toBeInTheDocument()
    expect(await screen.findByText('Organisation active : Beta')).toBeVisible()
    expect(screen.queryByRole('heading', { name: 'Inviter un membre' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Quitter cette organisation' })).toBeEnabled()
    expect(request.mock.calls.filter(([path]) => path.startsWith('tenants/' + beta))).toHaveLength(0)
  })

  it('demande un consentement ciblé avant confirmation de contexte puis mutation avec la même révision', async () => {
    const user = userEvent.setup()
    mount()
    await screen.findByRole('heading', { name: 'Inviter un membre' })
    await user.type(screen.getByLabelText('E-mail de la personne invitée'), 'new@example.test')
    await user.click(screen.getByRole('button', { name: 'Envoyer l’invitation' }))
    const confirmation = screen.getByRole('region', { name: 'Confirmer l’action' })
    expect(confirmation).toHaveFocus()
    expect(within(confirmation).getByText(/organisation Alpha/)).toBeVisible()
    expect(request.mock.calls.some(([path]) => path.endsWith('/invitations'))).toBe(false)
    await user.click(within(confirmation).getByRole('button', { name: 'Confirmer et continuer' }))
    expect(request).toHaveBeenCalledWith('active-organization/confirmation', expect.objectContaining({
      body: { organization_id: alpha, revision, confirm: true },
    }))
    expect(request).toHaveBeenCalledWith('tenants/' + alpha + '/invitations', expect.objectContaining({
      method: 'POST', revision, body: expect.objectContaining({ email: 'new@example.test', roles: ['member'] }),
    }))
    expect(await screen.findByText('Opération réussie.')).toBeVisible()
  })

  it('annuler ne confirme ni ne modifie le contexte', async () => {
    const user = userEvent.setup()
    mount()
    await screen.findByText(/secret-alpha@example.test/)
    await user.click(screen.getByRole('button', { name: 'Révoquer' }))
    await user.click(screen.getByRole('button', { name: 'Annuler' }))
    expect(screen.queryByRole('region', { name: 'Confirmer l’action' })).not.toBeInTheDocument()
    expect(request.mock.calls.some(([, options]) => options?.method)).toBe(false)
    expect(screen.getByRole('button', { name: 'Révoquer' })).toHaveFocus()
  })

  it('une révision périmée recharge les accès au lieu de rejouer la mutation', async () => {
    const user = userEvent.setup()
    mount()
    await screen.findByText(/secret-alpha@example.test/)
    await user.click(screen.getByRole('button', { name: 'Révoquer' }))
    const original = request.getMockImplementation()!
    request.mockImplementation(async (path, options) => {
      if (path === 'active-organization/confirmation') {
        selection = active(beta, false)
        throw new ApiClientError('Contexte changé', { status: 409, code: 'TENANT_CONTEXT_CHANGED' })
      }
      return original(path, options)
    })
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    expect(await screen.findByText('Organisation active : Beta')).toBeVisible()
    expect(screen.queryByText(/secret-alpha@example.test/)).not.toBeInTheDocument()
    expect(request.mock.calls.some(([, options]) => options?.method === 'DELETE')).toBe(false)
    expect(screen.getByText('Le contexte a changé. Les accès et les données ont été rechargés.')).toBeVisible()
  })

  it('ignore une réponse tenant obsolète après une nouvelle lecture', async () => {
    mount()
    await screen.findByText(/secret-alpha@example.test/)
    let resolveOld: (value: unknown) => void = () => {}
    const original = request.getMockImplementation()!
    let delayed = false
    request.mockImplementation(async (path, options) => {
      if (path.includes('/invitations?') && !delayed) {
        delayed = true
        return new Promise((resolve) => { resolveOld = resolve })
      }
      return original(path, options)
    })
    await act(async () => window.dispatchEvent(new Event('focus')))
    selection = active(beta, false)
    await act(async () => window.dispatchEvent(new Event('focus')))
    expect(await screen.findByText('Organisation active : Beta')).toBeVisible()
    await act(async () => resolveOld(page([{ email: 'obsolete@example.test' }])))
    expect(screen.queryByText(/obsolete@example.test/)).not.toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Inviter un membre' })).not.toBeInTheDocument()
  })

  it('affiche une panne sans réutiliser de données d’une ancienne organisation', async () => {
    mount()
    await screen.findByText(/secret-alpha@example.test/)
    request.mockRejectedValue(new ApiClientError('Service indisponible', { requestId: 'support-id' }))
    await userEvent.click(screen.getByRole('button', { name: 'Actualiser les accès' }))
    expect(await screen.findByText('Service indisponible')).toBeVisible()
    expect(screen.getByText('Référence de support : support-id')).toBeVisible()
    expect(screen.queryByText(/secret-alpha@example.test/)).not.toBeInTheDocument()
  })

  it('conserve les rôles multiples et les secondes de la période lorsqu’on ne change que le statut', async () => {
    const original = request.getMockImplementation()!
    request.mockImplementation(async (path, options) => {
      if (path.includes('/memberships?')) return page([{
        id: 'member-id', organization_id: alpha, user_id: 7, roles: ['member', 'administrator'], status: 'active', effective: true,
        starts_at: '2026-01-01T12:34:56+00:00', ends_at: null,
      }])
      return original(path, options)
    })
    const user = userEvent.setup()
    mount()
    const form = await screen.findByRole('form', { name: 'Compte 7' })
    await user.selectOptions(within(form).getByLabelText('Statut de l’adhésion'), 'suspended')
    await user.click(within(form).getByRole('button', { name: 'Enregistrer l’adhésion' }))
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    expect(request).toHaveBeenCalledWith('tenants/' + alpha + '/memberships/member-id', expect.objectContaining({
      body: { status: 'suspended', roles: ['member', 'administrator'], starts_at: '2026-01-01T12:34:56+00:00', ends_at: null },
    }))
  })
})
