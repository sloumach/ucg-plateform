import { expect, test } from '@playwright/test'
import type { CalendarFeed } from '../../src/api/calendarFeed'

test('ICS privé : création confirmée, secret éphémère et révocation sur mobile', async ({ page }) => {
  const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222', version = '33333333-3333-4333-8333-333333333333'
  const root = 'tenants/' + org + '/calendar', secret = 'https://calendar.example.test/api/v1/calendar-subscriptions/' + org + '/' + id + '.ics?token=' + 'a'.repeat(64)
  const headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }
  let confirmed = false, issues = 0, revocations = 0, feed: CalendarFeed | null = null
  const errors: string[] = []
  page.on('pageerror', (error) => errors.push(error.message))
  page.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()) })
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request(), path = new URL(request.url()).pathname.replace('/api/v1/', ''), method = request.method()
    if (method === 'OPTIONS') { await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET, POST, PATCH', 'Access-Control-Allow-Headers': 'Content-Type, X-Tenant-Revision, X-XSRF-TOKEN' } }); return }
    let data: unknown = [], status = 200
    if (path === 'auth/me') data = { id: 42, name: 'Membre calendrier', email: 'calendar@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (path === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (path === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: id }]
    if (path === 'active-organization/confirmation') confirmed = true
    if (path === 'active-organization' || path === 'active-organization/confirmation') data = {
      context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'],
        permissions: ['organization.view', 'calendar.read', 'calendar.export'], is_owner: false, actor_user_id: 42, membership_id: id }, revision: version, confirmed }
    if (path === root + '/feed') {
      if (method === 'POST') {
        expect(confirmed).toBe(true); expect(request.headers()['x-tenant-revision']).toBe(version)
        expect(request.postDataJSON()).toEqual({ expected_version: null, before_days: 7, after_days: 60, expires_in_days: 30, confirm_export: true })
        feed = { id, version, before_days: 7, after_days: 60, expires_at: '2026-11-17T10:00:00Z', revoked_at: null, status: 'active' }
        issues++; status = 201; data = { feed, url: secret }
      } else if (method === 'PATCH') {
        expect(request.postDataJSON()).toEqual({ version, confirm_revocation: true })
        expect(request.headers()['x-tenant-revision']).toBe(version)
        feed = { ...feed!, version: id, status: 'revoked', revoked_at: '2026-10-17T10:00:00Z' }; revocations++; data = feed
      } else data = feed
    }
    await route.fulfill({ status, headers, contentType: 'application/json', body: JSON.stringify({ data, meta: { current_page: 1, last_page: 1, total: 0 }, calendar: { viewer_timezone: 'UTC', display_timezone: 'UTC' } }) })
  })
  await page.setViewportSize({ width: 375, height: 844 }); await page.goto('/')
  const calendar = page.getByRole('region', { name: 'Calendrier des activités' })
  await calendar.getByRole('button', { name: 'Ouvrir le calendrier' }).click()
  const panel = calendar.getByRole('region', { name: 'Mon calendrier ICS privé' })
  await panel.getByRole('button', { name: 'Gérer mon lien ICS' }).click()
  await panel.getByLabel('J’autorise l’export des titres et horaires vers mon calendrier externe').check()
  await panel.getByRole('button', { name: 'Créer le lien privé' }).click(); expect(issues).toBe(0)
  await panel.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await expect(panel.getByLabel('Lien secret ICS')).toHaveValue(secret); expect(issues).toBe(1)
  expect(await panel.locator('a').count()).toBe(0)
  expect(await page.evaluate(() => JSON.stringify({ ...localStorage, ...sessionStorage }))).not.toContain('token=')
  await expect.poll(async () => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
  await panel.screenshot({ path: 'reports/cal005-secret-mobile.png', style: 'header, [aria-label="Notifications"] { visibility: hidden !important; }' })
  await panel.getByRole('button', { name: 'Fermer le lien ICS' }).click()
  await panel.getByRole('button', { name: 'Gérer mon lien ICS' }).click()
  await expect(panel.getByText('Lien actif', { exact: true })).toBeVisible()
  await expect(panel.getByLabel('Lien secret ICS')).toHaveCount(0)
  await panel.getByLabel('Je confirme la révocation immédiate de ce lien').check()
  await panel.getByRole('button', { name: 'Révoquer le lien privé' }).click()
  expect(revocations).toBe(0)
  await panel.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await expect(panel.getByText('Lien révoqué', { exact: true })).toBeVisible(); expect(revocations).toBe(1)
  await page.setViewportSize({ width: 320, height: 844 })
  await expect.poll(async () => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
  await panel.screenshot({ path: 'reports/cal005-revoked-mobile.png', style: 'header, [aria-label="Notifications"] { visibility: hidden !important; }' })
  expect(errors).toEqual([])
})