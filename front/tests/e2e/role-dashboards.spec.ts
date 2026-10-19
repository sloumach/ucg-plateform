import { expect, test } from '@playwright/test'
import type { DashboardCatalogue, DashboardCard, DashboardCardDefinition } from '../../src/api/dashboards'

test('tableaux de bord : vues autorisées, lecture privée, erreur indépendante et affichage mobile', async ({ page }) => {
  page.on('pageerror', (error) => { throw error })
  const org = '11111111-1111-4111-8111-111111111111', revision = '22222222-2222-4222-8222-222222222222'
  const card: DashboardCardDefinition = { id: 'active_people', label: 'Effectif actif', definition: 'Fiches actives de cette organisation. Ne représente ni un roster ni une éligibilité.', period_kind: 'snapshot', scope: 'organization', status: 'available', reason: null }
  const catalogue: DashboardCatalogue = { organization_id: org, timezone: 'Europe/Paris', max_days: 31, views: [{ id: 'direction', label: 'Direction', cards: [card, { ...card, id: 'budgets', label: 'Budgets', status: 'unavailable', reason: 'La source financière reste indisponible.' }] }] }
  const root = 'tenants/' + org + '/dashboards', headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }
  let reads = 0, writes = 0, failure = false
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request(), url = new URL(request.url()), path = url.pathname.replace('/api/v1/', '')
    if (request.method() === 'OPTIONS') { await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET', 'Access-Control-Allow-Headers': 'Content-Type,X-Tenant-Revision,X-XSRF-TOKEN' } }); return }
    if (request.method() !== 'GET') writes++
    const context = { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'Europe/Paris', language: 'fr', roles: ['member'], permissions: ['organization.view', 'reporting.direction', 'sports.read', 'financial.read'], actor_user_id: 42, is_owner: false, membership_id: org }
    let data: unknown = []
    if (path === 'auth/me') data = { id: 42, name: 'Lecteur', email: 'reader@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (path === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (path.endsWith('/game-profile-catalogue')) data = { games: [] }
    if (path === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: org }]
    if (path === 'active-organization') data = { context, revision, confirmed: false }
    if (path === root) data = catalogue
    if (path.startsWith(root + '/')) {
      expect(path).toBe(root + '/active_people'); expect(request.headers()['x-tenant-revision']).toBe(revision)
      expect(url.searchParams.get('view')).toBe('direction'); expect(url.searchParams.get('from')).toBe('2026-10-19'); expect(url.searchParams.get('to')).toBe('2026-10-25')
      reads++
      if (failure) { await route.fulfill({ status: 409, headers, contentType: 'application/json', body: JSON.stringify({ message: 'Source temporairement indisponible.', code: 'REPORTING_SOURCE_UNAVAILABLE', errors: {}, meta: { request_id: 'rep001-e2e' } }) }); return }
      const response: DashboardCard = { ...card, organization_id: org, view: 'direction', captured_at: '2026-10-19T10:00:00Z', total: 1, period: { kind: 'snapshot', from: null, to: null, timezone: 'Europe/Paris' }, items: [{ id: 'person', label: '<script>Joueur</script> ' + 'RéférenceLongue'.repeat(6), state: 'active', at: null, value: null }], meta: { current_page: 1, last_page: 1, per_page: 5, total: 1 } }
      data = response
    }
    await route.fulfill({ status: 200, headers, contentType: 'application/json', body: JSON.stringify({ data, meta: { request_id: 'rep001-e2e', current_page: 1, last_page: 1, total: Array.isArray(data) ? data.length : 0, can_manage: false } }) })
  })
  await page.setViewportSize({ width: 375, height: 812 }); await page.goto('/')
  const panel = page.getByRole('region', { name: 'Tableaux de bord par rôle', exact: true })
  await panel.getByRole('button', { name: 'Ouvrir les tableaux de bord' }).click()
  const form = panel.getByRole('form', { name: 'Filtres du tableau de bord' })
  await expect(form.getByRole('option')).toHaveCount(1)
  await form.getByLabel('Début de la fenêtre').fill('2026-10-19'); await form.getByLabel('Fin de la fenêtre').fill('2026-10-25')
  expect(reads).toBe(0); await form.getByRole('button', { name: 'Consulter le tableau de bord' }).click()
  const feed = panel.getByRole('article', { name: 'Effectif actif' })
  await expect(feed.getByText('Total accessible : 1')).toBeVisible()
  const initialReads = reads
  await expect(feed.getByText(/Instantané actuel/)).toBeVisible()
  const budget = panel.getByRole('article', { name: 'Budgets' })
  await expect(budget.getByText('La source financière reste indisponible.')).toBeVisible(); await expect(budget.getByText(/Total accessible/)).toHaveCount(0)
  expect(await panel.locator('script,img,iframe').count()).toBe(0)
  for (const width of [375, 320]) {
    await page.setViewportSize({ width, height: 812 })
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
    await feed.screenshot({ path: 'reports/rep001-' + width + '.png', style: 'header { visibility: hidden; }' })
  }
  failure = true; await feed.getByRole('button', { name: 'Actualiser cette carte' }).click()
  await expect(feed.getByText('Source temporairement indisponible.')).toBeVisible(); await expect(feed.getByText(/Total accessible/)).toHaveCount(0)
  await expect(budget.getByText('La source financière reste indisponible.')).toBeVisible()
  failure = false; await feed.getByRole('button', { name: 'Réessayer' }).click(); await expect(feed.getByText('Total accessible : 1')).toBeVisible()
  await form.getByLabel('Début de la fenêtre').fill('2026-10-18')
  await expect(panel.getByRole('region', { name: 'Résultats du tableau de bord' })).toHaveCount(0)
  expect(reads).toBe(initialReads + 2); expect(writes).toBe(0)
})
