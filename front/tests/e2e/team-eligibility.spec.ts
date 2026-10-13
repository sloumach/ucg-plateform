import { expect, test } from '@playwright/test'

test.use({ timezoneId: 'UTC' })
test('éligibilité : règlement confirmé, lien durable, motifs incomplets et rapport final sur mobile', async ({ page }) => {
  const org = '11111111-1111-4111-8111-111111111111', revision = '22222222-2222-4222-8222-222222222222'
  const teamId = '33333333-3333-4333-8333-333333333333', lineupId = '44444444-4444-4444-8444-444444444444', ruleId = '55555555-5555-4555-8555-555555555555'
  const team = { id: teamId, game_id: teamId, game_key: 'valorant', game_name: 'VALORANT', game_available: true, slug: 'pilote', season: '2026', name: 'Équipe pilote', kind: 'first', custom_category: null, short_name: null, public_description: null, color: null, status: 'active', version: revision, publication_status: 'private' }
  const pseudonym = '<script>ELIGIBILITY_BAD</script>' + 'P'.repeat(45), ruleName = 'Coupe_' + 'R'.repeat(100)
  const lineup = { id: lineupId, team_id: teamId, game_id: teamId, purpose: 'registration', label: 'Inscription pilote', effective_at: '2026-10-13T11:00:00+00:00', status: 'draft', version: revision,
    team_name: team.name, game_name: 'VALORANT', game_key: 'valorant', season: '2026', created_by: 42, finalized_at: null as string | null, finalized_by: null as number | null,
    ruleset_id: null as string | null, ruleset_version: null as string | null, ruleset_name: null as string | null, lock_at: null,
    eligibility_status: 'unassessed', eligibility_report_id: null as string | null,
    entries: [{ id: lineupId, person_id: lineupId, membership_id: lineupId, membership_version: revision, pseudonym, membership_role_key: 'player', slot_key: 'starter', position: 1 }] }
  const rules: Record<string, unknown>[] = [], reports: Record<string, unknown>[] = [], errors: string[] = []
  let writes = 0, confirmed = false
  const base = 'tenants/' + org, teamPath = base + '/teams/' + teamId, rulesPath = base + '/eligibility-rulesets'
  const headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }
  page.on('pageerror', (error) => errors.push(error.message))
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request(), path = new URL(request.url()).pathname.replace('/api/v1/', ''), method = request.method()
    if (method === 'OPTIONS') { await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET, POST, PATCH', 'Access-Control-Allow-Headers': 'Content-Type, X-Tenant-Revision, X-XSRF-TOKEN' } }); return }
    let data: unknown = []
    if (path === 'auth/me') data = { id: 42, name: 'Manager', email: 'manager@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (path === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (path === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: lineupId }]
    if (path === 'active-organization/confirmation') confirmed = true
    if (path === 'active-organization' || path === 'active-organization/confirmation') data = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'], permissions: ['organization.view', 'sports.read', 'teams.manage'], is_owner: false, actor_user_id: 42, membership_id: lineupId }, revision, confirmed }
    if (path.endsWith('/game-profile-catalogue')) data = { games: [] }
    if (path === base + '/games') data = [{ id: teamId, name: 'VALORANT', enabled: true }]
    if (path === base + '/games/' + teamId) data = { effective_options: { platform: [{ key: 'pc', name: 'PC' }], region: [{ key: 'eu', name: 'Europe' }] } }
    if (path === base + '/teams') data = [team]
    if (path === teamPath + '/lineups') data = [lineup]
    if (path === rulesPath) data = rules
    if (path === teamPath + '/lineups/' + lineupId + '/eligibility') data = reports
    if (method !== 'GET' && path !== 'active-organization/confirmation') {
      expect(confirmed).toBe(true); expect(request.headers()['x-tenant-revision']).toBe(revision)
      const body = request.postDataJSON()
      if (path === rulesPath) {
        expect(body).toEqual({ name: ruleName, game_id: teamId, min_starters: 1, max_starters: 5, max_substitutes: 2, min_age: null, max_age: null, observation_max_days: 30, lock_at: null, platform_key: 'pc', allowed_regions: ['eu'], allowed_ranks: ['Diamond'] })
        const rule = { ...body, id: ruleId, version: revision, status: 'active' }; rules.push(rule); data = rule
      } else if (path.endsWith('/ruleset-binding')) {
        expect(body).toEqual({ version: revision, ruleset_id: ruleId, ruleset_version: revision })
        Object.assign(lineup, { ruleset_id: ruleId, ruleset_version: revision, ruleset_name: ruleName, version: ruleId }); data = lineup
      } else if (path.endsWith('/eligibility')) {
        expect(body).toEqual({ version: ruleId })
        const report = { id: revision, ruleset_id: ruleId, ruleset_version: revision, lineup_version: ruleId, stage: 'preview', status: 'incomplete', evaluated_at: lineup.effective_at, actor_user_id: 42,
          checks: [{ code: 'profile_missing', status: 'unknown', person_id: lineupId, message: 'Profil de jeu absent.' }] }
        reports.push(report); data = report
      } else if (path.endsWith('/lineups/' + lineupId)) {
        expect(body).toEqual({ version: ruleId, action: 'finalize', team_version: revision })
        Object.assign(lineup, { status: 'finalized', eligibility_status: 'eligible', eligibility_report_id: teamId, version: teamId, finalized_at: lineup.effective_at, finalized_by: 42 })
        reports.unshift({ id: teamId, ruleset_id: ruleId, ruleset_version: revision, lineup_version: teamId, stage: 'final', status: 'eligible', evaluated_at: lineup.effective_at, actor_user_id: 42,
          checks: [{ code: 'rank', status: 'passed', person_id: lineupId, message: 'Rang déclaré autorisé.' }] }); data = lineup
      }
      writes++
    }
    await route.fulfill({ headers, json: { data, meta: { current_page: 1, last_page: 1, total: Array.isArray(data) ? data.length : 1, request_id: 'team005-browser' }, ...(method !== 'GET' ? { notification: { type: 'success', message: 'Éligibilité enregistrée.' } } : {}) } })
  })
  await page.goto('/')
  await page.getByRole('button', { name: 'Équipes de l’organisation' }).click()
  await page.getByRole('button', { name: 'Compositions et instantanés', exact: true }).click()
  const panel = page.getByRole('region', { name: 'Compositions et instantanés Équipe pilote' })
  const mobile = async () => { for (const width of [375, 320]) {
    await page.setViewportSize({ width, height: 812 })
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
    await expect.poll(() => panel.locator('h3,h4,h5,h6,p,ul,li,label').evaluateAll((elements) => elements.filter((element) => element.scrollWidth > element.clientWidth).map((element) => element.textContent))).toEqual([])
  } }
  const confirm = async () => { const pending = panel.getByRole('region', { name: 'Confirmer l’action' }); await expect(pending).toContainText('Alpha'); await mobile(); await pending.getByRole('button', { name: 'Confirmer et continuer' }).click() }
  await panel.getByRole('button', { name: 'Règlements d’éligibilité', exact: true }).click()
  const rulesPanel = panel.getByRole('region', { name: 'Règlements d’éligibilité', exact: true })
  await rulesPanel.getByRole('button', { name: 'Créer le règlement', exact: true }).click()
  await rulesPanel.getByLabel('Nom du contexte de compétition').fill(ruleName)
  await rulesPanel.getByLabel('Plateforme du profil contrôlé').selectOption('pc')
  await rulesPanel.getByRole('checkbox', { name: 'Europe' }).check()
  await rulesPanel.getByLabel('Rangs déclarés autorisés, un par ligne (vide : tous)').fill('Diamond')
  await mobile(); await rulesPanel.getByRole('form', { name: 'Créer le règlement' }).getByRole('button', { name: 'Créer le règlement' }).click()
  expect(writes).toBe(0); await confirm(); await expect(rulesPanel.getByRole('heading', { name: ruleName + ' · Actif' })).toBeVisible()
  await rulesPanel.getByRole('button', { name: 'Fermer les règlements' }).click()
  await panel.getByRole('button', { name: 'Contrôler l’éligibilité', exact: true }).click()
  await panel.getByLabel('Choisir un règlement actif').selectOption(ruleId)
  await panel.getByRole('button', { name: 'Lier le règlement', exact: true }).click(); expect(writes).toBe(1); await confirm()
  await expect(panel.getByText(ruleName, { exact: false })).toBeVisible()
  await panel.getByRole('button', { name: 'Contrôler l’éligibilité', exact: true }).click()
  await panel.getByRole('button', { name: 'Enregistrer une pré-évaluation', exact: true }).click(); expect(writes).toBe(2); await confirm()
  await expect(panel.getByRole('heading', { name: 'Pré-évaluation · Contrôle incomplet' })).toBeVisible()
  await expect(panel.getByText('Profil de jeu absent.', { exact: false })).toBeVisible(); await mobile()
  await panel.screenshot({ path: 'reports/team005-incomplete-mobile.png', style: 'header { visibility: hidden }' })
  // The API fixture models a profile corrected before confirmation; backend tests cover the actual reevaluation and refusal.
  await panel.getByRole('button', { name: 'Figer la composition', exact: true }).click(); expect(writes).toBe(3); await confirm()
  await expect(panel.getByText(ruleName + ' · Admissible')).toBeVisible()
  await panel.getByRole('button', { name: 'Contrôler l’éligibilité', exact: true }).click()
  await expect(panel.getByRole('heading', { name: 'Contrôle au figement · Admissible' })).toBeVisible()
  expect(await panel.getByRole('button', { name: 'Enregistrer une pré-évaluation' }).count()).toBe(0)
  expect(await page.locator('script').filter({ hasText: 'ELIGIBILITY_BAD' }).count()).toBe(0)
  await mobile(); await page.addStyleTag({ content: '[aria-label="Compositions et instantanés Équipe pilote"] { font-family: monospace; font-size: 20px; }' }); await mobile()
  await panel.screenshot({ path: 'reports/team005-final-mobile.png', style: 'header { visibility: hidden }' })
  expect(writes).toBe(4); expect(errors).toEqual([])
})
