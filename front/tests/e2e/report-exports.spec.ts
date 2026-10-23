import { expect, test } from '@playwright/test'

test('exports privés : confirmation, téléchargement, source modifiée et lecture mobile', async ({ page }) => {
  page.on('pageerror', (error) => { throw error })
  const org = '11111111-1111-4111-8111-111111111111', revision = '22222222-2222-4222-8222-222222222222', id = '33333333-3333-4333-8333-333333333333'
  const root = 'tenants/' + org, headers = { 'Access-Control-Allow-Origin': new URL(test.info().project.use.baseURL ?? 'http://localhost:5173').origin, 'Access-Control-Allow-Credentials': 'true' }
  const definition = { id: 'active_people', label: 'Effectif actif', definition: 'Fiches actives de l’organisation.', period_kind: 'snapshot', scope: 'organization', indicator_supported: true, status: 'available', reason: null }
  let writes = 0, ready = false, stale = false
  const now = new Date().toISOString(), expires = new Date(Date.now() + 3_600_000).toISOString()
  const row = { id, card: 'active_people', label: 'Effectif actif', view_label: 'Direction', period_kind: 'snapshot', view: 'direction', from: '2026-10-21', to: '2026-10-25', timezone: 'UTC', row_count: 0, created_at: now, generated_at: now, expires_at: expires }
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request(), url = new URL(request.url()), path = url.pathname.replace('/api/v1/', '')
    if (request.method() === 'OPTIONS') {
      await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET,POST', 'Access-Control-Allow-Headers': 'Content-Type,X-Tenant-Revision,X-XSRF-TOKEN' } }); return
    }
    const context = { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'], permissions: ['organization.view', 'reporting.export', 'reporting.direction', 'sports.read'], actor_user_id: 42, is_owner: false, membership_id: org }
    let data: unknown = []
    if (path === 'auth/me') data = { id: 42, name: 'Lecteur', email: 'reader@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (path === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (path.endsWith('/game-profile-catalogue')) data = { games: [] }
    if (path === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: org }]
    if (path === 'active-organization') data = { context, revision, confirmed: true }
    if (path === root + '/dashboards') data = { organization_id: org, timezone: 'UTC', max_days: 31, views: [{ id: 'direction', label: 'Direction', cards: [definition] }] }
    if (path.endsWith('/indicator')) data = { enabled: false, checked_at: now, state: 'missing', engine_version: 'rep-002.v1', result: null, history: null }
    if (path === root + '/exports') {
      expect(request.headers()['x-tenant-revision']).toBe(revision)
      if (request.method() === 'POST') {
        writes++
        expect(request.postDataJSON()).toEqual({ card: 'active_people', view: 'direction', from: row.from, to: row.to, confirm: true, request_key: expect.stringMatching(/^[a-f0-9-]{36}$/) })
        data = { ...row, state: 'queued' }
      } else data = { checked_at: now, enabled: true, max_rows: 500, lifetime_minutes: 60, items: writes ? [{ ...row, state: ready ? 'completed' : 'queued' }] : [], meta: { current_page: 1, last_page: 1, total: writes ? 1 : 0 } }
    }
    if (path === root + '/exports/' + id + '/download') {
      expect(request.method()).toBe('GET'); expect(request.headers()['x-tenant-revision']).toBe(revision)
      if (stale) {
        await route.fulfill({ status: 409, headers, contentType: 'application/json', body: JSON.stringify({ message: 'La source a changé. Créez un nouvel export.', code: 'REPORT_EXPORT_STALE', errors: {}, meta: { request_id: 'rep004-e2e' } }) })
      } else await route.fulfill({ status: 200, headers: { ...headers, 'Content-Disposition': 'attachment; filename="report.csv"' }, contentType: 'text/csv', body: 'id;label;state\r\n' })
      return
    }
    await route.fulfill({ status: 200, headers, contentType: 'application/json', body: JSON.stringify({ data, meta: { request_id: 'rep004-e2e', current_page: 1, last_page: 1, total: Array.isArray(data) ? data.length : 0, can_manage: false }, ...(request.method() === 'POST' ? { notification: { type: 'success', message: 'Export demandé.' } } : {}) }) })
  })
  await page.setViewportSize({ width: 375, height: 812 }); await page.goto('/')
  const dashboard = page.getByRole('region', { name: 'Tableaux de bord par rôle', exact: true })
  await dashboard.getByRole('button', { name: 'Ouvrir les tableaux de bord' }).click()
  const filters = dashboard.getByRole('form', { name: 'Filtres du tableau de bord' })
  await filters.getByLabel('Début de la fenêtre').fill(row.from); await filters.getByLabel('Fin de la fenêtre').fill(row.to)
  await filters.getByRole('button', { name: 'Consulter le tableau de bord' }).click()
  const panel = page.getByRole('region', { name: 'Exports privés de pilotage', exact: true })
  await panel.getByRole('button', { name: 'Ouvrir mes exports' }).click()
  await expect(panel.getByText('Aucun export accessible.')).toBeVisible(); expect(writes).toBe(0)
  await panel.getByRole('button', { name: 'Demander l’export CSV' }).click(); expect(writes).toBe(0)
  await panel.getByRole('checkbox').check(); await panel.getByRole('button', { name: 'Demander l’export CSV' }).click()
  await panel.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await expect(panel.getByText(/En attente de génération/)).toBeVisible(); expect(writes).toBe(1)
  await page.getByRole('button', { name: 'Fermer la notification' }).click()
  ready = true; await panel.getByRole('button', { name: 'Actualiser mes exports' }).click()
  await expect(panel.getByText('Lignes : 0')).toBeVisible()
  await expect(panel.getByText('Effectif actif · Direction · Prêt')).toBeVisible()
  for (const width of [375, 320]) {
    await page.setViewportSize({ width, height: 812 })
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
    await panel.screenshot({ path: 'reports/rep004-' + width + '.png', style: 'header { visibility: hidden; }' })
  }
  const downloadEvent = page.waitForEvent('download')
  await panel.getByRole('button', { name: 'Télécharger le CSV' }).click()
  const download = await downloadEvent
  expect(download.suggestedFilename()).toBe('report-' + id + '.csv')
  expect(await download.failure()).toBeNull()
  stale = true
  await panel.getByRole('button', { name: 'Télécharger le CSV' }).click()
  await expect(panel.getByText('La source a changé. Créez un nouvel export.')).toBeVisible()
  await expect(panel.getByRole('button', { name: 'Télécharger le CSV' })).toHaveCount(0)
})
