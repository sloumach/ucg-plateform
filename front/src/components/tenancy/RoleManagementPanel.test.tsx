/// <reference types="node" />
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { readFileSync } from 'node:fs'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { tenancyRequest, type ActiveOrganization } from '../../api/tenancy'
import { ApiClientError } from '../../api/errors'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { RoleManagementPanel } from './RoleManagementPanel'
import { t, type TranslationKey } from '../../i18n/fr'

vi.mock('../../api/tenancy', () => ({ tenancyRequest: vi.fn() }))
const request = vi.mocked(tenancyRequest)
const org = '11111111-1111-4111-8111-111111111111'
const version = '22222222-2222-4222-8222-222222222222'
const role = { id: '33333333-3333-4333-8333-333333333333', organization_id: org, key: 'coach', name: 'Coach',
  permissions: ['sports.read'], active: true, privileged: false, version }
const assignment = { id: '44444444-4444-4444-8444-444444444444', organization_id: org, role_id: role.id,
  role_name: 'Coach', user_id: 42, scope_type: 'organization', scope_id: org, status: 'active', starts_at: '2026-01-01T12:00:05+00:00', ends_at: null, version }
const member = { id: 43, name: 'Alex Martin', email: 'alex.martin@example.test' }
const secondMember = { id: 44, name: 'Alex Martin', email: 'alex.coach@example.test' }
function active(assign = true): ActiveOrganization {
  return { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr',
    roles: assign ? ['owner'] : ['member'], permissions: assign ? ['organization.view', 'roles.assign', 'roles.manage'] : ['organization.view'],
    is_owner: assign, actor_user_id: 42, membership_id: assign ? null : 'membership' }, revision: version, confirmed: false }
}
const page = (data: unknown[]) => ({ data, meta: { current_page: 1, last_page: 1, total: data.length, request_id: 'request' } })
function show(view: 'roles' | 'affectations' = 'roles', assign = true) {
  const changed = vi.fn()
  const result = render(<NotificationProvider><RoleManagementPanel view={view} active={active(assign)} disabled={false} onChanged={changed} /></NotificationProvider>)
  return { ...result, changed }
}
describe('Contextual role management', () => {
  beforeEach(() => {
    request.mockReset()
    request.mockImplementation(async (path, options) => {
      if (options?.method) return { data: role, notification: { type: 'success', message: 'Droits enregistrés.' } }
      if (path.includes('permission-catalogue')) return { data: { permissions: [{ key: 'sports.read', privileged: false, organization_only: false }, { key: 'audit.view', privileged: true, organization_only: true }], scope_types: ['organization'], can_manage: true } }
      if (path.includes('role-assignments')) return page([assignment])
      if (path.includes('role-assignment-members')) return page([member, secondMember])
      return page([role])
    })
  })
  it('confirme le tenant avant de créer un rôle et transmet seulement les permissions choisies', async () => {
    const user = userEvent.setup()
    const { changed } = show()
    await user.click(await screen.findByRole('button', { name: 'Créer un rôle' }))
    expect(screen.queryByRole('form', { name: 'Affecter un rôle' })).not.toBeInTheDocument()
    expect(request.mock.calls.some(([path]) => path.includes('/role-assignments'))).toBe(false)
    const editor = screen.getByRole('form', { name: 'Enregistrer le rôle' })
    await user.type(within(editor).getByLabelText('Clé du rôle'), 'analyste')
    await user.type(within(editor).getByLabelText('Nom du rôle'), 'Analyste')
    expect(within(editor).queryByRole('checkbox', { name: 'Consulter les données sportives' })).not.toBeInTheDocument()
    await user.click(within(editor).getByRole('button', { name: /Équipes, jeux et suivi sportif/ }))
    await user.click(within(editor).getByLabelText('Consulter les données sportives'))
    await user.type(within(editor).getByRole('searchbox'), 'aUdIt')
    expect(within(editor).queryByRole('checkbox', { name: 'Consulter les données sportives' })).not.toBeInTheDocument()
    expect(within(editor).getByRole('checkbox', { name: /Consulter le journal d’audit/ })).toBeVisible()
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
  it('permet de choisir la consultation du journal avec son libellé français', async () => {
    const user = userEvent.setup()
    show()
    await user.click(await screen.findByRole('button', { name: 'Créer un rôle' }))
    const editor = screen.getByRole('form', { name: 'Enregistrer le rôle' })
    await user.type(within(editor).getByLabelText('Clé du rôle'), 'auditeur')
    await user.type(within(editor).getByLabelText('Nom du rôle'), 'Auditeur')
    await user.click(within(editor).getByRole('button', { name: /Organisation et accès/ }))
    await user.click(within(editor).getByRole('checkbox', { name: /Consulter le journal d’audit de l’organisation.*MFA/ }))
    await user.click(within(editor).getByRole('button', { name: 'Enregistrer le rôle' }))
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/' + org + '/roles', expect.objectContaining({
      method: 'POST', body: expect.objectContaining({ permissions: ['audit.view'] }),
    })))
  })
  it('présente toutes les permissions configurables du serveur en français et conserve leurs clés lors de la modification', async () => {
    // Read the domain catalogue so a new server permission cannot silently fall back to a technical label.
    const source = readFileSync('../back/app/Modules/Tenancy/Domain/Permission.php', 'utf8')
    const keys = [...source.matchAll(/case \w+ = '([^']+)';/g)].map((match) => match[1]).filter((key) => key !== 'roles.manage')
    expect(keys).toHaveLength(66)
    const completeRole = { ...role, permissions: keys }
    request.mockImplementation(async (path, options) => {
      if (options?.method) return { data: completeRole }
      if (path.includes('permission-catalogue')) return { data: { permissions: keys.map((key) => ({ key, privileged: !['organization.view', 'sports.read'].includes(key), organization_only: false })), scope_types: ['organization'], can_manage: true } }
      if (path.includes('role-assignments')) return page([])
      return page([completeRole])
    })
    const user = userEvent.setup()
    show()
    await user.click(await screen.findByText('Voir les permissions (66)'))
    expect(screen.getByText(keys.map((key) => t(('roles.permission.' + key) as TranslationKey)).join(', '))).toBeVisible()
    await user.click(await screen.findByRole('button', { name: 'Modifier le rôle Coach' }))
    const editor = screen.getByRole('form', { name: 'Enregistrer le rôle' })
    const permissions = within(editor).getByRole('group', { name: 'Permissions explicites' })
    await user.click(within(permissions).getByRole('button', { name: 'Afficher la sélection' }))
    const checkboxes = within(permissions).getAllByRole('checkbox')
    expect(checkboxes).toHaveLength(keys.length)
    for (const key of keys) {
      const label = t(('roles.permission.' + key) as TranslationKey)
      expect(label).toBeTruthy()
      expect(label).not.toBe(key)
      const checkbox = checkboxes.find((input) => (input as HTMLInputElement).value === key)!
      expect(checkbox).toBeChecked()
      expect(checkbox).toHaveAccessibleName(label + (['organization.view', 'sports.read'].includes(key) ? '' : t('roles.mfaBadge')))
    }
    expect(within(permissions).queryByRole('checkbox', { name: 'roles.manage' })).not.toBeInTheDocument()
    await user.click(within(editor).getByRole('button', { name: 'Enregistrer le rôle' }))
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/' + org + '/roles/' + role.id, expect.objectContaining({ method: 'PUT', body: expect.objectContaining({ permissions: keys, version }) })))
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
    show('affectations', false)
    expect(await screen.findByRole('heading', { name: 'Mes affectations' })).toBeVisible()
    expect(screen.queryByRole('button', { name: 'Créer un rôle' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Enregistrer l’affectation' })).not.toBeInTheDocument()
    expect(request).toHaveBeenCalledTimes(1)
    expect(request.mock.calls[0][0]).toContain('/my-role-assignments?')
  })
  it('préserve les secondes et la version lors de la révocation ciblée', async () => {
    const user = userEvent.setup()
    show('affectations')
    const edit = await screen.findByRole('button', { name: 'Modifier l’affectation Coach du compte 42' })
    expect(screen.queryByRole('form', { name: 'Affectation Coach — compte 42' })).not.toBeInTheDocument()
    await user.click(edit)
    const editor = await screen.findByRole('form', { name: 'Affectation Coach — compte 42' })
    expect(screen.queryByRole('button', { name: 'Créer un rôle' })).not.toBeInTheDocument()
    await user.selectOptions(within(editor).getByLabelText('Statut de l’affectation'), 'revoked')
    await user.click(within(editor).getByRole('button', { name: 'Enregistrer l’affectation' }))
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/' + org + '/role-assignments/' + assignment.id, expect.objectContaining({
      method: 'PUT', body: expect.objectContaining({ status: 'revoked', starts_at: assignment.starts_at, version, user_id: 42, role_id: role.id }),
    })))
  })
  it('recherche par e-mail et confirme la personne choisie même quand deux membres ont le même nom', async () => {
    const user = userEvent.setup()
    const original = request.getMockImplementation()!
    request.mockImplementation(async (path, options) => {
      if (path.includes('role-assignment-members') && new URLSearchParams(path.split('?')[1]).get('q') === 'alex.coach') return page([secondMember])
      return original(path, options)
    })
    show('affectations')
    const create = await screen.findByRole('button', { name: 'Nouvelle affectation' })
    expect(screen.queryByRole('form', { name: 'Affecter un rôle' })).not.toBeInTheDocument()
    await user.click(create)
    const form = await screen.findByRole('form', { name: 'Affecter un rôle' })
    expect(screen.queryByRole('button', { name: /Modifier l’affectation/ })).not.toBeInTheDocument()
    const picker = within(form).getByRole('group', { name: 'Choisir un membre' })
    const firstOption = await within(picker).findByRole('radio', { name: 'Alex Martin — alex.martin@example.test' })
    await user.click(within(form).getByRole('button', { name: 'Affecter un rôle' }))
    expect(screen.queryByRole('region', { name: 'Confirmer l’action' })).not.toBeInTheDocument()
    expect(within(picker).getByRole('alert')).toHaveTextContent('Sélectionne un membre')

    await user.click(firstOption)
    await user.type(within(form).getByRole('searchbox', { name: 'Rechercher par nom ou e-mail' }), 'alex.coach{Enter}')
    const option = await within(picker).findByRole('radio', { name: 'Alex Martin — alex.coach@example.test' })
    expect(within(picker).queryByRole('radio', { name: 'Alex Martin — alex.martin@example.test' })).not.toBeInTheDocument()
    expect(option).not.toBeChecked()
    await user.click(option)
    await user.selectOptions(within(form).getByRole('combobox', { name: 'Rôle à affecter' }), role.id)
    await user.click(within(form).getByRole('button', { name: 'Affecter un rôle' }))
    const confirmation = screen.getByRole('region', { name: 'Confirmer l’action' })
    expect(confirmation).toHaveTextContent('Alex Martin — alex.coach@example.test')
    await user.click(within(confirmation).getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/' + org + '/role-assignments', expect.objectContaining({
      method: 'POST', body: expect.objectContaining({ user_id: '44', role_id: role.id }),
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
  it.each([['team', 'sports.read'], ['game', 'sports.read'], ['cohort', 'academy.read'], ['event', 'calendar.read']] as const)('affecte un rôle %s depuis un objet réel et retire les rôles incompatibles du choix', async (type, permission) => {
    const user = userEvent.setup()
    const limitedRole = { ...role, name: 'Lecteur limité', permissions: [permission] }
    const organizationRole = { ...role, id: '55555555-5555-4555-8555-555555555555', name: 'Gouvernance globale', permissions: ['audit.view'] }
    request.mockImplementation(async (path, options) => {
      if (options?.method) return { data: assignment, notification: { type: 'success', message: 'Droits enregistrés.' } }
      if (path.includes('permission-catalogue')) return { data: { permissions: [
        { key: permission, privileged: type !== 'game', organization_only: false, scope_types: ['organization', type] },
        { key: 'audit.view', privileged: true, organization_only: true, scope_types: ['organization'] },
      ], scope_types: ['organization', 'team', 'game', 'cohort', 'event'], can_manage: true } }
      if (path.includes('authorization-scopes/')) return page([{ id: version, label: 'Objet autorisé' }])
      if (path.includes('role-assignments')) return page([])
      if (path.includes('role-assignment-members')) return page([member])
      return page([limitedRole, organizationRole])
    })
    show('affectations')
    await user.click(await screen.findByRole('button', { name: 'Nouvelle affectation' }))
    const form = await screen.findByRole('form', { name: 'Affecter un rôle' })
    await user.selectOptions(within(form).getByRole('combobox', { name: 'Périmètre' }), type)
    await within(form).findByRole('option', { name: 'Objet autorisé' })
    expect(within(form).queryByRole('option', { name: 'Gouvernance globale' })).not.toBeInTheDocument()
    await user.selectOptions(within(form).getByRole('combobox', { name: 'Rôle à affecter' }), role.id)
    await user.click(await within(form).findByRole('radio', { name: 'Alex Martin — alex.martin@example.test' }))
    await user.selectOptions(within(form).getByRole('combobox', { name: 'Objet du périmètre' }), version)
    await user.click(within(form).getByRole('button', { name: 'Affecter un rôle' }))
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/' + org + '/role-assignments', expect.objectContaining({
      method: 'POST', body: expect.objectContaining({ user_id: '43', role_id: role.id, scope_type: type, scope_id: version }),
    })))
  })

})
