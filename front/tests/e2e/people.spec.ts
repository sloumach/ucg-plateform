import { expect, test } from '@playwright/test'

test('personnes : création confirmée, recherche, profils et comptes sur mobile', async ({ page }) => {
  const org = '11111111-1111-4111-8111-111111111111'
  const revision = '22222222-2222-4222-8222-222222222222'
  const personId = '33333333-3333-4333-8333-333333333333'
  const profileId = '44444444-4444-4444-8444-444444444444'
  const accountId = '55555555-5555-4555-8555-555555555555'
  let confirmed = false
  const people: Record<string, unknown>[] = []
  const profiles: Record<string, unknown>[] = []
  const accounts: Record<string, unknown>[] = []
  const headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }
  const catalogue = { games: [{ key: 'valorant', name: 'VALORANT', regions: [{ key: 'eu', name: 'Europe' }], platforms: [{ key: 'pc', name: 'PC', providers: [{ key: 'riot', name: 'Riot' }] }] }] }
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url())
    const path = url.pathname.replace('/api/v1/', '')
    const method = route.request().method()
    if (method === 'OPTIONS') {
      await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET, POST, PATCH', 'Access-Control-Allow-Headers': 'Content-Type, X-Tenant-Revision, X-XSRF-TOKEN' } })
      return
    }
    let data: unknown = []
    let status = 200
    let notification: unknown
    if (path === 'auth/me') data = { id: 42, name: 'Manager', email: 'manager@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (path === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (path === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: 'membership' }]
    if (path === 'active-organization/confirmation') {
      expect(route.request().postDataJSON()).toEqual({ organization_id: org, revision, confirm: true })
      confirmed = true
    }
    if (path === 'active-organization' || path === 'active-organization/confirmation') data = {
      context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'], permissions: ['organization.view', 'sports.read', 'sports.write'], is_owner: false, actor_user_id: 42, membership_id: 'membership' }, revision, confirmed,
    }
    if (path.endsWith('/game-profile-catalogue')) data = catalogue
    if (path.endsWith('/departure')) data = { departed: false, person_version: revision }
    const peoplePath = 'tenants/' + org + '/people'
    if (path === peoplePath) {
      if (method === 'POST') {
        expect(confirmed).toBe(true)
        expect(route.request().headers()['x-tenant-revision']).toBe(revision)
        const body = route.request().postDataJSON()
        expect(body.pseudonym).toBe('Camille')
        expect(body).not.toHaveProperty('first_name')
        people.push({ ...body, id: personId, version: revision })
        data = people[0]; status = 201
        notification = { type: 'success', message: 'La fiche personne a été créée.' }
      } else {
        const prefix = url.searchParams.get('q')?.toLowerCase()
        data = people.filter((person) => !prefix || String(person.pseudonym).toLowerCase().startsWith(prefix))
      }
    }
    if (path === peoplePath + '/' + personId) {
      if (method === 'PATCH') {
        expect(route.request().postDataJSON()).toEqual({ pseudonym: 'Camille2', version: revision })
        Object.assign(people[0], { pseudonym: 'Camille2' })
      }
      data = people[0]
    }
    if (path.endsWith('/game-profiles')) {
      if (method === 'POST') {
        expect(route.request().postDataJSON()).toEqual({ game_key: 'valorant', platform_key: 'pc', region_key: 'eu', rank: 'Gold', game_role: null, observed_at: null, status: 'active' })
        profiles.push({ ...route.request().postDataJSON(), id: profileId, version: revision, freshness: 'unknown', external_accounts_count: 0 })
        people[0].game_profiles_count = profiles.length
        data = profiles[0]; status = 201
      } else data = profiles
    }
    if (path.endsWith('/external-accounts')) {
      if (method === 'POST') {
        expect(route.request().postDataJSON()).toEqual({ provider_key: 'riot', external_id: null, display_name: 'Alias#EU', observed_at: null, status: 'active' })
        accounts.push({ ...route.request().postDataJSON(), id: accountId, version: revision, freshness: 'unknown', ownership_verified: false })
        profiles[0].external_accounts_count = 1
        data = accounts[0]; status = 201
      } else data = accounts
    }
    const meta = { request_id: 'browser-people', current_page: 1, last_page: 1, total: Array.isArray(data) ? data.length : 1, unavailable_now: false, pending_items_count: 0 }
    await route.fulfill({ status, headers, json: { data, meta, ...(notification ? { notification } : {}) } })
  })
  await page.goto('/')
  const panel = page.getByRole('region', { name: 'Personnes', exact: true })
  await panel.getByRole('button', { name: 'Ajouter une personne' }).click()
  await panel.getByLabel('Pseudonyme', { exact: true }).fill('Camille')
  await panel.getByRole('button', { name: 'Enregistrer la fiche' }).click()
  expect(people).toHaveLength(0)
  const confirmation = panel.getByRole('region', { name: 'Confirmer l’action' })
  await expect(confirmation).toBeFocused()
  await confirmation.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await expect(panel.getByRole('button', { name: 'Ouvrir la fiche Camille' })).toBeVisible()
  await panel.getByLabel('Début du pseudonyme').fill('Ca')
  await panel.getByRole('button', { name: 'Appliquer les filtres' }).click()
  await panel.getByRole('button', { name: 'Ouvrir la fiche Camille' }).click()
  await panel.getByRole('button', { name: 'Modifier la fiche', exact: true }).click()
  const personForm = panel.getByRole('form', { name: 'Enregistrer la fiche' })
  await personForm.getByLabel('Pseudonyme', { exact: true }).fill('Camille2')
  await personForm.getByRole('button', { name: 'Enregistrer la fiche' }).click()
  await confirmation.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await panel.getByRole('button', { name: 'Ouvrir la fiche Camille2' }).click()
  await panel.getByRole('button', { name: 'Ajouter un profil de jeu' }).click()
  const profileForm = panel.getByRole('form', { name: 'Enregistrer le profil de jeu' })
  await profileForm.getByLabel('Jeu', { exact: true }).selectOption('valorant')
  await profileForm.getByLabel('Plateforme', { exact: true }).selectOption('pc')
  await profileForm.getByLabel('Zone de jeu').selectOption('eu')
  await profileForm.getByLabel('Rang déclaré').fill('Gold')
  await profileForm.getByRole('button', { name: 'Enregistrer le profil de jeu' }).click()
  await confirmation.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await panel.getByRole('button', { name: /Afficher les comptes externes/ }).click()
  await panel.getByRole('button', { name: 'Ajouter un compte externe' }).click()
  const accountForm = panel.getByRole('form', { name: 'Enregistrer le compte externe' })
  await accountForm.getByLabel('Fournisseur du compte').selectOption('riot')
  await accountForm.getByLabel('Alias du compte externe').fill('Alias#EU')
  await accountForm.getByRole('button', { name: 'Enregistrer le compte externe' }).click()
  await confirmation.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await panel.getByRole('button', { name: /Afficher les comptes externes/ }).click()
  await expect(panel.getByText('riot · Alias#EU · Actif')).toBeVisible()
  for (const width of [375, 320]) {
    await page.setViewportSize({ width, height: 812 })
    await expect(panel.getByRole('button', { name: 'Ajouter un compte externe' })).toBeVisible()
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
  }
  await panel.screenshot({ path: 'reports/hum003-people-mobile.png' })
})

