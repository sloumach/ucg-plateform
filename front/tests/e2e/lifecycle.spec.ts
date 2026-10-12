import { expect, test } from '@playwright/test'

test('départ : confirmation, périodes clôturées et équipements encore régularisables sur mobile', async ({ page }) => {
  const org = '11111111-1111-4111-8111-111111111111'
  const revision = '22222222-2222-4222-8222-222222222222'
  const personId = '33333333-3333-4333-8333-333333333333'
  const periodId = '44444444-4444-4444-8444-444444444444'
  const itemId = '55555555-5555-4555-8555-555555555555'
  let departed = false, confirmed = false
  const person = { id: personId, version: revision, pseudonym: 'Camille', language: 'fr', timezone: 'UTC', status: 'active', user_id: 43 }
  const periods: Record<string, unknown>[] = []
  const items: Record<string, unknown>[] = []
  const headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const path = new URL(route.request().url()).pathname.replace('/api/v1/', '')
    const method = route.request().method()
    if (method === 'OPTIONS') {
      await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET, POST, PATCH', 'Access-Control-Allow-Headers': 'Content-Type, X-Tenant-Revision, X-XSRF-TOKEN' } })
      return
    }
    let data: unknown = [], status = 200
    const meta: Record<string, unknown> = { request_id: 'hum004-browser', current_page: 1, last_page: 1, total: 0, unavailable_now: false, pending_items_count: items.filter((item) => item.status === 'open').length }
    if (path === 'auth/me') data = { id: 42, name: 'Manager', email: 'manager@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (path === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (path === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: 'membership' }]
    if (path === 'active-organization/confirmation') confirmed = true
    if (path === 'active-organization' || path === 'active-organization/confirmation') data = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'], permissions: ['organization.view', 'sports.read', 'sports.write', 'administrative.read', 'administrative.write', 'memberships.manage', 'invitations.manage'], is_owner: false, actor_user_id: 42, membership_id: 'membership' }, revision, confirmed }
    if (path.endsWith('/game-profile-catalogue')) data = { games: [] }
    const root = 'tenants/' + org + '/people'
    if (path === root) data = [person]
    if (path === root + '/' + personId) data = person
    if (path.endsWith('/periods')) {
      if (method === 'POST') {
        expect(confirmed).toBe(true)
        const body = route.request().postDataJSON()
        expect(body.kind).toBe('assignment'); expect(body.role_key).toBe('coach')
        expect(body.starts_at).toMatch(/T\d{2}:\d{2}:\d{2}\+00:00$/)
        periods.push({ ...body, id: periodId, version: revision, person_id: personId, effective: true })
        data = periods[0]; status = 201
      } else data = periods
    }
    if (path.endsWith('/departure-items')) {
      if (method === 'POST') {
        const body = route.request().postDataJSON()
        expect(body.title).toBe('Keyboard')
        items.push({ ...body, id: itemId, version: revision, person_id: personId, completed_at: null })
        data = items[0]; status = 201
      } else data = items
      meta.pending_items_count = items.filter((item) => item.status === 'open').length
    }
    if (path.endsWith('/departure-items/' + itemId) && method === 'PATCH') {
      expect(route.request().postDataJSON()).toEqual({ status: 'resolved', version: revision })
      items[0].status = 'resolved'; items[0].completed_at = '2026-10-12T12:00:00+00:00'; data = items[0]
    }
    if (path.endsWith('/departure')) {
      if (method === 'POST') {
        expect(confirmed).toBe(true)
        expect(route.request().headers()['x-tenant-revision']).toBe(revision)
        expect(route.request().postDataJSON()).toEqual({ version: revision, reason_key: 'decision', confirm: true, acknowledge_pending: true })
        departed = true; person.status = 'former'
        person.version = '66666666-6666-4666-8666-666666666666'
        periods[0].status = 'closed'; periods[0].ends_at = '2026-10-12T12:00:00+00:00'; periods[0].effective = false
      }
      data = { departed, person_version: person.version, ...(departed ? { departed_at: '2026-10-12T12:00:00+00:00', reason_key: 'decision', pending_items_count: 1 } : {}) }
    }
    if (Array.isArray(data)) meta.total = data.length
    await route.fulfill({ status, headers, json: { data, meta, ...(method === 'POST' || method === 'PATCH' ? { notification: { type: 'success', message: 'Suivi enregistré.' } } : {}) } })
  })
  await page.goto('/')
  await page.getByRole('button', { name: 'Ouvrir la fiche Camille' }).click()
  await page.getByRole('button', { name: 'Ajouter une période' }).click()
  const periodForm = page.getByRole('form', { name: 'Enregistrer la période' })
  await periodForm.getByLabel('Fonction dans l’organisation').selectOption('coach')
  await periodForm.getByLabel('Début de la période').fill('2026-10-12T09:00')
  await periodForm.getByRole('button', { name: 'Enregistrer la période' }).click()
  await page.getByRole('region', { name: 'Confirmer l’action' }).getByRole('button', { name: 'Confirmer et continuer' }).click()
  await page.getByRole('button', { name: 'Ajouter un élément à régulariser' }).click()
  const itemForm = page.getByRole('form', { name: 'Enregistrer l’élément' })
  await itemForm.getByLabel('Libellé administratif').fill('Keyboard')
  await itemForm.getByRole('button', { name: 'Enregistrer l’élément' }).click()
  await page.getByRole('region', { name: 'Confirmer l’action' }).getByRole('button', { name: 'Confirmer et continuer' }).click()
  const departForm = page.getByRole('form', { name: 'Départ effectif de l’organisation' })
  await departForm.getByLabel('Motif de départ').selectOption('decision')
  await departForm.getByLabel('Je confirme le départ effectif de cette personne.').check()
  await departForm.getByLabel('Je confirme que les éléments ouverts resteront à traiter après le retrait des accès.').check()
  await departForm.getByRole('button', { name: 'Confirmer le départ' }).click()
  expect(departed).toBe(false)
  const confirmation = page.getByRole('region', { name: 'Confirmer l’action' })
  await expect(confirmation).toBeFocused()
  await expect(confirmation).toContainText('Camille')
  await expect(confirmation).toContainText('Alpha')
  await confirmation.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await page.getByRole('button', { name: 'Ouvrir la fiche Camille' }).click()
  await expect(page.getByText(/Départ enregistré le/)).toBeVisible()
  await expect(page.getByRole('button', { name: 'Confirmer le départ' })).toHaveCount(0)
  await expect(page.getByRole('button', { name: 'Ajouter une période' })).toHaveCount(0)
  await page.getByRole('button', { name: 'Modifier l’élément · Keyboard' }).click()
  await page.getByRole('form', { name: 'Enregistrer l’élément' }).getByLabel('État de la régularisation').selectOption('resolved')
  await page.getByRole('form', { name: 'Enregistrer l’élément' }).getByRole('button', { name: 'Enregistrer l’élément' }).click()
  await page.getByRole('region', { name: 'Confirmer l’action' }).getByRole('button', { name: 'Confirmer et continuer' }).click()
  await expect(page.getByText('0 élément(s) encore ouvert(s).')).toBeVisible()
  const panel = page.getByRole('region', { name: 'Affectations, indisponibilités et départ', exact: true })
  for (const width of [375, 320]) {
    await page.setViewportSize({ width, height: 812 })
    await expect(panel).toBeVisible()
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
  }
  await panel.screenshot({ path: 'reports/hum004-lifecycle-mobile.png' })
})
