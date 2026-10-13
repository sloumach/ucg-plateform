import { expect, test, type Page } from '@playwright/test'

const org = '11111111-1111-4111-8111-111111111111', revision = '22222222-2222-4222-8222-222222222222'
const id = '33333333-3333-4333-8333-333333333333', nextVersion = '44444444-4444-4444-8444-444444444444'
async function mockWorkspace(page: Page, operator: boolean, business: (path: string, method: string, body: Record<string, unknown>, headers: Record<string, string>) => unknown) {
  const headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request(), url = new URL(request.url()), path = url.pathname.replace('/api/v1/', ''), method = request.method()
    if (method === 'OPTIONS') { await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET, POST, PATCH', 'Access-Control-Allow-Headers': 'Content-Type, X-Tenant-Revision, X-XSRF-TOKEN' } }); return }
    let data: unknown = []
    const meta = { request_id: 'team001-browser', current_page: 1, last_page: 1, total: 1, catalogue_version: revision, can_manage: operator }
    if (path === 'auth/me') data = { id: 42, name: operator ? 'Opérateur' : 'Manager', email: 'manager@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (path === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (path === 'accessible-organizations' && !operator) data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: 'membership' }]
    if (path === 'active-organization' || path === 'active-organization/confirmation') data = { context: operator ? null : { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'], permissions: ['organization.view', 'sports.read', 'games.manage'], is_owner: false, actor_user_id: 42, membership_id: 'membership' }, revision: operator ? null : revision, confirmed: path.endsWith('/confirmation') }
    if (path.endsWith('/game-profile-catalogue')) data = { games: [] }
    const custom = business(path + url.search, method, method === 'GET' ? {} : request.postDataJSON(), request.headers())
    if (custom !== undefined) data = custom
    await route.fulfill({ headers, json: { data, meta, ...(method !== 'GET' ? { notification: { type: 'success', message: 'Catalogue enregistré.' } } : {}) } })
  })
}

test('catalogue commun : création, désactivation, carte et confirmation globale sur mobile', async ({ page }) => {
  const errors: string[] = []
  page.on('pageerror', (error) => errors.push(error.message))
  page.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()) })
  const game = { id, kind: 'game', key: 'valorant', name: 'VALORANT', game_id: null, active: true, version: revision, definition: { platforms: { pc: ['riot'] }, regions: ['eu'] } }
  const games: Record<string, unknown>[] = [game], maps: Record<string, unknown>[] = []
  let writes = 0
  await mockWorkspace(page, true, (path, method, body, headers) => {
    if (path.startsWith('game-catalogue?')) {
      const kind = new URL('http://example.test/' + path).searchParams.get('kind')
      if (kind === 'game') return games
      if (kind === 'map') return maps
      const key = kind === 'platform' ? 'pc' : kind === 'provider' ? 'steam' : 'eu'
      return [{ id: kind + '-uuid', kind, key, name: kind === 'platform' ? 'PC' : kind === 'provider' ? 'Steam' : 'Europe', active: true, game_id: null, definition: null, version: revision }]
    }
    if (path.startsWith('game-catalogue') && method !== 'GET') {
      expect(body.confirm_global).toBe(true); expect(body.catalogue_version).toBe(revision)
      expect(headers['x-tenant-revision']).toBeUndefined(); expect(body.organization_id).toBeUndefined()
      writes++
      if (method === 'PATCH') { games[1] = { ...games[1], ...body, version: nextVersion }; return games[1] }
      const created = { ...body, id: nextVersion, game_id: body.game_id ?? null, version: nextVersion }
      if (body.kind === 'map') { expect(body.game_id).toBe(id); maps.push(created) } else games.push(created)
      return created
    }
    if (path.startsWith('tenants/') && method !== 'GET') throw new Error('Global editing must not write tenant data')
    return undefined
  })
  await page.goto('/')
  await page.getByRole('button', { name: 'Catalogue commun des jeux' }).click()
  await page.getByRole('button', { name: 'Ajouter une référence' }).click()
  const form = page.getByRole('form', { name: 'Enregistrer la référence' })
  await form.getByLabel('Clé stable').fill('pilot-game')
  await form.getByLabel('Nom', { exact: true }).fill('Jeu pilote')
  await form.getByLabel('PC', { exact: true }).check()
  await form.getByLabel('Steam', { exact: true }).check()
  await form.getByLabel('Europe', { exact: true }).check()
  await form.getByRole('button', { name: 'Enregistrer la référence' }).click()
  const approval = page.getByRole('region', { name: 'Confirmer le catalogue commun' })
  await expect(approval).toBeFocused()
  await expect(approval.getByRole('button', { name: 'Appliquer au catalogue commun' })).toBeDisabled()
  expect(writes).toBe(0)
  const confirm = async () => {
    await approval.getByLabel('Je confirme la modification du catalogue commun à toutes les organisations.').check()
    await approval.getByRole('button', { name: 'Appliquer au catalogue commun' }).click()
  }
  await confirm()
  await expect(page.getByText('Jeu pilote', { exact: true })).toBeVisible()
  const card = page.getByRole('listitem').filter({ has: page.getByText('Jeu pilote', { exact: true }) })
  await card.getByRole('button', { name: 'Modifier' }).click()
  await expect(form.getByLabel('Clé stable')).toHaveCount(0)
  await form.getByLabel('Référence disponible', { exact: true }).uncheck()
  await form.getByRole('button', { name: 'Enregistrer la référence' }).click(); await confirm()
  await expect(card).toContainText('Retiré du catalogue commun')
  await page.getByLabel('Catégorie').selectOption('map')
  await page.getByLabel('Jeu', { exact: true }).selectOption(id)
  await page.getByRole('button', { name: 'Ajouter une référence' }).click()
  await form.getByLabel('Clé stable').fill('ascent')
  await form.getByLabel('Nom', { exact: true }).fill('Ascent')
  await form.getByRole('button', { name: 'Enregistrer la référence' }).click(); await confirm()
  await expect(page.getByText('Ascent', { exact: true })).toBeVisible()
  expect(writes).toBe(3)
  for (const button of await page.getByRole('button', { name: 'Fermer la notification', exact: true }).all()) await button.click()
  const panel = page.getByRole('region', { name: 'Catalogue commun des jeux', exact: true })
  for (const width of [375, 320]) {
    await page.setViewportSize({ width, height: 812 })
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
  }
  await panel.screenshot({ path: 'reports/team001-global-mobile.png' })
  expect(errors).toEqual([])
})

