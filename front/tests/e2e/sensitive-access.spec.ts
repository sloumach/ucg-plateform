import { expect, test } from '@playwright/test'

test('audit sensible : accès réservé, filtres et curseurs opaques sur mobile', async ({ page }) => {
  const org = '11111111-1111-4111-8111-111111111111', revision = '22222222-2222-4222-8222-222222222222'
  const resource = '33333333-3333-4333-8333-333333333333'
  let permissions = ['organization.view', 'audit.view'], reads = 0
  const errors: string[] = [], headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }
  const entry = { id: resource, source: 'tenancy.sensitive', organization_id: org, actor_user_id: 43,
    action: 'contract.document_downloaded', target: { type: 'contract_document_version', id: resource }, reason_code: 'internal_control',
    request_id: revision, ip_address: null, before: {}, after: { title: 'PRIVATE_CONTRACT_CONTENT' }, occurred_at: '2026-10-15T10:00:00Z' }
  page.on('pageerror', (error) => errors.push(error.message))
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request(), url = new URL(request.url()), path = url.pathname.replace('/api/v1/', '')
    if (request.method() === 'OPTIONS') {
      await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET, POST', 'Access-Control-Allow-Headers': 'Content-Type, X-Tenant-Revision, X-XSRF-TOKEN' } }); return
    }
    let data: unknown = [], next: string | null = null
    if (path === 'auth/me') data = { id: 42, name: 'Auditeur', email: 'audit@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (path === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (path === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: resource }]
    if (path === 'active-organization') data = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'Europe/Paris', language: 'fr',
      roles: ['member'], permissions, actor_user_id: 42, is_owner: false, membership_id: resource }, revision, confirmed: true }
    if (path === 'tenants/' + org + '/audit-events') {
      expect(permissions).toContain('audit.view'); expect(request.method()).toBe('GET')
      expect(request.headers()['x-tenant-revision']).toBe(revision)
      expect(url.searchParams.get('source')).toBe('tenancy.sensitive'); expect(url.searchParams.get('per_page')).toBe('10')
      reads++
      if (url.searchParams.has('action')) {
        expect(url.searchParams.get('action')).toBe('tenant.export_read'); expect(url.searchParams.get('actor_user_id')).toBe('43')
        expect(url.searchParams.has('cursor')).toBe(false)
        data = [{ ...entry, action: 'tenant.export_read', reason_code: 'operator_portability', target: { type: 'organization_export', id: resource } }]
      } else if (url.searchParams.has('cursor')) {
        expect(url.searchParams.get('cursor')).toBe('opaque-cursor')
        data = [{ ...entry, id: revision, action: 'contract.download_link_created' }]
      } else { data = [entry]; next = 'opaque-cursor' }
    }
    await route.fulfill({ status: 200, headers, json: { data, meta: { request_id: revision, current_page: 1, last_page: 1, per_page: 10, next_cursor: next } } })
  })
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/')
  await page.getByRole('button', { name: 'Journal des accès sensibles', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Téléchargement du document autorisé', exact: true })).toBeVisible()
  await page.getByRole('button', { name: 'Accès suivants', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Lien temporaire de téléchargement créé', exact: true })).toBeVisible()
  await page.getByRole('button', { name: 'Accès précédents', exact: true }).click()
  const form = page.getByRole('form', { name: 'Filtrer le journal', exact: true })
  await expect(form.getByLabel('Action', { exact: true })).toBeEnabled()
  await form.getByLabel('Action', { exact: true }).selectOption('tenant.export_read')
  await form.getByLabel('Identifiant du compte intervenant').fill('43')
  await form.getByRole('button', { name: 'Filtrer le journal', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Archive opérateur ouverte en lecture', exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Accès suivants', exact: true })).toBeDisabled()
  await expect(page.getByText('PRIVATE_CONTRACT_CONTENT', { exact: true })).toHaveCount(0)
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
  await page.getByRole('region', { name: 'Journal des accès sensibles', exact: true }).scrollIntoViewIfNeeded()
  await page.screenshot({ path: 'reports/ctr006-sensitive-audit-mobile.png' })
  const before = reads
  permissions = ['organization.view', 'administrative.read']
  await page.reload()
  await expect(page.getByRole('button', { name: 'Contrats de l’organisation', exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Journal des accès sensibles', exact: true })).toHaveCount(0)
  expect(reads).toBe(before); expect(errors).toEqual([])
})
