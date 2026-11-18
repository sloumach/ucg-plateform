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
  return render(<NotificationProvider><TenantWorkspace userId={42} /></NotificationProvider>)
}

describe('TenantWorkspace', () => {
  beforeEach(() => {
    window.history.replaceState(null, '', '/?rubrique=acces&outil=invitations')
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
    await user.click(screen.getByRole('link', { name: 'Aperçu' }))
    await user.click(screen.getByRole('button', { name: /Ouvrir Beta/ }))
    expect(screen.queryByText(/secret-alpha@example.test/)).not.toBeInTheDocument()
    expect(await screen.findByText('Organisation active : Beta')).toBeVisible()
    expect(screen.queryByRole('heading', { name: 'Inviter un membre' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Quitter cette organisation' })).toBeEnabled()
    await user.click(screen.getByRole('link', { name: 'Membres et accès' }))
    await screen.findByRole('heading', { name: 'Mes affectations' })
    expect(screen.queryByRole('heading', { name: 'Inviter un membre' })).not.toBeInTheDocument()
    expect(request.mock.calls.filter(([path]) => path.startsWith('tenants/' + beta)).map(([path]) => path)).toEqual(['tenants/' + beta + '/my-role-assignments?per_page=10&page=1'])
  })

  it('demande un consentement ciblé avant confirmation de contexte puis mutation avec la même révision', async () => {
    const user = userEvent.setup()
    mount()
    const invite = await screen.findByRole('button', { name: 'Inviter un membre' })
    expect(screen.queryByLabelText('E-mail de la personne invitée')).not.toBeInTheDocument()
    await user.click(invite)
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
    await user.click(await screen.findByRole('link', { name: 'Aperçu' }))
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
      if (path === 'active-organization' && !delayed) {
        delayed = true
        return new Promise((resolve) => { resolveOld = resolve })
      }
      return original(path, options)
    })
    await act(async () => window.dispatchEvent(new Event('focus')))
    selection = active(beta, false)
    await userEvent.click(screen.getByRole('link', { name: 'Aperçu' }))
    let resolveLists: (value: unknown) => void = () => {}
    const withDelayedLists = request.getMockImplementation()!
    request.mockImplementation(async (path, options) => {
      if (path.startsWith('accessible-organizations')) return new Promise((resolve) => { resolveLists = resolve })
      return withDelayedLists(path, options)
    })
    await act(async () => window.dispatchEvent(new Event('focus')))
    expect(screen.queryByText('Organisation active : Alpha')).not.toBeInTheDocument()
    await act(async () => resolveLists(page([])))
    expect(await screen.findByText('Organisation active : Beta')).toBeVisible()
    await act(async () => resolveOld({ data: active(alpha) }))
    expect(screen.getByText('Organisation active : Beta')).toBeVisible()
    expect(screen.queryByText(/secret-alpha@example.test/)).not.toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Inviter un membre' })).not.toBeInTheDocument()
  })

  it('conserve une saisie et sa confirmation quand les accès sont identiques au retour du focus', async () => {
    const user = userEvent.setup()
    mount()
    await user.click(await screen.findByRole('button', { name: 'Inviter un membre' }))
    const email = await screen.findByLabelText('E-mail de la personne invitée')
    await user.type(email, 'brouillon@example.test')
    selection = active(alpha)
    selection.context!.permissions.reverse()
    request.mockClear()

    await act(async () => window.dispatchEvent(new Event('focus')))
    expect(screen.getByLabelText('E-mail de la personne invitée')).toBe(email)
    expect(email).toHaveValue('brouillon@example.test')
    expect(request.mock.calls.map(([path]) => path)).toEqual(['active-organization'])

    await user.click(screen.getByRole('button', { name: 'Envoyer l’invitation' }))
    const confirmation = screen.getByRole('region', { name: 'Confirmer l’action' })
    await act(async () => window.dispatchEvent(new Event('focus')))
    expect(screen.getByRole('region', { name: 'Confirmer l’action' })).toBe(confirmation)
    expect(confirmation).toHaveFocus()
    expect(request.mock.calls.every(([, options]) => !options?.method)).toBe(true)
  })

  it('efface le formulaire si un droit disparaît sans changement de révision', async () => {
    mount()
    await userEvent.click(await screen.findByRole('button', { name: 'Inviter un membre' }))
    const email = await screen.findByLabelText('E-mail de la personne invitée')
    await userEvent.type(email, 'brouillon@example.test')
    selection = active(alpha)
    selection.context!.permissions = selection.context!.permissions.filter((permission) => permission !== 'invitations.manage')
    request.mockClear()

    await act(async () => window.dispatchEvent(new Event('focus')))
    expect(email).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /^Invitations$/ })).not.toBeInTheDocument()
    expect(request.mock.calls.some(([path]) => path.includes('/invitations?'))).toBe(false)
  })

  it('affiche une panne sans réutiliser de données d’une ancienne organisation', async () => {
    mount()
    await screen.findByText(/secret-alpha@example.test/)
    await userEvent.click(screen.getByRole('link', { name: 'Aperçu' }))
    request.mockRejectedValue(new ApiClientError('Service indisponible', { requestId: 'support-id' }))
    await userEvent.click(screen.getByRole('button', { name: 'Actualiser les accès' }))
    expect(await screen.findByText('Service indisponible')).toBeVisible()
    expect(screen.getByText('Référence de support : support-id')).toBeVisible()
    expect(screen.queryByText(/secret-alpha@example.test/)).not.toBeInTheDocument()
  })

  it('ouvre un espace avec une navigation filtrée et charge les outils à la demande', async () => {
    window.history.replaceState(null, '', '/')
    selection = { ...active(alpha), context: null }
    const original = request.getMockImplementation()!
    request.mockImplementation(async (path, options) => {
      if (path === 'active-organization' && options?.method === 'POST') {
        const organization = active(alpha)
        selection = { ...organization, context: { ...organization.context!, permissions: ['organization.view', 'sports.read'] } }
        return { data: selection }
      }
      return original(path, options)
    })
    const user = userEvent.setup()
    mount()

    expect(screen.queryByRole('navigation', { name: 'Outils de l’organisation' })).not.toBeInTheDocument()
    await user.click(await screen.findByRole('button', { name: 'Ouvrir Alpha' }))
    const navigation = await screen.findByRole('navigation', { name: 'Outils de l’organisation' })
    expect(within(navigation).getByRole('link', { name: 'Aperçu' })).toHaveAttribute('aria-current', 'page')
    expect(within(navigation).queryByRole('link', { name: 'Calendrier' })).not.toBeInTheDocument()
    expect(request.mock.calls.some(([path]) => path.startsWith('tenants/' + alpha + '/teams'))).toBe(false)

    await user.click(within(navigation).getByRole('link', { name: 'Équipes et jeux' }))
    expect(screen.getByRole('heading', { name: 'Équipes et jeux' })).toHaveFocus()
    expect(screen.getByRole('button', { name: 'Équipes de l’organisation' })).toBeVisible()
    expect(screen.queryByRole('button', { name: 'Jeux de l’organisation' })).not.toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Mes invitations' })).not.toBeInTheDocument()
    expect(window.location.search).toBe('?rubrique=equipes')
    await user.click(screen.getByRole('link', { name: /^Jeux$/ }))
    expect(screen.getByRole('button', { name: 'Jeux de l’organisation' })).toBeVisible()
    expect(screen.queryByRole('button', { name: 'Équipes de l’organisation' })).not.toBeInTheDocument()
    window.history.back()
    expect(await screen.findByRole('button', { name: 'Équipes de l’organisation' })).toBeVisible()
    window.history.back()
    expect(await screen.findByRole('heading', { name: 'Mes invitations' })).toBeVisible()
  })

  it('ignore un lien direct à un outil réservé au gestionnaire et ne charge pas ses données', async () => {
    selection = active(beta, false)
    mount()

    expect(await screen.findByRole('heading', { name: 'Mes affectations' })).toBeVisible()
    expect(screen.queryByRole('link', { name: /^Rôles$/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /^Invitations$/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Inviter un membre' })).not.toBeInTheDocument()
    expect(request.mock.calls.some(([path]) => path.startsWith('tenants/' + beta + '/invitations'))).toBe(false)
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
    await user.click(await screen.findByRole('link', { name: /^Membres$/ }))
    expect(screen.queryByRole('form', { name: 'Compte 7' })).not.toBeInTheDocument()
    await user.click(await screen.findByRole('button', { name: 'Modifier l’adhésion du compte 7' }))
    const form = await screen.findByRole('form', { name: 'Compte 7' })
    await user.selectOptions(within(form).getByLabelText('Statut de l’adhésion'), 'suspended')
    await user.click(within(form).getByRole('button', { name: 'Enregistrer l’adhésion' }))
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    expect(request).toHaveBeenCalledWith('tenants/' + alpha + '/memberships/member-id', expect.objectContaining({
      body: { status: 'suspended', roles: ['member', 'administrator'], starts_at: '2026-01-01T12:34:56+00:00', ends_at: null },
    }))
  })
})
