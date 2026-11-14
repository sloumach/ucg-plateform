import { expect, test } from '@playwright/test'
import { readFileSync } from 'node:fs'

const configurablePermissions = [...readFileSync(new URL('../../../back/app/Modules/Tenancy/Domain/Permission.php', import.meta.url), 'utf8')
  .matchAll(/case \w+ = '([^']+)';/g)]
  .map((match) => match[1])
  .filter((key) => key !== 'roles.manage')
  .map((key) => ({ key, privileged: !['sports.read', 'organization.view'].includes(key), organization_only: false }))

test('création de rôle confirmée, permissions explicites et affichage mobile', async ({ page }) => {
  const org = '11111111-1111-4111-8111-111111111111'
  const revision = '22222222-2222-4222-8222-222222222222'
  const roleId = '33333333-3333-4333-8333-333333333333'
  let confirmed = false
  let created = false
  let assignedTo: string | undefined
  const headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }
  const roles: Record<string, unknown>[] = []
  const meta = { current_page: 1, last_page: 1, total: 0, request_id: 'browser-role-test' }
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url())
    const path = url.pathname.replace('/api/v1/', '')
    const method = route.request().method()
    if (method === 'OPTIONS') {
      await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET, POST, PUT', 'Access-Control-Allow-Headers': 'Content-Type, X-Tenant-Revision, X-XSRF-TOKEN' } })
      return
    }
    let data: unknown = []
    let status = 200
    let notification: unknown
    if (path === 'auth/me') data = { id: 42, name: 'Propriétaire', email: 'owner@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (path === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (path === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['owner'], is_owner: true, membership_id: null }]
    if (path === 'active-organization/confirmation') {
      expect(route.request().postDataJSON()).toEqual({ organization_id: org, revision, confirm: true })
      confirmed = true
    }
    if (path === 'active-organization' || path === 'active-organization/confirmation') data = {
      context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['owner'], permissions: ['organization.view', 'roles.manage', 'roles.assign'], is_owner: true, actor_user_id: 42, membership_id: null }, revision, confirmed,
    }
    if (path.endsWith('/permission-catalogue')) data = { permissions: configurablePermissions, scope_types: ['organization'], can_manage: true }
    if (path.endsWith('/role-assignment-members')) {
      data = [
        { id: 43, name: 'Alex Martin', email: 'alex@example.test' },
        { id: 44, name: 'Alex Martin', email: 'coach@example.test' },
      ].filter((member) => (member.name + ' ' + member.email).includes(url.searchParams.get('q') ?? ''))
    }
    if (path.endsWith('/role-assignments') && method === 'POST') {
      expect(confirmed).toBe(true)
      expect(route.request().headers()['x-tenant-revision']).toBe(revision)
      const input = route.request().postDataJSON()
      expect(input.role_id).toBe(roleId)
      assignedTo = input.user_id
      status = 201
    }
    if (path.endsWith('/roles')) {
      if (method === 'POST') {
        expect(confirmed).toBe(true)
        expect(route.request().headers()['x-tenant-revision']).toBe(revision)
        const input = route.request().postDataJSON()
        expect(input).toEqual({ key: 'coach', name: 'Coach', permissions: ['sports.read'], active: true })
        const role = { ...input, id: roleId, organization_id: org, privileged: false, version: revision }
        roles.push(role); data = role; created = true; status = 201
        notification = { type: 'success', message: 'Le rôle a été enregistré.' }
      } else data = roles
    }
    await route.fulfill({ status, headers, json: { data, meta, ...(notification ? { notification } : {}) } })
  })
  await page.goto('/?rubrique=acces&outil=roles')
  await page.getByRole('button', { name: 'Créer un rôle' }).click()
  await page.getByLabel('Clé du rôle').fill('coach')
  await page.getByLabel('Nom du rôle').fill('Coach')
  const group = page.getByRole('button', { name: /Équipes, jeux et suivi sportif/ })
  await expect(group).toHaveAttribute('aria-expanded', 'false')
  await group.click()
  await page.getByLabel('Consulter les données sportives').check()
  await group.click()
  await expect(page.getByLabel('Consulter les données sportives')).toHaveCount(0)
  const editor = page.getByRole('form', { name: 'Enregistrer le rôle' })
  await expect(editor.getByText(`Sélection : 1 sur ${configurablePermissions.length}`)).toBeVisible()
  await editor.screenshot({ path: 'reports/ui-role-permissions-bureau.png', style: 'header { visibility: hidden; }' })
  await page.setViewportSize({ width: 320, height: 812 })
  await editor.getByRole('searchbox').fill('donnees sportives')
  await expect(editor.getByLabel('Consulter les données sportives')).toBeChecked()
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
  await editor.screenshot({ path: 'reports/ui-role-permissions-mobile.png', style: 'header { visibility: hidden; }' })
  await page.getByRole('button', { name: 'Enregistrer le rôle' }).click()
  const confirmation = page.getByRole('region', { name: 'Confirmer l’action' })
  await expect(confirmation).toBeFocused()
  await expect(confirmation).toContainText('Coach')
  await expect(confirmation).toContainText('Alpha')
  expect(created).toBe(false)
  await confirmation.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await expect(page.getByRole('button', { name: 'Modifier le rôle Coach' })).toBeVisible()
  await expect(page.getByRole('form', { name: 'Affecter un rôle' })).toHaveCount(0)
  expect(created).toBe(true)
  await page.getByRole('link', { name: 'Affectations', exact: true }).click()
  await expect(page.getByRole('button', { name: 'Créer un rôle' })).toHaveCount(0)
  await expect(page.getByRole('form', { name: 'Affecter un rôle' }).getByRole('option', { name: 'Coach', exact: true })).toHaveCount(1)
  for (const width of [375, 320]) {
    await page.setViewportSize({ width, height: 812 })
    await expect(page.getByRole('button', { name: 'Affecter un rôle' })).toBeVisible()
    const start = page.getByLabel('Début de l’affectation')
    const end = page.getByLabel('Fin de l’affectation (facultative)')
    await start.fill('2026-10-11T14:30:05')
    await end.fill('2026-10-12T14:30:05')
    await expect(start).toHaveValue('2026-10-11T14:30:05')
    await expect(end).toHaveValue('2026-10-12T14:30:05')
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
  }
  await page.screenshot({ path: 'reports/iam003-roles-mobile.png', fullPage: true })
  await page.setViewportSize({ width: 1280, height: 900 })
  const assignment = page.getByRole('form', { name: 'Affecter un rôle' })
  const picker = assignment.getByRole('group', { name: 'Choisir un membre' })
  const search = picker.getByRole('searchbox', { name: 'Rechercher par nom ou e-mail' })
  const role = assignment.getByRole('combobox', { name: 'Rôle à affecter' })
  const scope = assignment.getByRole('combobox', { name: 'Périmètre' })
  const roleHeight = await role.evaluate((select) => select.offsetHeight)
  const searchHeight = await search.evaluate((input) => input.offsetHeight)
  expect(Math.abs(roleHeight - searchHeight)).toBeLessThanOrEqual(2)
  expect(await role.evaluate((select) => select.offsetTop)).toBe(await scope.evaluate((select) => select.offsetTop))
  await expect(picker.getByRole('radio', { name: 'Alex Martin — alex@example.test' })).toBeVisible()
  await search.fill('aucun-résultat')
  await expect(picker.getByRole('status')).toHaveText('Aucun membre disponible pour cette recherche.')
  await search.fill('co')
  const coach = picker.getByRole('radio', { name: 'Alex Martin — coach@example.test' })
  await expect(coach).toBeVisible()
  await expect(picker.getByRole('radio', { name: 'Alex Martin — alex@example.test' })).toHaveCount(0)
  await coach.check()
  await role.selectOption(roleId)
  await assignment.getByRole('button', { name: 'Affecter un rôle' }).click()
  await expect(confirmation).toContainText('Alex Martin — coach@example.test')
  expect(assignedTo).toBeUndefined()
  await confirmation.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await expect.poll(() => assignedTo).toBe('44')
})
