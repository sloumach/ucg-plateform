import { expect, test } from '@playwright/test'
import type { DashboardIndicator } from '../../src/api/dashboards'

test('indicateurs différés : confirmation, fraîcheur, erreur et lecture mobile', async ({ page }) => {
  page.on('pageerror', (error) => { throw error })
  const org = '11111111-1111-4111-8111-111111111111', revision = '22222222-2222-4222-8222-222222222222'
  const root = 'tenants/' + org + '/dashboards', indicatorPath = root + '/active_people/indicator'
  const headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }
  const definition = { id: 'active_people', label: 'Effectif actif', definition: 'Fiches actives de cette organisation, sans déduire une éligibilité.', period_kind: 'snapshot', scope: 'organization', indicator_supported: true, status: 'available', reason: null }
  let state: DashboardIndicator['state'] = 'missing', writes = 0, liveReads = 0, failure = false
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request(), url = new URL(request.url()), path = url.pathname.replace('/api/v1/', '')
    if (request.method() === 'OPTIONS') { await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET,POST', 'Access-Control-Allow-Headers': 'Content-Type,X-Tenant-Revision,X-XSRF-TOKEN' } }); return }
    const context = { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'Europe/Paris', language: 'fr', roles: ['member'], permissions: ['organization.view', 'reporting.direction', 'sports.read'], actor_user_id: 42, is_owner: false, membership_id: org }
    let data: unknown = []
    if (path === 'auth/me') data = { id: 42, name: 'Lecteur', email: 'reader@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (path === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (path.endsWith('/game-profile-catalogue')) data = { games: [] }
    if (path === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: org }]
    if (path === 'active-organization') data = { context, revision, confirmed: true }
    if (path === root) data = { organization_id: org, timezone: 'Europe/Paris', max_days: 31, views: [{ id: 'direction', label: 'Direction', cards: [definition] }] }
    if (path === root + '/active_people') { liveReads++; data = { ...definition, captured_at: '2026-10-20T10:00:00Z', total: 0, items: [], meta: null } }
    if (request.method() !== 'GET') {
      writes++; expect(path).toBe(indicatorPath); expect(request.method()).toBe('POST')
      expect(request.postDataJSON()).toEqual({ view: 'direction', from: '2026-10-20', to: '2026-10-25', confirm: true })
      expect(request.headers()['x-tenant-revision']).toBe(revision); state = 'queued'
    }
    if (path === indicatorPath) {
      if (failure) { await route.fulfill({ status: 409, headers, contentType: 'application/json', body: JSON.stringify({ message: 'Indicateur indisponible temporairement.', code: 'SOURCE_UNAVAILABLE', errors: {}, meta: { request_id: 'rep002-e2e' } }) }); return }
      const hasRevision = state === 'fresh' || state === 'stale'
      data = { enabled: true, checked_at: '2026-10-20T10:00:00Z', engine_version: 'rep-002.v1', id: state === 'missing' ? null : revision,
        version: state === 'missing' ? null : revision, state, evaluated_at: hasRevision ? '2026-10-20T10:00:00Z' : null,
        expires_at: hasRevision ? '2026-10-20T10:05:00Z' : null, revision: hasRevision ? 1 : null,
        result: hasRevision ? { total: state === 'fresh' ? 0 : 99999, groups: [{ state: 'active', count: 0 }] } : null,
        history: hasRevision ? { items: [{ number: 1, engine_version: 'rep-002.v1', evaluated_at: '2026-10-20T10:00:00Z', expires_at: '2026-10-20T10:05:00Z' }], meta: { current_page: 1, last_page: 1, total: 1 } } : null } satisfies DashboardIndicator
    }
    await route.fulfill({ status: 200, headers, contentType: 'application/json', body: JSON.stringify({ data, meta: { request_id: 'rep002-e2e', current_page: 1, last_page: 1, total: Array.isArray(data) ? data.length : 0, can_manage: false }, ...(request.method() === 'POST' ? { notification: { type: 'success', message: 'Demande prise en compte.' } } : {}) }) })
  })
  await page.setViewportSize({ width: 375, height: 812 }); await page.goto('/')
  const panel = page.getByRole('region', { name: 'Tableaux de bord par rôle', exact: true })
  await panel.getByRole('button', { name: 'Ouvrir les tableaux de bord' }).click()
  const form = panel.getByRole('form', { name: 'Filtres du tableau de bord' })
  await form.getByLabel('Début de la fenêtre').fill('2026-10-20'); await form.getByLabel('Fin de la fenêtre').fill('2026-10-25')
  await form.getByRole('button', { name: 'Consulter le tableau de bord' }).click()
  const feed = panel.getByRole('article', { name: 'Effectif actif' })
  await expect(feed.getByText('Aucun calcul enregistré pour ces filtres.')).toBeVisible(); expect(writes).toBe(0); expect(liveReads).toBe(0)
  await feed.getByRole('button', { name: 'Demander le calcul de cet indicateur' }).click(); expect(writes).toBe(0)
  await feed.getByRole('checkbox').check(); await feed.getByRole('button', { name: 'Demander le calcul de cet indicateur' }).click()
  await expect(feed.getByText('Calcul demandé, résultat en attente.')).toBeVisible(); expect(writes).toBe(1)
  await page.getByRole('button', { name: 'Fermer la notification' }).click()
  state = 'fresh'; await feed.getByRole('button', { name: 'Vérifier la fraîcheur et le résultat' }).click()
  await expect(feed.getByText('Total calculé accessible : 0')).toBeVisible()
  await expect(feed.getByText(/Version du calcul : rep-002.v1/)).toBeVisible()
  for (const width of [375, 320]) {
    await page.setViewportSize({ width, height: 812 }); expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
    await feed.screenshot({ path: 'reports/rep002-' + width + '.png', style: 'header { visibility: hidden; }' })
  }
  state = 'stale'; await feed.getByRole('button', { name: 'Vérifier la fraîcheur et le résultat' }).click()
  await expect(feed.getByText('Résultat périmé : un nouveau calcul est nécessaire.')).toBeVisible(); await expect(feed.getByText(/99999|Total calculé accessible/)).toHaveCount(0)
  failure = true; await feed.getByRole('button', { name: 'Vérifier la fraîcheur et le résultat' }).click()
  await expect(feed.getByText('Indicateur indisponible temporairement.')).toBeVisible()
  await feed.getByRole('button', { name: 'Consulter les détails actuels' }).click(); await expect(feed.getByText('Total accessible : 0')).toBeVisible()
  expect(liveReads).toBeGreaterThan(0); expect(writes).toBe(1)
  await form.getByLabel('Début de la fenêtre').fill('2026-10-19'); await expect(panel.getByRole('region', { name: 'Résultats du tableau de bord' })).toHaveCount(0)
})
