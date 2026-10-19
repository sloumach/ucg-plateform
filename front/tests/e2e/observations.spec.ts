import { expect, test } from '@playwright/test'
import type { MetricDefinition } from '../../src/api/metricCatalogue'
import type { StatisticalObservation } from '../../src/api/observations'
test('observations privées : zéro, indisponibilité, correction historisée et lecteur sur mobile', async ({ page }) => {
  const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222', revision = '33333333-3333-4333-8333-333333333333', person = '44444444-4444-4444-8444-444444444444', v2 = '55555555-5555-4555-8555-555555555555'
  const root = 'tenants/' + org + '/observations', headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }
  const metric: MetricDefinition = { id, game_id: id, code: 'POINTS', version: revision, number: 1, mode_id: null, role_id: null, release_id: null, scope: { game_id: id, game_key: 'pilot', game_name: 'Jeu pilote', mode_id: null, mode_name: null, role_id: null, role_name: null, release_id: null, release_name: null }, title: '<img>Points documentés</img>', definition: 'Mesure ponctuelle documentée', unit: 'points', aggregation: 'manual', aggregation_level: 'period', formula: '<script>source</script>', zero_denominator: 'not_applicable', measurement_kind: 'objective', availability: 'manual', availability_note: 'Relevé manuel contrôlé', status: 'active', reason: 'Définition commune contrôlée.', occurred_at: '2026-10-19T10:00:00Z' }
  let current: StatisticalObservation | null = null, writer = true, confirmed = false, writes = 0
  const history: StatisticalObservation[] = [], source = '<script>Grille privée</script> ' + 'RéférenceLongue'.repeat(8)
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request(), method = request.method(), url = new URL(request.url()), target = url.pathname.replace('/api/v1/', '')
    if (method === 'OPTIONS') { await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET, POST, PATCH', 'Access-Control-Allow-Headers': 'Content-Type, X-Tenant-Revision, X-XSRF-TOKEN' } }); return }
    const context = { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'], permissions: ['organization.view', 'sports.read', 'sports.statistics.read', ...(writer ? ['sports.write', 'sports.statistics.write', 'sports.statistics.correct'] : [])], is_owner: false, actor_user_id: writer ? 42 : 43, membership_id: id }
    let data: unknown = []
    if (target === 'auth/me') data = { id: writer ? 42 : 43, name: 'Coach', email: 'coach@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (target === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (target.endsWith('/game-profile-catalogue')) data = { games: [] }
    if (target === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: id }]
    if (target === 'active-organization') data = { context, revision, confirmed }
    if (target === 'active-organization/confirmation') { confirmed = true; data = { context, revision, confirmed } }
    if (target === 'metric-catalogue') data = [metric]
    if (target === root + '/choices' && url.searchParams.get('kind') === 'people') data = [{ id: person, label: 'Joueur pilote' }]
    if (target === root || target === root + '/' + id) {
      if (method === 'POST' || method === 'PATCH') {
        expect(writer && confirmed).toBe(true); expect(request.headers()['x-tenant-revision']).toBe(revision); const body = request.postDataJSON(); writes++
        if (!current) { expect(body.metric_version).toBe(revision); expect(body.person_id).toBe(person); expect(body.value).toBe('0'); expect(body.organization_id).toBeUndefined(); current = { ...body, id, version: revision, number: 1, person_label: 'Joueur pilote', game_id: id, acquired_via: 'manual', origin_reference: null, context: { metric, game_context: metric.scope, region: body.region, season: body.season, map: body.map, opponent: body.opponent, round_number: body.round_number, session_label: body.session_label }, occurred_at: '2026-10-19T10:00:00Z' } }
        else { expect(body.version).toBe(revision); expect(body.source_label).toBeUndefined(); expect(body.metric_version).toBeUndefined(); expect(body.value).toBeNull(); expect(body.availability).toBe('unavailable'); current = { ...current, ...body, version: v2, number: 2 } }
        history.push(current!); data = current
      } else data = current ? [current] : []
    }
    if (target === root + '/' + id + '/history') data = [...history].reverse()
    await route.fulfill({ status: method === 'POST' && target === root ? 201 : 200, headers, contentType: 'application/json', body: JSON.stringify({ data, meta: { request_id: 'stat002-e2e', current_page: 1, last_page: 1, total: Array.isArray(data) ? data.length : 0 } }) })
  })
  await page.setViewportSize({ width: 375, height: 812 }); await page.goto('/')
  const panel = page.getByRole('region', { name: 'Observations statistiques privées', exact: true }); await panel.getByRole('button', { name: 'Ouvrir les observations' }).click(); await panel.getByRole('button', { name: 'Saisir une observation' }).click()
  let form = panel.getByRole('form', { name: 'Saisir une observation' }); await form.getByLabel('Métrique et version').selectOption(id); await form.getByLabel('Joueur', { exact: true }).selectOption(person); await form.getByLabel('Source et référence documentaire').fill(source); await form.getByLabel('Début de période').fill('2026-10-01'); await form.getByLabel('Fin de période').fill('2026-10-18'); await form.getByLabel('Date d’observation de la source').fill('2026-10-19'); await form.getByLabel('Valeur', { exact: true }).fill('0'); await form.getByLabel('Motif de saisie ou correction').fill('Source consultée et observation vérifiée.'); await form.getByRole('button', { name: 'Enregistrer cette observation' }).click(); expect(writes).toBe(0); await page.getByRole('button', { name: 'Confirmer et continuer' }).click(); await expect(form).toHaveCount(0); await expect(panel.getByText('Valeur : 0', { exact: false })).toBeVisible()
  await panel.getByRole('button', { name: 'Corriger cette observation' }).click(); form = panel.getByRole('form', { name: 'Corriger cette observation' }); await form.getByLabel('Disponibilité de la valeur').selectOption('unavailable'); await form.getByLabel('Motif d’indisponibilité').fill('Source incomplète après vérification.'); await form.getByLabel('Motif de saisie ou correction').fill('Correction justifiée par le contrôle de la source.'); await form.getByRole('button', { name: 'Enregistrer cette observation' }).click(); await page.getByRole('button', { name: 'Confirmer et continuer' }).click(); await expect(form).toHaveCount(0); await expect(panel.getByText('Valeur : Donnée indisponible', { exact: false })).toBeVisible(); expect(await panel.locator('script,img,iframe').count()).toBe(0)
  await panel.getByRole('button', { name: 'Historique des observations', exact: true }).click(); const journal = panel.getByRole('region', { name: 'Historique des observations', exact: true }); await expect(journal.getByText('Valeur : 0', { exact: false })).toBeVisible(); await expect(journal.getByText('Valeur : Donnée indisponible', { exact: false })).toBeVisible(); await journal.getByRole('button', { name: 'Fermer cet historique' }).click()
  await expect(panel.getByText('Période', { exact: false })).toBeVisible()
  for (const close of await page.getByRole('button', { name: 'Fermer la notification' }).all()) await close.click()
  for (const width of [375, 320]) { await page.setViewportSize({ width, height: 812 }); expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true); await panel.screenshot({ path: 'reports/stat002-' + width + '.png' }) }
  writer = false; await page.reload(); await panel.getByRole('button', { name: 'Ouvrir les observations' }).click(); await expect(panel.getByText(source, { exact: false })).toBeVisible(); await expect(panel.getByRole('button', { name: 'Corriger cette observation' })).toHaveCount(0); await expect(panel.getByRole('button', { name: 'Saisir une observation' })).toHaveCount(0); expect(writes).toBe(2)
})
