import { expect, test } from '@playwright/test'

test('recherche tenant : suggestions, pagination, filtres et lecture mobile', async ({ page }) => {
  page.on('pageerror', (error) => { throw error })
  const org = '11111111-1111-4111-8111-111111111111', revision = '22222222-2222-4222-8222-222222222222'
  const root = 'tenants/' + org + '/search', headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }
  let searches = 0, failure = false
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request(), url = new URL(request.url()), path = url.pathname.replace('/api/v1/', '')
    if (request.method() === 'OPTIONS') { await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET', 'Access-Control-Allow-Headers': 'Content-Type,X-Tenant-Revision,X-XSRF-TOKEN' } }); return }
    expect(request.method()).toBe('GET')
    const context = { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'Europe/Paris', language: 'fr', roles: ['member'], permissions: ['organization.view', 'sports.read'], actor_user_id: 42, is_owner: false, membership_id: org }
    let data: unknown = []
    if (path === 'auth/me') data = { id: 42, name: 'Lecteur', email: 'reader@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (path === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (path.endsWith('/game-profile-catalogue')) data = { games: [] }
    if (path === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: org }]
    if (path === 'active-organization') data = { context, revision, confirmed: true }
    if (path === root + '/types') data = { types: ['people', 'teams', 'matches'] }
    if (path === root) {
      searches++; expect(request.headers()['x-tenant-revision']).toBe(revision)
      if (failure) { await route.fulfill({ status: 409, headers, contentType: 'application/json', body: JSON.stringify({ message: 'Recherche momentanément indisponible.', code: 'SEARCH_SOURCE_UNAVAILABLE', errors: {}, meta: { request_id: 'rep003-e2e' } }) }); return }
      const next = url.searchParams.has('cursor'), suggest = url.searchParams.get('mode') === 'suggest'
      data = { type: url.searchParams.get('type'), items: [{ id: next ? 'record-2' : 'record-1', label: next ? 'Alpha second' : 'Alpha <script>texte</script>', reference: null, parent_id: null }], meta: { per_page: suggest ? 5 : 10, examined: 1, scan_limit: 100, has_more: !next, next_cursor: next ? null : 'opaque-next' } }
    }
    await route.fulfill({ status: 200, headers, contentType: 'application/json', body: JSON.stringify({ data, meta: { request_id: 'rep003-e2e', current_page: 1, last_page: 1, total: Array.isArray(data) ? data.length : 0, can_manage: false } }) })
  })
  await page.setViewportSize({ width: 375, height: 812 }); await page.goto('/')
  const panel = page.getByRole('region', { name: 'Rechercher dans l’organisation', exact: true })
  await panel.getByRole('button', { name: 'Ouvrir la recherche' }).click(); expect(searches).toBe(0)
  const input = panel.getByLabel('Texte à rechercher'); await input.fill('Al')
  await panel.getByRole('button', { name: 'Alpha <script>texte</script>' }).click()
  const results = panel.getByRole('region', { name: 'Résultats de recherche', exact: true })
  await expect(results.getByText('Alpha <script>texte</script>', { exact: true })).toBeVisible()
  await expect(panel.locator('script')).toHaveCount(0)
  for (const width of [375, 320]) {
    await page.setViewportSize({ width, height: 812 }); expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
    await panel.screenshot({ path: 'reports/rep003-' + width + '.png', style: 'header { visibility: hidden; }' })
  }
  await results.getByRole('button', { name: 'Continuer la recherche' }).click(); await expect(results.getByText('Alpha second')).toBeVisible()
  await panel.getByLabel('Catégorie').selectOption('teams'); await expect(results).toHaveCount(0)
  await input.fill('Al'); await panel.getByRole('button', { name: 'Rechercher', exact: true }).click(); await expect(results).toBeVisible()
  failure = true; await results.getByRole('button', { name: 'Recommencer depuis le début' }).click(); await expect(panel.getByText('Recherche momentanément indisponible.')).toBeVisible()
  await expect(results).toHaveCount(0)
})
