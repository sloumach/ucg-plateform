import { expect, test } from '@playwright/test'

test('sélection tenant, droits rechargés et confirmation explicite dans le navigateur', async ({ page }) => {
  const alpha = '11111111-1111-4111-8111-111111111111'
  const beta = '22222222-2222-4222-8222-222222222222'
  const revision = '33333333-3333-4333-8333-333333333333'
  let current = alpha
  let confirmed = false
  let invited = false
  const headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }
  const meta = { current_page: 1, last_page: 1, total: 0, request_id: 'test-request' }
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const path = new URL(route.request().url()).pathname.replace('/api/v1/', '')
    if (route.request().method() === 'OPTIONS') {
      await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET, POST, PUT, DELETE', 'Access-Control-Allow-Headers': 'Content-Type, X-Tenant-Revision, X-XSRF-TOKEN' } })
      return
    }
    let data: unknown = []
    if (path === 'auth/me') data = { id: 42, name: 'Test staff', email: 'staff@example.test' }
    if (path === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (path === 'accessible-organizations') data = [
      { id: alpha, name: 'Alpha', slug: 'alpha', roles: ['owner'], is_owner: true, membership_id: null },
      { id: beta, name: 'Beta', slug: 'beta', roles: ['member'], is_owner: false, membership_id: 'membership-id' },
    ]
    if (path === 'active-organization' && route.request().method() === 'POST') {
      current = route.request().postDataJSON().organization_id
      confirmed = false
    }
    if (path.startsWith('active-organization')) {
      if (path.endsWith('confirmation')) {
        expect(route.request().postDataJSON()).toEqual({ organization_id: current, revision, confirm: true })
        confirmed = true
      }
      data = {
        context: { organization_id: current, name: current === alpha ? 'Alpha' : 'Beta',
          slug: current === alpha ? 'alpha' : 'beta', timezone: 'UTC', language: 'fr', roles: current === alpha ? ['owner'] : ['member'],
          permissions: current === alpha ? ['organization.view', 'memberships.manage', 'invitations.manage'] : ['organization.view'],
          is_owner: current === alpha, actor_user_id: 42, membership_id: current === alpha ? null : 'membership-id' },
        revision, confirmed,
      }
    }
    if (path === 'tenants/' + alpha + '/invitations' && route.request().method() === 'POST') {
      expect(confirmed).toBe(true)
      expect(route.request().headers()['x-tenant-revision']).toBe(revision)
      expect(route.request().postDataJSON().email).toBe('member@example.test')
      invited = true
    }
    await route.fulfill({ headers, json: { data, meta, ...(invited ? { notification: { type: 'success', message: 'Invitation envoyée.' } } : {}) } })
  })

  await page.goto('/')
  await expect(page.getByText('Organisation active : Alpha')).toBeVisible()
  await page.getByLabel('E-mail de la personne invitée').fill('member@example.test')
  await page.getByRole('button', { name: 'Envoyer l’invitation' }).click()
  const confirmation = page.getByRole('region', { name: 'Confirmer l’action' })
  await expect(confirmation).toBeFocused()
  await expect(confirmation).toContainText('organisation Alpha')
  expect(invited).toBe(false)
  await confirmation.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await expect(page.getByText('Invitation envoyée.')).toBeVisible()
  await page.getByRole('button', { name: /Ouvrir Beta/ }).click()
  await expect(page.getByText('Organisation active : Beta')).toBeVisible()
  await expect(page.getByLabel('Contexte d’organisation')).toHaveText('Beta')
  await expect(page.getByRole('heading', { name: 'Inviter un membre' })).toHaveCount(0)
  await expect(page.getByRole('button', { name: 'Quitter cette organisation' })).toBeEnabled()
  await page.screenshot({ path: 'reports/tenancy-workspace.png', fullPage: true })
  await page.setViewportSize({ width: 375, height: 812 })
  await expect(page.getByLabel('Contexte d’organisation')).toHaveText('Beta')
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
})
