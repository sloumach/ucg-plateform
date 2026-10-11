import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { tenancyRequest, type ActiveOrganization } from '../../api/tenancy'
import { ApiClientError } from '../../api/errors'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { RoleManagementPanel } from './RoleManagementPanel'

vi.mock('../../api/tenancy', () => ({ tenancyRequest: vi.fn() }))
const request = vi.mocked(tenancyRequest)
const org = '11111111-1111-4111-8111-111111111111'
const version = '22222222-2222-4222-8222-222222222222'
const role = { id: '33333333-3333-4333-8333-333333333333', organization_id: org, key: 'coach', name: 'Coach',
  permissions: ['sports.read'], active: true, privileged: false, version }
const assignment = { id: '44444444-4444-4444-8444-444444444444', organization_id: org, role_id: role.id,
  role_name: 'Coach', user_id: 42, scope_type: 'organization', scope_id: org, status: 'active', starts_at: '2026-01-01T12:00:05+00:00', ends_at: null, version }
function active(assign = true): ActiveOrganization {
  return { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr',
    roles: assign ? ['owner'] : ['member'], permissions: assign ? ['organization.view', 'roles.assign', 'roles.manage'] : ['organization.view'],
    is_owner: assign, actor_user_id: 42, membership_id: assign ? null : 'membership' }, revision: version, confirmed: false }
}
const page = (data: unknown[]) => ({ data, meta: { current_page: 1, last_page: 1, total: data.length, request_id: 'request' } })
function show(assign = true) {
  const changed = vi.fn()
  const result = render(<NotificationProvider><RoleManagementPanel active={active(assign)} disabled={false} onChanged={changed} /></NotificationProvider>)
  return { ...result, changed }
}
describe('Contextual role management', () => {
  beforeEach(() => {
    request.mockReset()
    request.mockImplementation(async (path, options) => {
      if (options?.method) return { data: role, notification: { type: 'success', message: 'Droits enregistrés.' } }
      if (path.includes('permission-catalogue')) return { data: { permissions: [{ key: 'sports.read', privileged: false, organization_only: false }], scope_types: ['organization'], can_manage: true } }
      if (path.includes('role-assignments')) return page([assignment])
      return page([role])
    })
  })
  it('confirme le tenant avant de créer un rôle et transmet seulement les permissions choisies', async () => {
    const user = userEvent.setup()
    const { changed } = show()
    await user.click(await screen.findByRole('button', { name: 'Créer un rôle' }))
    const editor = screen.getByRole('form', { name: 'Enregistrer le rôle' })
    await user.type(within(editor).getByLabelText('Clé du rôle'), 'analyste')
    await user.type(within(editor).getByLabelText('Nom du rôle'), 'Analyste')
    await user.click(within(editor).getByLabelText('Consulter les données sportives'))
    await user.click(within(editor).getByRole('button', { name: 'Enregistrer le rôle' }))
    expect(request.mock.calls.some(([, options]) => options?.method)).toBe(false)
    const confirmation = screen.getByRole('region', { name: 'Confirmer l’action' })
    expect(confirmation).toHaveFocus()
    expect(within(confirmation).getByText(/Analyste.*Alpha/)).toBeVisible()
    await user.click(within(confirmation).getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(changed).toHaveBeenCalledTimes(1))
    expect(request).toHaveBeenCalledWith('active-organization/confirmation', expect.objectContaining({ body: { organization_id: org, revision: version, confirm: true } }))
    expect(request).toHaveBeenCalledWith('tenants/' + org + '/roles', expect.objectContaining({
      method: 'POST', revision: version, body: { key: 'analyste', name: 'Analyste', permissions: ['sports.read'], active: true },
    }))
  })
  it('conserve la version et la clé lors de la modification du rôle', async () => {
    const user = userEvent.setup()
    show()
    await user.click(await screen.findByRole('button', { name: 'Modifier le rôle Coach' }))
    const editor = screen.getByRole('form', { name: 'Enregistrer le rôle' })
    expect(within(editor).getByLabelText('Clé du rôle')).toHaveAttribute('readonly')
    await user.clear(within(editor).getByLabelText('Nom du rôle'))
    await user.type(within(editor).getByLabelText('Nom du rôle'), 'Coach principal')
    await user.click(within(editor).getByRole('button', { name: 'Enregistrer le rôle' }))
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/' + org + '/roles/' + role.id, expect.objectContaining({
      method: 'PUT', body: expect.objectContaining({ key: 'coach', name: 'Coach principal', version }),
    })))
  })
  it('un membre consulte uniquement ses affectations sans commandes de gestion', async () => {
    show(false)
    expect(await screen.findByRole('heading', { name: 'Mes affectations' })).toBeVisible()
    expect(screen.queryByRole('button', { name: 'Créer un rôle' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Enregistrer l’affectation' })).not.toBeInTheDocument()
    expect(request).toHaveBeenCalledTimes(1)
    expect(request.mock.calls[0][0]).toContain('/my-role-assignments?')
  })
  it('préserve les secondes et la version lors de la révocation ciblée', async () => {
    const user = userEvent.setup()
    show()
    const editor = await screen.findByRole('form', { name: 'Affectation Coach — compte 42' })
    await user.selectOptions(within(editor).getByLabelText('Statut de l’affectation'), 'revoked')
    await user.click(within(editor).getByRole('button', { name: 'Enregistrer l’affectation' }))
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/' + org + '/role-assignments/' + assignment.id, expect.objectContaining({
      method: 'PUT', body: expect.objectContaining({ status: 'revoked', starts_at: assignment.starts_at, version, user_id: 42, role_id: role.id }),
    })))
  })
  it('une erreur de validation conserve le formulaire et place le focus sur le champ', async () => {
    const user = userEvent.setup()
    show()
    await user.click(await screen.findByRole('button', { name: 'Créer un rôle' }))
    request.mockImplementation(async (_path, options) => {
      if (options?.method === 'POST' && _path.endsWith('/roles')) throw new ApiClientError('Clé réservée.', { status: 422, fieldErrors: { key: ['Choisissez une autre clé.'] } })
      return { data: null }
    })
    await user.click(screen.getByRole('button', { name: 'Enregistrer le rôle' }))
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    expect(await screen.findByText('Choisissez une autre clé.')).toBeVisible()
    expect(screen.getByLabelText('Clé du rôle')).toHaveFocus()
  })
  it('une version périmée déclenche une relecture sans rejouer la mutation', async () => {
    const user = userEvent.setup()
    const { changed } = show()
    await user.click(await screen.findByRole('button', { name: 'Modifier le rôle Coach' }))
    request.mockImplementation(async (path) => {
      if (path.endsWith('/roles/' + role.id)) throw new ApiClientError('Ces droits ont changé.', { status: 409, code: 'AUTHORIZATION_VERSION_STALE' })
      return { data: null }
    })
    await user.click(screen.getByRole('button', { name: 'Enregistrer le rôle' }))
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(changed).toHaveBeenCalledTimes(1))
    expect(request.mock.calls.filter(([path, options]) => path.endsWith('/roles/' + role.id) && options?.method === 'PUT')).toHaveLength(1)
    expect(screen.getAllByText('Ces droits ont changé.').length).toBeGreaterThan(0)
  })
})
