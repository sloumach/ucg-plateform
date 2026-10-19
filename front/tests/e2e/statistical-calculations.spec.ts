import { expect, test } from '@playwright/test'
import type { MetricDefinition } from '../../src/api/metricCatalogue'
import type { StatisticalObservation } from '../../src/api/observations'
import type { StatisticalCalculation, StatisticalCalculationResult } from '../../src/api/statisticalCalculations'

test('agrégat privé : sélection, confirmation, résultat périmé et recalcul sur mobile', async ({ page }) => {
  const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222', revision = '33333333-3333-4333-8333-333333333333', person = '44444444-4444-4444-8444-444444444444', denominator = '55555555-5555-4555-8555-555555555555', target = '66666666-6666-4666-8666-666666666666', newVersion = '77777777-7777-4777-8777-777777777777'
  const metric: MetricDefinition = { id, game_id: id, code: 'KILLS', version: revision, number: 1, mode_id: null, role_id: null, release_id: null, scope: { game_id: id, game_key: 'pilot', game_name: 'Jeu pilote', mode_id: null, mode_name: null, role_id: null, role_name: null, release_id: null, release_name: null }, title: '<img>Mesure documentée</img>', definition: 'Mesure additive', unit: 'count', aggregation: 'sum', aggregation_level: 'period', formula: '<script>documentaire</script>', zero_denominator: 'not_applicable', measurement_kind: 'objective', availability: 'manual', availability_note: 'Source déclarée', status: 'active', reason: 'Définition contrôlée', occurred_at: '2026-10-19T10:00:00Z' }
  const ratio: MetricDefinition = { ...metric, id: target, code: 'KD_RATIO', title: 'Ratio documenté', aggregation: 'ratio', zero_denominator: 'unavailable', ratio_components: { numerator: [{ metric_id: id, version: revision, code: 'KILLS' }], denominator: [{ metric_id: denominator, version: revision, code: 'DEATHS' }] } }
  const source = '<script>Source privée</script> ' + 'RéférenceLongue'.repeat(8)
  const first: StatisticalObservation = { id, version: revision, number: 1, metric_id: id, metric_version: revision, game_id: id, person_id: person, person_label: 'Joueur pilote', match_id: null, match_game_id: null, source_kind: 'training', acquired_via: 'manual', origin_reference: null, source_label: source, period_start: '2026-10-01', period_end: '2026-10-01', observed_on: '2026-10-19', sample_size: 3, context: { metric, game_context: metric.scope, region: 'Europe', season: '2026', map: null, opponent: null, round_number: null, session_label: null }, availability: 'available', value: '1', missing_reason: null, notes: null, status: 'active', reason: 'Observation contrôlée.', occurred_at: '2026-10-19T10:00:00Z' }
  const second: StatisticalObservation = { ...first, id: denominator, metric_id: denominator, value: '10', context: { ...first.context, metric: { ...metric, id: denominator, code: 'DEATHS' } } }
  const result: StatisticalCalculationResult = { engine_version: 'stat-004.v1', precision: 6, rounding: 'half_up', availability: 'available', value: '0.1', sample_size: 3, source_count: 2, included_count: 2, missing_count: 0, voided_count: 0, incomplete: false, numerator: '1', denominator: '10', reason: null }
  const calc: StatisticalCalculation = { id: target, version: revision, status: 'completed', source_count: 2, metric: ratio, cohort: { person_id: person, source_kind: 'training', source_label: source }, failure_code: null, result, result_version: revision, number: 1, can_recalculate: true, sources: { data: [id, denominator], meta: { current_page: 1, last_page: 1, total: 2, per_page: 10 } } }
  const root = 'tenants/' + org, calculationRoot = root + '/statistical-calculations', headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }
  let confirmed = false, calculated = false, writes = 0, recalculations = 0
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request(), method = request.method(), url = new URL(request.url()), path = url.pathname.replace('/api/v1/', '')
    if (method === 'OPTIONS') { await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET,POST,PATCH', 'Access-Control-Allow-Headers': 'Content-Type,X-Tenant-Revision,X-XSRF-TOKEN' } }); return }
    const context = { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'], permissions: ['organization.view', 'sports.read', 'sports.write', 'sports.statistics.read', 'sports.statistics.write', 'sports.statistics.correct'], is_owner: false, actor_user_id: 42, membership_id: id }
    let data: unknown = []
    if (path === 'auth/me') data = { id: 42, name: 'Coach', email: 'coach@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (path === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (path.endsWith('/game-profile-catalogue')) data = { games: [] }
    if (path === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: id }]
    if (path === 'active-organization') data = { context, revision, confirmed }
    if (path === 'active-organization/confirmation') { confirmed = true; data = { context, revision, confirmed } }
    if (path === 'metric-catalogue') data = [ratio]
    if (path === root + '/observations') data = [first, second]
    if (path === calculationRoot) {
      data = calculated ? [calc] : []
      if (method === 'POST') {
        expect(confirmed).toBe(true); expect(request.headers()['x-tenant-revision']).toBe(revision)
        expect(request.postDataJSON()).toEqual({ metric_id: target, metric_version: revision, observations: [id, denominator], confirm: true })
        calculated = true; writes++; data = calc
      }
    }
    if (path === calculationRoot + '/' + target) data = calc
    if (path === calculationRoot + '/' + target + '/history') data = [{ id: revision, number: 1, engine_version: result.engine_version, result, created_at: '2026-10-19T10:00:00Z' }]
    if (path === root + '/observations/' + id && method === 'PATCH') {
      expect(request.postDataJSON().value).toBe('2'); writes++
      first.value = '2'; first.version = newVersion; first.number = 2
      Object.assign(calc, { status: 'stale', version: newVersion, result: null })
      data = first
    }
    if (path === calculationRoot + '/' + target + '/recalculate') {
      expect(request.postDataJSON()).toEqual({ version: newVersion, confirm: true }); recalculations++; writes++
      Object.assign(calc, { status: 'completed', result: { ...result, value: '0.2', numerator: '2' }, number: 2 })
      data = calc
    }
    if (['POST', 'PATCH'].includes(method) && ![calculationRoot, 'active-organization/confirmation', root + '/observations/' + id, calculationRoot + '/' + target + '/recalculate'].includes(path)) throw new Error('Unexpected mutation: ' + path)
    await route.fulfill({ status: 200, headers, contentType: 'application/json', body: JSON.stringify({ data, meta: { request_id: 'stat004-e2e', current_page: 1, last_page: 1, total: Array.isArray(data) ? data.length : 0 } }) })
  })
  await page.setViewportSize({ width: 375, height: 812 }); await page.goto('/')
  const observations = page.getByRole('region', { name: 'Observations statistiques privées', exact: true })
  await observations.getByRole('button', { name: 'Ouvrir les observations', exact: true }).click()
  await observations.getByRole('checkbox', { name: 'Sélectionner pour un calcul · ' + id, exact: true }).check()
  await observations.getByRole('checkbox', { name: 'Sélectionner pour un calcul · ' + denominator, exact: true }).check()
  const panel = page.getByRole('region', { name: 'Agrégats et ratios privés', exact: true })
  await panel.getByRole('button', { name: 'Ouvrir les calculs statistiques' }).click()
  await panel.getByLabel('Métrique cible et version').selectOption(target)
  await panel.getByRole('button', { name: 'Demander le calcul de cet échantillon', exact: true }).click()
  expect(writes).toBe(0); await panel.getByRole('button', { name: 'Confirmer et continuer' }).click()
  const detail = panel.getByRole('region', { name: 'Détail du calcul statistique', exact: true })
  await expect(detail.getByText('Résultat : 0.1', { exact: true })).toBeVisible()
  expect(writes).toBe(1)
  await observations.getByRole('button', { name: 'Corriger cette observation', exact: true }).first().click()
  const correction = observations.getByRole('form', { name: 'Corriger cette observation', exact: true })
  await correction.getByLabel('Valeur', { exact: true }).fill('2')
  await correction.getByLabel('Motif de saisie ou correction').fill('Correction contrôlée avant le recalcul.')
  await correction.getByRole('button', { name: 'Enregistrer cette observation' }).click()
  await observations.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await expect(correction).toHaveCount(0)
  await panel.getByRole('button', { name: 'Actualiser les calculs' }).click()
  await expect(detail.getByText('Aucun résultat actuel validé.', { exact: false })).toBeVisible()
  await expect(detail.getByText('Résultat : 0.1', { exact: true })).toHaveCount(0)
  await detail.getByRole('button', { name: 'Historique des calculs', exact: true }).click()
  await expect(detail.getByRole('region', { name: 'Historique des calculs' }).getByText('Résultat : 0.1', { exact: true })).toBeVisible()
  expect(recalculations).toBe(0)
  await detail.getByRole('button', { name: 'Confirmer un recalcul', exact: true }).click()
  await panel.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await expect(detail.getByText('Résultat : 0.2', { exact: true })).toBeVisible()
  expect(recalculations).toBe(1); expect(writes).toBe(3)
  expect(await panel.locator('script,img,iframe').count()).toBe(0)
  for (const width of [375, 320]) {
    await page.setViewportSize({ width, height: 812 })
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
    await detail.screenshot({ path: 'reports/stat004-' + width + '.png', style: 'header { visibility: hidden; }' })
  }
})
