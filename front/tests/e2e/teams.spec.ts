import { expect, test } from '@playwright/test'
test('équipes : création privée, recherche, patch partiel et archivage confirmé sur mobile', async ({ page }) => {
  const org = '11111111-1111-4111-8111-111111111111', revision = '22222222-2222-4222-8222-222222222222', id = '33333333-3333-4333-8333-333333333333'
  let confirmed = false, writes = 0
  let team: Record<string, unknown> | null = null
  const errors: string[] = []
  page.on('pageerror', (error) => errors.push(error.message))
  page.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()) })
  const headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request(), url = new URL(request.url()), path = url.pathname.replace('/api/v1/', ''), method = request.method()
    if (method === 'OPTIONS') { await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET, POST, PATCH', 'Access-Control-Allow-Headers': 'Content-Type, X-Tenant-Revision, X-XSRF-TOKEN' } }); return }
    let data: unknown = []
    const meta = { request_id: 'team002-browser', current_page: 1, last_page: 1, total: team ? 1 : 0 }
    if (path === 'auth/me') data = { id: 42, name: 'Manager', email: 'manager@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (path === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (path === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: 'membership' }]
    if (path === 'active-organization/confirmation') confirmed = true
    if (path === 'active-organization' || path === 'active-organization/confirmation') data = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'], permissions: ['organization.view', 'sports.read', 'teams.manage'], is_owner: false, actor_user_id: 42, membership_id: 'membership' }, revision, confirmed }
    if (path.endsWith('/game-profile-catalogue')) data = { games: [] }
    if (path.endsWith('/games')) data = [{ id, name: 'VALORANT', enabled: true }, { id: revision, name: 'Jeu désactivé', enabled: false }]
    const root = 'tenants/' + org + '/teams'
    if (path === root && method === 'POST') {
      expect(confirmed).toBe(true); expect(request.headers()['x-tenant-revision']).toBe(revision)
      const body = request.postDataJSON()
      expect(body).toMatchObject({ game_id: id, slug: 'premiere-2026', season: '2026', kind: 'first', status: 'draft' })
      expect(body.organization_id).toBeUndefined(); expect(body.published).toBeUndefined()
      writes++
      team = { ...body, id, game_key: 'valorant', game_name: 'VALORANT', game_available: true, version: revision, publication_status: 'private' }; data = team
    } else if (path === root) data = team ? [team] : []
    if (path === root + '/' + id && method === 'PATCH') {
      const body = request.postDataJSON()
      expect(body.game_id).toBeUndefined(); expect(body.slug).toBeUndefined(); expect(body.season).toBeUndefined()
      expect(body.version).toBe(team?.version); expect(confirmed).toBe(true)
      writes++; team = { ...team, ...body, version: '44444444-4444-4444-8444-444444444444' }; data = team
    }
    await route.fulfill({ headers, json: { data, meta, ...(method !== 'GET' ? { notification: { type: 'success', message: 'Équipe enregistrée.' } } : {}) } })
  })
  await page.goto('/')
  await page.getByRole('button', { name: 'Équipes de l’organisation' }).click()
  const panel = page.getByRole('region', { name: 'Équipes de l’organisation', exact: true })
  await panel.getByRole('button', { name: 'Créer une équipe' }).click()
  const form = panel.getByRole('form', { name: 'Enregistrer l’équipe' })
  await expect(form.getByRole('option', { name: 'Jeu désactivé' })).toHaveCount(0)
  await form.getByLabel('Jeu activé').selectOption(id)
  await form.getByLabel('Slug stable').fill('premiere-2026')
  await form.getByLabel('Saison', { exact: true }).fill('2026')
  await form.getByLabel('Nom de l’équipe').fill('Pilote VALORANT')
  await form.getByLabel('Description prévue pour publication').fill('<script>TEAM_BAD</script>')
  for (const width of [375, 320]) {
    await page.setViewportSize({ width, height: 812 })
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
  }
  await form.screenshot({ path: 'reports/team002-form-mobile.png', style: 'header { visibility: hidden }' })
  await form.getByRole('button', { name: 'Enregistrer l’équipe' }).click()
  expect(writes).toBe(0)
  const approve = async () => {
    const confirmation = page.getByRole('region', { name: 'Confirmer l’action' })
    await expect(confirmation).toContainText('Alpha')
    await confirmation.getByRole('button', { name: 'Confirmer et continuer' }).click()
  }
  await approve()
  await expect(panel.getByRole('heading', { name: 'Pilote VALORANT' })).toBeVisible()
  await expect(panel.getByText('<script>TEAM_BAD</script>', { exact: true })).toBeVisible()
  await expect(panel.getByText('Identité prévue pour publication, actuellement privée.')).toBeVisible()
  await panel.getByRole('button', { name: 'Modifier l’équipe' }).click()
  await expect(form.getByLabel('Slug stable')).toHaveCount(0)
  await form.getByLabel('Nom de l’équipe').fill('Pilote renommée')
  await form.getByRole('button', { name: 'Enregistrer l’équipe' }).click(); await approve()
  await expect(panel.getByRole('heading', { name: 'Pilote renommée' })).toBeVisible()
  const search = panel.getByRole('form', { name: 'Rechercher les équipes' })
  await search.getByLabel('Préfixe du nom').fill('Pilote')
  await search.getByRole('button', { name: 'Rechercher les équipes' }).click()
  await expect(panel.getByRole('heading', { name: 'Pilote renommée' })).toBeVisible()
  await panel.getByRole('button', { name: 'Archiver l’équipe' }).click(); await approve()
  await expect(panel.getByRole('button', { name: 'Modifier l’équipe' })).toHaveCount(0)
  await expect(panel.getByRole('heading', { name: 'Pilote renommée' })).toBeVisible()
  expect(writes).toBe(3)
  for (const button of await page.getByRole('button', { name: 'Fermer la notification', exact: true }).all()) await button.click()
  await panel.screenshot({ path: 'reports/team002-archive-mobile.png', style: 'header { visibility: hidden }' })
  expect(errors).toEqual([])
})