test('changer d’organisation efface la fiche et une confirmation en attente sans écriture', async ({ page }) => {
  const alpha = '11111111-1111-4111-8111-111111111111'
  const beta = '22222222-2222-4222-8222-222222222222'
  const revision = '33333333-3333-4333-8333-333333333333'
  let selected = alpha
  let writes = 0
  const headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const path = new URL(route.request().url()).pathname.replace('/api/v1/', '')
    const method = route.request().method()
    if (method === 'OPTIONS') {
      await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET, POST, PATCH', 'Access-Control-Allow-Headers': 'Content-Type, X-Tenant-Revision, X-XSRF-TOKEN' } })
      return
    }
    let data: unknown = []
    if (path === 'auth/me') data = { id: 42, name: 'Manager', email: 'manager@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (path === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (path === 'accessible-organizations') data = [alpha, beta].map((id) => ({ id, name: id === alpha ? 'Alpha' : 'Beta', slug: id === alpha ? 'alpha' : 'beta', roles: ['member'], is_owner: false, membership_id: 'membership' }))
    if (path === 'active-organization' && method === 'POST') selected = route.request().postDataJSON().organization_id
    if (path === 'active-organization') data = { context: { organization_id: selected, name: selected === alpha ? 'Alpha' : 'Beta', slug: 'test', timezone: 'UTC', language: 'fr', roles: ['member'], permissions: selected === alpha ? ['organization.view', 'sports.read', 'sports.write'] : ['organization.view'], is_owner: false, actor_user_id: 42, membership_id: 'membership' }, revision, confirmed: false }
    if (path.endsWith('/game-profile-catalogue')) data = { games: [] }
    if (path.endsWith('/departure')) data = { departed: false, person_version: revision }
    if (path === 'tenants/' + alpha + '/people') data = [{ id: '44444444-4444-4444-8444-444444444444', version: revision, pseudonym: 'SECRET_ALPHA', language: 'fr', timezone: 'UTC', status: 'active', game_profiles_count: 0 }]
    if (path.includes('/people') && method !== 'GET') writes++
    await route.fulfill({ status: 200, headers, json: { data, meta: { request_id: 'browser-isolation', current_page: 1, last_page: 1, total: Array.isArray(data) ? data.length : 1 } } })
  })
  await page.goto('/')
  const panel = page.getByRole('region', { name: 'Personnes', exact: true })
  await expect(panel.getByText('SECRET_ALPHA', { exact: true })).toBeVisible()
  await panel.getByRole('button', { name: 'Ajouter une personne' }).click()
  await panel.getByLabel('Pseudonyme', { exact: true }).fill('SECRET_DRAFT')
  await panel.getByRole('button', { name: 'Enregistrer la fiche' }).click()
  await expect(panel.getByRole('region', { name: 'Confirmer l’action' })).toBeVisible()
  await page.getByRole('button', { name: /Ouvrir Beta/ }).click()
  await expect(page.getByText('Organisation active : Beta')).toBeVisible()
  await expect(page.getByRole('region', { name: 'Personnes', exact: true })).toHaveCount(0)
  await expect(page.getByText('SECRET_ALPHA', { exact: true })).toHaveCount(0)
  await expect(page.getByText('SECRET_DRAFT', { exact: true })).toHaveCount(0)
  expect(writes).toBe(0)
})
