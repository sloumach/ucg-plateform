import { expect, test } from '@playwright/test'

test.use({ timezoneId: 'UTC' })
test('composition : préparation confirmée, figement définitif et participants échappés sur mobile', async ({ page }) => {
  const org = '11111111-1111-4111-8111-111111111111', revision = '22222222-2222-4222-8222-222222222222'
  const teamId = '33333333-3333-4333-8333-333333333333', firstId = '44444444-4444-4444-8444-444444444444', secondId = '55555555-5555-4555-8555-555555555555'
  const team = { id: teamId, game_id: teamId, game_key: 'valorant', game_name: 'VALORANT', game_available: true, slug: 'pilote-2026', season: '2026', name: 'Équipe pilote', kind: 'first', custom_category: null, short_name: null, public_description: null, color: null, status: 'active', version: revision, publication_status: 'private' }
  const pseudonym = '<script>LINEUP_BAD</script>' + 'X'.repeat(50), label = 'Scrim_' + 'L'.repeat(100)
  const periods = [{ id: firstId, team_id: teamId, person_id: firstId, pseudonym, role_key: 'player', starts_at: '2020-01-01T00:00:00+00:00', ends_at: null, status: 'active', effective: true, version: revision },
    { id: secondId, team_id: teamId, person_id: secondId, pseudonym: 'Noé', role_key: 'substitute', starts_at: '2020-01-01T00:00:00+00:00', ends_at: null, status: 'active', effective: true, version: revision }]
  const lineups: Record<string, unknown>[] = [], errors: string[] = []
  const lookupDates: string[] = []
  let confirmed = false, writes = 0
  page.on('pageerror', (error) => errors.push(error.message)); page.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()) })
  const base = 'tenants/' + org + '/teams', headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request(), url = new URL(request.url()), path = url.pathname.replace('/api/v1/', ''), method = request.method()
    if (method === 'OPTIONS') { await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET, POST, PATCH', 'Access-Control-Allow-Headers': 'Content-Type, X-Tenant-Revision, X-XSRF-TOKEN' } }); return }
    let data: unknown = []
    if (path === 'auth/me') data = { id: 42, name: 'Manager', email: 'manager@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (path === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (path === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: 'membership' }]
    if (path === 'active-organization/confirmation') confirmed = true
    if (path === 'active-organization' || path === 'active-organization/confirmation') data = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'], permissions: ['organization.view', 'sports.read', 'teams.manage'], is_owner: false, actor_user_id: 42, membership_id: 'membership' }, revision, confirmed }
    if (path.endsWith('/game-profile-catalogue')) data = { games: [] }
    if (path.endsWith('/games')) data = [{ id: teamId, name: 'VALORANT', enabled: true }]
    if (path === base) data = [team]
    if (path.endsWith('/memberships')) { lookupDates.push(url.searchParams.get('at') ?? ''); data = periods }
    if (path.endsWith('/lineups') && method === 'GET') data = lineups
    if (path === base + '/' + teamId + '/lineups' && method === 'POST') {
      expect(confirmed).toBe(true); expect(request.headers()['x-tenant-revision']).toBe(revision)
      const body = request.postDataJSON()
      expect(body).toEqual({ purpose: 'match', label, effective_at: '2026-10-13T11:00:00+00:00', team_version: revision,
        entries: [{ membership_id: firstId, membership_version: revision, slot_key: 'starter' }, { membership_id: secondId, membership_version: revision, slot_key: 'substitute' }] })
      const entries = body.entries.map((choice: { membership_id: string; slot_key: string }, index: number) => {
        const period = periods.find((item) => item.id === choice.membership_id)!
        return { id: period.id, ...choice, membership_version: period.version, person_id: period.person_id, pseudonym: period.pseudonym, membership_role_key: period.role_key, position: index + 1 }
      })
      const draft = { ...body, id: firstId, team_id: teamId, game_id: teamId, team_name: team.name, game_name: 'VALORANT', game_key: 'valorant', season: '2026', status: 'draft', version: revision, entries, created_by: 42, finalized_at: null, finalized_by: null }
      lineups.push(draft); data = draft; writes++
    }
    if (path === base + '/' + teamId + '/lineups/' + firstId && method === 'PATCH') {
      expect(confirmed).toBe(true); expect(request.postDataJSON()).toEqual({ action: 'finalize', version: revision, team_version: revision })
      Object.assign(lineups[0], { status: 'finalized', finalized_at: '2026-10-13T12:00:00+00:00', finalized_by: 42, version: secondId }); data = lineups[0]; writes++
    }
    await route.fulfill({ headers, json: { data, meta: { request_id: 'team004-browser', current_page: 1, last_page: 1, total: Array.isArray(data) ? data.length : 1 }, ...(method !== 'GET' ? { notification: { type: 'success', message: 'Composition enregistrée.' } } : {}) } })
  })
  await page.goto('/')
  await page.getByRole('button', { name: 'Équipes de l’organisation' }).click()
  await page.getByRole('button', { name: 'Compositions et instantanés', exact: true }).click()
  const panel = page.getByRole('region', { name: 'Compositions et instantanés Équipe pilote' })
  await panel.getByRole('button', { name: 'Préparer une composition' }).click()
  await panel.getByLabel('Date de la composition').fill('2026-10-13T11:00')
  await panel.getByRole('checkbox', { name: 'Retenir ' + pseudonym, exact: true }).check(); await panel.getByRole('checkbox', { name: 'Retenir Noé', exact: true }).check()
  await panel.getByLabel('Place dans la composition Noé').selectOption('substitute')
  await panel.getByLabel('Libellé du match ou de l’inscription').fill(label)
  const mobile = async () => { for (const width of [375, 320]) {
    await page.setViewportSize({ width, height: 812 })
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
    await expect.poll(() => panel.locator('h3, h4, p, ul, ol, li, label').evaluateAll((elements) => elements.filter((element) => element.scrollWidth > element.clientWidth).map((element) => ({ tag: element.tagName, text: element.textContent })))).toEqual([])
  } }
  await mobile(); await panel.screenshot({ path: 'reports/team004-draft-mobile.png', style: 'header { visibility: hidden }' })
  await panel.getByRole('button', { name: 'Enregistrer le brouillon' }).click(); expect(writes).toBe(0)
  await mobile()
  const approve = async () => { const confirmation = panel.getByRole('region', { name: 'Confirmer l’action' }); await expect(confirmation).toContainText('Alpha'); await confirmation.getByRole('button', { name: 'Confirmer et continuer' }).click() }
  await approve(); await expect(panel.getByRole('heading', { name: label, exact: true })).toBeVisible()
  await panel.getByRole('button', { name: 'Figer la composition', exact: true }).click(); expect(writes).toBe(1); await mobile(); await approve()
  await expect(panel.getByText('Match · Figée', { exact: false })).toBeVisible()
  await expect(panel.getByRole('button', { name: 'Modifier le brouillon' })).toHaveCount(0)
  await expect(panel.getByRole('button', { name: 'Annuler le brouillon' })).toHaveCount(0)
  expect(await page.locator('script').filter({ hasText: 'LINEUP_BAD' }).count()).toBe(0)
  await mobile(); await panel.screenshot({ path: 'reports/team004-snapshot-mobile.png', style: 'header { visibility: hidden }' })
  await page.addStyleTag({ content: '[aria-label="Compositions et instantanés Équipe pilote"] { font-family: monospace; font-size: 20px; }' })
  await mobile()
  expect(writes).toBe(2); expect(errors).toEqual([]); expect(lookupDates).toContain('2026-10-13T11:00:00+00:00')
})
