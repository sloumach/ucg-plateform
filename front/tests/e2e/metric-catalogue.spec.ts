import { expect, test } from '@playwright/test'
import type { MetricDefinition } from '../../src/api/metricCatalogue'
test('dictionnaire global : création, formule versionnée, historique et lecture mobile', async ({ page }) => {
  const game = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222', initial = '33333333-3333-4333-8333-333333333333', gamesVersion = '44444444-4444-4444-8444-444444444444', mode = '55555555-5555-4555-8555-555555555555', release = '66666666-6666-4666-8666-666666666666'
  const title = '<img>Ratio documenté</img> ' + 'Long'.repeat(24), firstFormula = '<script>sum(kills)/sum(deaths)</script>', nextFormula = 'sum(kills) / sum(deaths), composantes contrôlées'
  let current: MetricDefinition | null = null, version = initial, manager = true, writes = 0
  const history: MetricDefinition[] = [], headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }
  await page.route('**/sanctum/csrf-cookie', (r) => r.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request(), method = request.method(), url = new URL(request.url()), target = url.pathname.replace('/api/v1/', '')
    if (method === 'OPTIONS') { await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET, POST, PATCH', 'Access-Control-Allow-Headers': 'Content-Type, X-Tenant-Revision, X-XSRF-TOKEN' } }); return }
    let data: unknown = []
    const meta = { request_id: 'stat001-e2e', current_page: 1, last_page: 1, total: 0, can_manage: manager, catalogue_version: version, game_catalogue_version: gamesVersion }
    if (target === 'auth/me') data = { id: 42, name: 'Utilisateur vérifié', email: 'operator@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (target === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (target === 'active-organization') data = { context: null, revision: null, confirmed: false }
    if (target === 'game-catalogue') {
      const kind = url.searchParams.get('kind'); data = kind === 'game' ? [{ id: game, name: 'Jeu pilote', active: true }] : kind === 'mode' ? [{ id: mode, name: '<img>Mode pilote</img>', active: true }] : kind === 'release' ? [{ id: release, name: 'Patch 2026.10', active: true }] : []
    }
    if (target === 'metric-catalogue' || target === 'metric-catalogue/' + id) {
      if (method === 'POST' || method === 'PATCH') {
        expect(manager).toBe(true); const body = request.postDataJSON(); expect(body.confirm_global).toBe(true); expect(body.catalogue_version).toBe(version); expect(body.game_catalogue_version).toBe(gamesVersion); expect(body.organization_id).toBeUndefined(); expect(body.value).toBeUndefined(); expect(body.zero_denominator).toBe('unavailable'); expect(body.aggregation_level).toBe('period')
        if (current) { expect(body.version).toBe(current.version); expect(body.game_id).toBeUndefined(); expect(body.code).toBeUndefined() } else { expect(body.game_id).toBe(game); expect(body.code).toBe('KD_RATIO') }
        const { confirm_global: _confirm, catalogue_version: _catalogue, game_catalogue_version: _games, ...values } = body
        writes++; version = writes === 1 ? id : release
        current = { ...current, ...values, id, game_id: game, code: 'KD_RATIO', number: writes, version, scope: { game_id: game, game_key: 'pilot', game_name: 'Jeu pilote', mode_id: mode, mode_name: '<img>Mode pilote</img>', role_id: null, role_name: null, release_id: release, release_name: 'Patch 2026.10' }, occurred_at: '2026-10-19T10:00:00Z' }
        history.push(current!); data = current; meta.catalogue_version = version
      } else data = current ? [current] : []
    }
    if (target === 'metric-catalogue/' + id + '/history') data = [...history].reverse()
    meta.total = Array.isArray(data) ? data.length : 0
    await route.fulfill({ status: method === 'POST' && target === 'metric-catalogue' ? 201 : 200, headers, contentType: 'application/json', body: JSON.stringify({ data, meta }) })
  })
  await page.setViewportSize({ width: 375, height: 812 }); await page.goto('/')
  const panel = page.getByRole('region', { name: 'Dictionnaire des métriques', exact: true }); await panel.getByRole('button', { name: 'Ouvrir le dictionnaire' }).click(); await expect(panel.getByText('Aucune métrique pour ces filtres.')).toBeVisible(); await panel.getByRole('button', { name: 'Créer une métrique' }).click()
  const form = panel.getByRole('form', { name: 'Enregistrer cette définition' }); await form.getByLabel('Jeu', { exact: true }).selectOption(game); await form.getByLabel('Code stable de la métrique').fill('KD_RATIO'); await form.getByLabel('Nom de la métrique').fill(title); await form.getByLabel('Définition', { exact: true }).fill('Ratio des composantes observées dans le contexte indiqué.'); await form.getByLabel('Unité', { exact: true }).fill('ratio'); await form.getByLabel('Agrégation', { exact: true }).selectOption('ratio'); await form.getByLabel('Niveau de la mesure').selectOption('period'); await form.getByLabel('Formule documentée').fill(firstFormula); await form.getByLabel('Disponibilité de la mesure').selectOption('source_required'); await form.getByLabel('Conditions et limites de disponibilité').fill('Accès à la source et qualité des composantes à confirmer.'); await form.getByLabel('Mode', { exact: true }).selectOption(mode); await form.getByLabel('Patch / version du jeu').selectOption(release); await form.getByLabel('Motif du changement').fill('Définition commune vérifiée avant toute acquisition.'); await form.getByRole('button', { name: 'Enregistrer cette définition' }).click()
  const confirmation = panel.getByRole('region', { name: 'Confirmer la définition globale' }); await expect(confirmation.getByRole('button', { name: 'Confirmer la définition globale' })).toBeDisabled(); expect(writes).toBe(0); await confirmation.getByRole('checkbox').check(); await confirmation.getByRole('button', { name: 'Confirmer la définition globale' }).click(); await expect(form).toHaveCount(0); await expect(panel.getByText(title)).toBeVisible(); expect(await panel.locator('script,img,iframe').count()).toBe(0)
  for (const width of [375, 320]) { await page.setViewportSize({ width, height: 812 }); expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true); await panel.screenshot({ path: 'reports/stat001-' + width + '.png' }) }
  await panel.getByRole('button', { name: 'Modifier cette définition' }).click(); await form.getByLabel('Formule documentée').fill(nextFormula); await form.getByLabel('Motif du changement').fill('Précision documentée de la formule et de sa source.'); await form.getByRole('button', { name: 'Enregistrer cette définition' }).click(); await confirmation.getByRole('checkbox').check(); await confirmation.getByRole('button', { name: 'Confirmer la définition globale' }).click(); await expect(form).toHaveCount(0)
  await panel.getByRole('button', { name: 'Historique des définitions', exact: true }).click(); const journal = panel.getByRole('region', { name: 'Historique des définitions', exact: true }); await expect(journal.getByText(firstFormula, { exact: false })).toBeVisible(); await expect(journal.getByText(nextFormula, { exact: false })).toBeVisible()
  manager = false; await page.reload(); await panel.getByRole('button', { name: 'Ouvrir le dictionnaire' }).click(); await expect(panel.getByText(nextFormula, { exact: false })).toBeVisible(); await expect(panel.getByRole('button', { name: 'Créer une métrique' })).toHaveCount(0); await expect(panel.getByRole('button', { name: 'Modifier cette définition' })).toHaveCount(0); expect(writes).toBe(2)
})
