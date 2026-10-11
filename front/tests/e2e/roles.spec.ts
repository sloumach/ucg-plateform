import { expect, test } from '@playwright/test'

test('création de rôle confirmée, permissions explicites et affichage mobile', async ({ page }) => {
  const org = '11111111-1111-4111-8111-111111111111'
  const revision = '22222222-2222-4222-8222-222222222222'
  const roleId = '33333333-3333-4333-8333-333333333333'
  let confirmed = false
  let created = false
  const headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }
  const roles: Record<string, unknown>[] = []
  const meta = { current_page: 1, last_page: 1, total: 0, request_id: 'browser-role-test' }
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const path = new URL(route.request().url()).pathname.replace('/api/v1/', '')
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
    if (path.endsWith('/permission-catalogue')) data = { permissions: [{ key: 'sports.read', privileged: false, organization_only: false }], scope_types: ['organization'], can_manage: true }
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
  await page.goto('/')
  await page.getByRole('button', { name: 'Créer un rôle' }).click()
  await page.getByLabel('Clé du rôle').fill('coach')
  await page.getByLabel('Nom du rôle').fill('Coach')
  await page.getByLabel('Consulter les données sportives').check()
  await page.getByRole('button', { name: 'Enregistrer le rôle' }).click()
  const confirmation = page.getByRole('region', { name: 'Confirmer l’action' })
  await expect(confirmation).toBeFocused()
  await expect(confirmation).toContainText('Coach')
  await expect(confirmation).toContainText('Alpha')
  expect(created).toBe(false)
  await confirmation.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await expect(page.getByRole('button', { name: 'Modifier le rôle Coach' })).toBeVisible()
  expect(created).toBe(true)
  await page.setViewportSize({ width: 375, height: 812 })
  await expect(page.getByRole('button', { name: 'Affecter un rôle' })).toBeVisible()
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
  await page.screenshot({ path: 'reports/iam003-roles-mobile.png', fullPage: true })
})