test('jeux tenant : sélection locale et confirmation de l’organisation sur mobile', async ({ page }) => {
  const errors: string[] = []
  page.on('pageerror', (error) => errors.push(error.message))
  page.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()) })
  let confirmed = false, writes = 0
  let game = { id, key: 'valorant', name: 'VALORANT', available: true, enabled: true, configured_enabled: true, restrictions: {}, version: null as string | null,
    catalogue_version: revision, options: { platform: [{ key: 'pc', name: 'PC' }], provider: [], region: [{ key: 'eu', name: 'Europe' }], mode: [], role: [], map: [], release: [] },
    effective_options: { platform: [], provider: [], region: [], mode: [], role: [], map: [], release: [] } }
  await mockWorkspace(page, false, (path, method, body, headers) => {
    if (path === 'active-organization/confirmation') confirmed = true
    if (path.startsWith('tenants/' + org + '/games?')) return [game]
    if (path === 'tenants/' + org + '/games/' + id) {
      if (method === 'PATCH') {
        expect(confirmed).toBe(true); expect(headers['x-tenant-revision']).toBe(revision)
        expect(body).toEqual({ enabled: true, restrictions: { region: ['eu'] }, version: null, catalogue_version: revision })
        game = { ...game, restrictions: body.restrictions as Record<string, string[]>, version: nextVersion }; writes++
      }
      return game
    }
    if (path.startsWith('game-catalogue') && method !== 'GET') throw new Error('Tenant editing must not write global metadata')
    return undefined
  })
  await page.goto('/')
  await page.getByRole('button', { name: 'Jeux de l’organisation' }).click()
  const panel = page.getByRole('region', { name: 'Jeux de l’organisation', exact: true })
  await panel.getByRole('button', { name: 'Modifier' }).click()
  const form = page.getByRole('form', { name: 'Enregistrer les choix du jeu' })
  const regions = form.getByRole('group', { name: 'Régions de contexte' })
  await regions.getByLabel('Utiliser tous les choix disponibles').uncheck()
  await regions.getByLabel('Europe', { exact: true }).check()
  for (const width of [375, 320]) {
    await page.setViewportSize({ width, height: 812 })
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
  }
  await form.screenshot({ path: 'reports/team001-tenant-mobile.png', style: 'header { visibility: hidden }' })
  await form.getByRole('button', { name: 'Enregistrer les choix du jeu' }).click()
  expect(writes).toBe(0)
  const confirmation = page.getByRole('region', { name: 'Confirmer l’action' })
  await expect(confirmation).toContainText('Alpha')
  await confirmation.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await expect.poll(() => writes).toBe(1)
  expect(errors).toEqual([])
})
