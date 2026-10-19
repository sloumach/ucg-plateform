import { Buffer } from 'node:buffer'
import { expect, test } from '@playwright/test'
import type { MetricDefinition } from '../../src/api/metricCatalogue'
import type { StatisticalImport } from '../../src/api/statisticalImports'
test('CSV statistique privé : dépôt confirmé, aperçu sans observations et erreurs sur mobile', async ({ page }) => {
  const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222', revision = '33333333-3333-4333-8333-333333333333', person = '44444444-4444-4444-8444-444444444444'
  const metric: MetricDefinition = { id, game_id: id, code: 'POINTS', version: revision, number: 1, mode_id: null, role_id: null, release_id: null, scope: { game_id: id, game_key: 'pilot', game_name: 'Jeu pilote', mode_id: null, mode_name: null, role_id: null, role_name: null, release_id: null, release_name: null }, title: '<img>Points documentés</img>', definition: 'Mesure ponctuelle documentée', unit: 'points', aggregation: 'manual', aggregation_level: 'period', formula: '<script>source</script>', zero_denominator: 'not_applicable', measurement_kind: 'objective', availability: 'manual', availability_note: 'Relevé manuel contrôlé', status: 'active', reason: 'Définition commune contrôlée.', occurred_at: '2026-10-19T10:00:00Z' }
  const root = 'tenants/' + org + '/statistical-imports', headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }
  let confirmed = false, uploaded = false, importer = true, writes = 0
  const source = '<script>Grille privée</script> ' + 'RéférenceLongue'.repeat(6)
  const run: StatisticalImport = { id, status: 'previewed', row_count: 2, valid_count: 1, invalid_count: 1, created_at: '2026-10-19T10:00:00Z', can_confirm: false, headers: ['person_id', 'value'], delimiter: ';', rows: { data: [
    { line: 2, status: 'valid', values: { person_id: person, metric_version: revision, source_label: source, availability: 'available', value: '0' }, errors: {}, resolved: { person_id: person, person_label: 'Joueur pilote', metric_id: id, metric_version: revision, game_id: id, match_id: null, match_game_id: null, metric, game_context: metric.scope } },
    { line: 3, status: 'invalid', values: { value: '=SUM(1,2)' }, errors: { value: ['Valeur décimale invalide.'] }, resolved: null },
  ], meta: { current_page: 1, last_page: 1, per_page: 10, total: 2 } } }
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request(), method = request.method(), url = new URL(request.url()), target = url.pathname.replace('/api/v1/', '')
    if (method === 'OPTIONS') { await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET, POST', 'Access-Control-Allow-Headers': 'Content-Type, X-Tenant-Revision, X-XSRF-TOKEN' } }); return }
    const context = { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'], permissions: ['organization.view', 'sports.read', 'sports.statistics.read', ...(importer ? ['sports.write', 'sports.statistics.write', 'sports.statistics.import'] : [])], is_owner: false, actor_user_id: 42, membership_id: id }
    let data: unknown = []
    if (target === 'auth/me') data = { id: 42, name: 'Coach', email: 'coach@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (target === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (target.endsWith('/game-profile-catalogue')) data = { games: [] }
    if (target === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: id }]
    if (target === 'active-organization') data = { context, revision, confirmed }
    if (target === 'active-organization/confirmation') { confirmed = true; data = { context, revision, confirmed } }
    if (target === 'metric-catalogue') data = [metric]
    if (target.endsWith('/observations/choices')) data = [{ id: person, label: 'Joueur pilote' }]
    if (target === root && method === 'POST') {
      expect(importer && confirmed).toBe(true); expect(request.headers()['x-tenant-revision']).toBe(revision)
      expect(request.headers()['content-type']).toContain('multipart/form-data')
      expect(request.postData()).toContain('statistics.csv'); expect(request.postData()).toContain('name="delimiter"')
      uploaded = true; writes++; data = run
    } else if (target === root) data = uploaded ? [run] : []
    if (target === root + '/' + id) data = run
    if (['POST', 'PATCH'].includes(method) && target !== root && target !== 'active-organization/confirmation') throw new Error('Unexpected mutation: ' + target)
    await route.fulfill({ status: 200, headers, contentType: 'application/json', body: JSON.stringify({ data, meta: { request_id: 'stat003a-e2e', current_page: 1, last_page: 1, total: Array.isArray(data) ? data.length : 0 } }) })
  })
  await page.setViewportSize({ width: 375, height: 812 }); await page.goto('/')
  const panel = page.getByRole('region', { name: 'Préparer un CSV statistique', exact: true })
  await panel.getByRole('button', { name: 'Préparer un CSV statistique', exact: true }).click()
  await panel.getByRole('button', { name: 'Consulter les identifiants des joueurs et métriques' }).click()
  await expect(panel.getByText('Joueur pilote · person_id', { exact: false })).toBeVisible()
  await panel.getByRole('button', { name: 'Fermer les références CSV' }).click()
  await panel.getByLabel('Fichier CSV statistique').setInputFiles({ name: 'statistics.csv', mimeType: 'text/csv', buffer: Buffer.from('person_id;value\n' + person + ';0\n') })
  await panel.getByLabel('Séparateur du CSV').selectOption(';')
  await panel.getByRole('button', { name: 'Déposer et prévisualiser le CSV', exact: true }).click()
  expect(writes).toBe(0); await page.getByRole('button', { name: 'Confirmer et continuer' }).click()
  const preview = panel.getByRole('region', { name: 'Prévisualisation du CSV', exact: true })
  await expect(preview.getByText('Ligne 2 · Valide à la préparation')).toBeVisible()
  await expect(preview.getByText('Ligne 3 · À corriger')).toBeVisible()
  await expect(preview.getByText('value : Valeur décimale invalide.')).toBeVisible()
  await expect(preview.getByText('Valeur disponible', { exact: true })).toBeVisible()
  expect(await panel.locator('script,img,iframe').count()).toBe(0)
  await expect(panel.getByRole('button', { name: /traiter|importer les observations/i })).toHaveCount(0)
  for (const close of await page.getByRole('button', { name: 'Fermer la notification' }).all()) await close.click()
  for (const width of [375, 320]) {
    await page.setViewportSize({ width, height: 812 })
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
    await page.evaluate(() => window.scrollTo(0, 0))
    await page.screenshot({ path: 'reports/stat003a-' + width + '.png', fullPage: true })
  }
  importer = false; await page.reload()
  await expect(page.getByRole('button', { name: 'Préparer un CSV statistique', exact: true })).toHaveCount(0)
  expect(writes).toBe(1)
})
