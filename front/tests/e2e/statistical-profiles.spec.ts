import { expect, test } from '@playwright/test'
import type { StatisticalProfileResponse, ProfileGroup } from '../../src/api/statisticalProfiles'
import type { MetricDefinition } from '../../src/api/metricCatalogue'
import type { StatisticalCalculationResult } from '../../src/api/statisticalCalculations'

test('profils privés : filtres, comparaison refusée, fraîcheur et lecture mobile sans mutation', async ({ page }) => {
  page.on('pageerror', (error) => { throw error })
  const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222', revision = '33333333-3333-4333-8333-333333333333', person = '44444444-4444-4444-8444-444444444444', second = '55555555-5555-4555-8555-555555555555'
  const metric: MetricDefinition = { id, game_id: id, code: 'POINTS', version: revision, number: 1, mode_id: null, role_id: null, release_id: null, scope: { game_id: id, game_key: 'pilot', game_name: 'Jeu pilote', mode_id: null, mode_name: null, role_id: null, role_name: null, release_id: null, release_name: null }, title: 'Points', definition: 'Mesure additive', unit: 'points', aggregation: 'sum', aggregation_level: 'period', formula: 'Somme documentée', zero_denominator: 'not_applicable', measurement_kind: 'objective', availability: 'manual', availability_note: 'Source déclarée', status: 'active', reason: 'Définition contrôlée', occurred_at: '2026-10-19T10:00:00Z' }
  const result: StatisticalCalculationResult = { engine_version: 'stat-004.v1', precision: 6, rounding: 'half_up', availability: 'available', value: '0', sample_size: 3, source_count: 1, included_count: 1, missing_count: 0, voided_count: 0, incomplete: false, numerator: null, denominator: null, reason: null }
  const group: ProfileGroup = { key: 'same-context', context: { source_kind: 'training', source_label: '<script>Source privée</script> ' + 'RéférenceLongue'.repeat(6), season: '2026', role_id: id, release_id: revision, map: '<img>Carte</img>', opponent: null }, context_labels: { role_id: 'Rôle pilote', release_id: 'Patch pilote' }, result, issue: null, source_count: 1, period_start: '2026-10-01', period_end: '2026-10-01', period_count: 1, coverage_version: 'periods', latest_observed_on: '2026-10-19', last_updated_at: '2026-10-19T10:00:00Z' }
  const pagination = <T,>(data: T[]) => ({ data, meta: { current_page: 1, last_page: 1, total: data.length, per_page: 10 } })
  const root = 'tenants/' + org + '/statistical-profiles', headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }
  let incompatible = false, reads = 0, writes = 0
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request(), url = new URL(request.url()), path = url.pathname.replace('/api/v1/', '')
    if (request.method() === 'OPTIONS') { await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET', 'Access-Control-Allow-Headers': 'Content-Type,X-Tenant-Revision,X-XSRF-TOKEN' } }); return }
    if (request.method() !== 'GET') writes++
    const context = { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'], permissions: ['organization.view', 'sports.read', 'sports.statistics.read'], actor_user_id: 42, is_owner: false, membership_id: id }
    let data: unknown = []
    if (path === 'auth/me') data = { id: 42, name: 'Lecteur', email: 'reader@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (path === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (path.endsWith('/game-profile-catalogue')) data = { games: [] }
    if (path === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: id }]
    if (path === 'active-organization') data = { context, revision, confirmed: false }
    if (path === 'metric-catalogue' || path === 'metric-catalogue/' + id + '/history') data = [metric]
    if (path === root + '/people') data = [{ id: person, label: '<img>Joueur A</img>' }, { id: second, label: 'Joueur B' }]
    if (path === root) {
      reads++
      expect(request.headers()['x-tenant-revision']).toBe(revision)
      expect(url.searchParams.getAll('people[]')).toEqual([person, second]); expect(url.searchParams.get('metric_version')).toBe(revision)
      expect(url.searchParams.get('season')).toBe('2026'); expect(url.searchParams.get('from')).toBe('2026-10-01')
      const right: ProfileGroup = { ...group, result: { ...result, value: '2', sample_size: 5 } }
      const response: StatisticalProfileResponse = {
        metric, filters: { from: '2026-10-01', to: '2026-10-18' }, generated_at: '2026-10-19T10:00:00Z', engine_version: result.engine_version, live: true,
        profiles: [
          { person_id: person, person_label: '<img>Joueur A</img>', source_count: 1, latest_observed_on: group.latest_observed_on, last_updated_at: group.last_updated_at, groups: pagination([group]), timeline: pagination([]) },
          { person_id: second, person_label: 'Joueur B', source_count: 1, latest_observed_on: group.latest_observed_on, last_updated_at: group.last_updated_at, groups: pagination([right]), timeline: pagination([]) },
        ],
        comparison: pagination([{ key: group.key, context: group.context, context_labels: group.context_labels, left: group, right, comparable: !incompatible, reason: incompatible ? 'PERIOD_MISMATCH' : null, difference: incompatible ? null : '-2', unequal_samples: true }]),
      }
      data = response
    }
    await route.fulfill({ status: 200, headers, contentType: 'application/json', body: JSON.stringify({ data, meta: { request_id: 'stat005-e2e', current_page: 1, last_page: 1, total: Array.isArray(data) ? data.length : 0, can_manage: false } }) })
  })
  await page.setViewportSize({ width: 375, height: 812 }); await page.goto('/')
  const panel = page.getByRole('region', { name: 'Profils et comparaisons statistiques', exact: true })
  await panel.getByRole('button', { name: 'Ouvrir les profils statistiques' }).click()
  const form = panel.getByRole('form', { name: 'Filtres des profils statistiques' })
  await form.getByLabel('Jeu et métrique', { exact: true }).selectOption(id)
  await form.getByLabel('Premier joueur', { exact: true }).selectOption(person)
  await form.getByLabel('Second joueur à comparer').selectOption(second)
  await form.getByLabel('Début de la fenêtre').fill('2026-10-01'); await form.getByLabel('Fin de la fenêtre').fill('2026-10-18')
  await form.getByLabel('Saison', { exact: true }).fill('2026')
  expect(reads).toBe(0); await form.getByRole('button', { name: 'Consulter les profils' }).click()
  const comparison = panel.getByRole('region', { name: 'Comparaison contextualisée', exact: true })
  await expect(comparison.getByText(/Écart.*-2/)).toBeVisible(); await expect(comparison.getByText(/Tailles d’échantillon différentes/)).toBeVisible()
  await expect(panel.getByText('Résultat : 0', { exact: true })).toBeVisible()
  expect(await panel.locator('script,img,iframe').count()).toBe(0)
  for (const width of [375, 320]) {
    await page.setViewportSize({ width, height: 812 })
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
    await comparison.screenshot({ path: 'reports/stat005-' + width + '.png', style: 'header { visibility: hidden; }' })
  }
  incompatible = true
  await panel.getByRole('button', { name: 'Actualiser ces profils' }).click()
  await expect(comparison.getByText(/les périodes couvertes diffèrent/)).toBeVisible()
  await expect(comparison.getByText(/Écart.*-2/)).toHaveCount(0)
  await form.getByLabel('Saison', { exact: true }).fill('2025')
  await expect(panel.getByRole('region', { name: 'Résultats des profils statistiques' })).toHaveCount(0)
  expect(reads).toBe(2); expect(writes).toBe(0)
})
