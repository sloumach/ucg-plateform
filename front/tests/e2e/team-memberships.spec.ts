import { expect, test } from '@playwright/test'
test('effectif : création et promotion confirmées, historique privé et mobile', async ({ page }) => {
  const org = '11111111-1111-4111-8111-111111111111', revision = '22222222-2222-4222-8222-222222222222'
  const sourceId = '33333333-3333-4333-8333-333333333333', targetId = '44444444-4444-4444-8444-444444444444', personId = '55555555-5555-4555-8555-555555555555'
  const base = 'tenants/' + org + '/teams'
  const source = { id: sourceId, game_id: sourceId, game_key: 'valorant', game_name: 'VALORANT', game_available: true, slug: 'academy-2026', season: '2026',
    name: 'Académie pilote', kind: 'academy', custom_category: null, short_name: null, public_description: null, color: null, status: 'active', version: revision, publication_status: 'private' }
  const target = { ...source, id: targetId, kind: 'first', name: 'Première pilote', slug: 'first-2026' }
  const periods: Record<string, unknown>[] = [], events: Record<string, unknown>[] = []
  let confirmed = false, writes = 0
  const lookupViews: (string | null)[] = []
  const errors: string[] = []
  page.on('pageerror', (error) => errors.push(error.message)); page.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()) })
  const headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }
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
    if (path.endsWith('/games')) data = [{ id: sourceId, name: 'VALORANT', enabled: true }]
    if (path === base) data = url.searchParams.get('kind') === 'first' ? [target] : [source, target]
    if (path.endsWith('/people')) { lookupViews.push(url.searchParams.get('view')); data = [{ id: personId, pseudonym: '<script>ROSTER_BAD</script>', status: 'active', version: revision }] }
    if (path.endsWith('/movements')) data = events
    if (path.endsWith('/memberships') && method === 'GET') data = periods.filter((period) => period.team_id === path.split('/').at(-2))
    if (path === base + '/' + sourceId + '/memberships' && method === 'POST') {
      expect(confirmed).toBe(true); expect(request.headers()['x-tenant-revision']).toBe(revision)
      const body = request.postDataJSON(); expect(body).toMatchObject({ person_id: personId, role_key: 'player', ends_at: null, reason_key: 'initial' })
      expect(body.organization_id).toBeUndefined(); expect(body.permissions).toBeUndefined()
      const period = { ...body, id: personId, team_id: sourceId, pseudonym: '<script>ROSTER_BAD</script>', status: 'active', effective: true, version: revision }
      periods.push(period); events.push({ id: 'created', team_id: sourceId, membership_id: personId, destination_team_id: null, action: 'created', reason_key: 'initial', actor_user_id: 42, effective_at: body.starts_at, recorded_at: body.starts_at }); data = period; writes++
    }
    if (path === base + '/' + sourceId + '/memberships/' + personId && method === 'PATCH') {
      expect(confirmed).toBe(true); const body = request.postDataJSON()
      expect(body).toEqual({ version: revision, action: 'promotion', destination_team_id: targetId, destination_version: revision, role_key: 'player', reason_key: 'promotion' })
      const at = new Date().toISOString(), old = periods[0]
      old.ends_at = at; old.status = 'closed'; old.effective = false; old.version = targetId
      const next = { ...old, id: targetId, team_id: targetId, starts_at: at, ends_at: null, status: 'active', effective: true }
      periods.push(next); events.push({ id: 'promoted', team_id: sourceId, team_name: source.name, destination_team_name: target.name, membership_id: personId, destination_team_id: targetId, destination_membership_id: targetId, action: 'promotion', reason_key: 'promotion', actor_user_id: 42, effective_at: at, recorded_at: at }); data = next; writes++
    }
    await route.fulfill({ headers, json: { data, meta: { request_id: 'team003-browser', current_page: 1, last_page: 1, total: Array.isArray(data) ? data.length : 1 }, ...(method !== 'GET' ? { notification: { type: 'success', message: 'Appartenance enregistrée.' } } : {}) } })
  })
  await page.goto('/')
  await page.getByRole('button', { name: 'Équipes de l’organisation' }).click()
  const card = page.getByRole('heading', { name: 'Académie pilote', exact: true }).locator('..')
  await card.getByRole('button', { name: 'Effectif et historique', exact: true }).click()
  const panel = page.getByRole('region', { name: 'Effectif et historique Académie pilote', exact: true })
  await panel.getByRole('button', { name: 'Ajouter une appartenance' }).click()
  const form = panel.getByRole('form', { name: 'Enregistrer l’appartenance' })
  await form.getByLabel('Personne de l’organisation').selectOption(personId)
  await form.getByLabel('Début de la période').fill('2026-01-01T12:00')
  for (const width of [375, 320]) { await page.setViewportSize({ width, height: 812 }); await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true) }
  await form.screenshot({ path: 'reports/team003-membership-mobile.png', style: 'header { visibility: hidden }' })
  await form.getByRole('button', { name: 'Enregistrer l’appartenance' }).click(); expect(writes).toBe(0)
  const approve = async () => { const confirmation = panel.getByRole('region', { name: 'Confirmer l’action' }); await expect(confirmation).toContainText('Alpha'); await confirmation.getByRole('button', { name: 'Confirmer et continuer' }).click() }
  await approve(); await expect(panel.getByRole('heading', { name: '<script>ROSTER_BAD</script> · Titulaire' })).toBeVisible()
  expect(await page.locator('script').filter({ hasText: 'ROSTER_BAD' }).count()).toBe(0)
  await panel.getByRole('button', { name: 'Promouvoir en équipe première' }).click()
  await form.getByLabel('Équipe de destination').selectOption(targetId)
  await form.getByRole('button', { name: 'Enregistrer l’appartenance' }).click(); await approve()
  await expect(panel.getByText('Clôturée · Non effective à la date consultée')).toBeVisible()
  await expect(panel.getByRole('button', { name: 'Changer d’équipe' })).toHaveCount(0)
  await expect(panel.getByText('Promotion · Promotion', { exact: false })).toBeVisible()
  for (const width of [375, 320]) {
    await page.setViewportSize({ width, height: 812 })
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
    await expect.poll(() => panel.locator('h4').evaluateAll((headings) => headings.every((heading) => heading.scrollWidth <= heading.clientWidth))).toBe(true)
  }
  await panel.screenshot({ path: 'reports/team003-history-mobile.png', style: 'header { visibility: hidden }' })
  expect(writes).toBe(2); expect(lookupViews).toContain('sports'); expect(errors).toEqual([])
})
