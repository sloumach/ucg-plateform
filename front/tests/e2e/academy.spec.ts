import { expect, test } from '@playwright/test'

test('académie : cohorte, cycle, créneau, mentor, historique et affichage mobile', async ({ page }) => {
  page.on('pageerror', (error) => { throw error })
  const org = '11111111-1111-4111-8111-111111111111', revision = '22222222-2222-4222-8222-222222222222', id = '33333333-3333-4333-8333-333333333333'
  const root = 'tenants/' + org, base = root + '/academy/cohorts'
  const headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }
  const context = { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'Europe/Paris', language: 'fr', roles: ['member'], permissions: ['organization.view', 'academy.read', 'academy.manage'], actor_user_id: 42, is_owner: false, membership_id: org }
  const cohorts: Record<string, unknown>[] = [], cycles: Record<string, unknown>[] = [], enrollments: Record<string, unknown>[] = []
  let mutations = 0
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request(), path = new URL(request.url()).pathname.replace('/api/v1/', '')
    if (request.method() === 'OPTIONS') { await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET,POST,PATCH', 'Access-Control-Allow-Headers': 'Content-Type,X-Tenant-Revision,X-XSRF-TOKEN' } }); return }
    let data: unknown = []
    if (path === 'auth/me') data = { id: 42, name: 'Coach', email: 'coach@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (path === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (path === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: org }]
    if (path === 'active-organization') data = { context, revision, confirmed: true }
    if (path.endsWith('/game-profile-catalogue')) data = { games: [] }
    if (path === base) data = cohorts
    if (path.endsWith('/cycles')) data = cycles
    if (path.endsWith('/enrollments')) data = enrollments
    if (path === root + '/academy/people') data = [{ id, label: 'Camille' }]
    if (request.method() === 'POST' && path.startsWith(base)) {
      expect(request.headers()['x-tenant-revision']).toBe(revision)
      mutations++
      const body = request.postDataJSON()
      if (path === base) { data = { ...body, id, version: revision, status: 'active' }; cohorts.push(data as Record<string, unknown>) }
      if (path.endsWith('/cycles')) { expect(body.timetable).toEqual([{ day: 1, starts_at: '18:00', ends_at: '20:00' }]); data = { ...body, id, cohort_id: id, version: revision, status: 'active' }; cycles.push(data as Record<string, unknown>) }
      if (path.endsWith('/enrollments')) { expect(body.role).toBe('mentor'); expect(body).not.toHaveProperty('permissions'); data = { ...body, id, person_label: 'Camille', version: revision, status: 'active' }; enrollments.push(data as Record<string, unknown>) }
    }
    await route.fulfill({ status: request.method() === 'POST' ? 201 : 200, headers, contentType: 'application/json', body: JSON.stringify({ data, meta: { request_id: 'aca001-e2e', current_page: 1, last_page: 1, total: Array.isArray(data) ? data.length : 0 }, ...(request.method() === 'POST' ? { notification: { type: 'success', message: 'Académie mise à jour.' } } : {}) }) })
  })
  await page.setViewportSize({ width: 375, height: 812 }); await page.goto('/')
  const panel = page.getByRole('region', { name: 'Académie : cohortes et cycles', exact: true })
  await panel.getByRole('button', { name: 'Académie : cohortes et cycles', exact: true }).click()
  await panel.getByRole('button', { name: 'Créer une cohorte' }).click()
  await panel.getByLabel('Nom', { exact: true }).fill('Académie pilote')
  await panel.getByRole('button', { name: 'Enregistrer la cohorte' }).click()
  expect(mutations).toBe(0)
  await panel.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await panel.getByRole('button', { name: 'Consulter Académie pilote' }).click()
  await panel.getByRole('button', { name: 'Créer un cycle' }).click()
  const cycleForm = panel.getByRole('form', { name: 'Enregistrer le cycle' })
  await cycleForm.getByLabel('Nom', { exact: true }).fill('Automne')
  await cycleForm.getByLabel('Date de début').fill('2026-10-01'); await cycleForm.getByLabel('Date de fin').fill('2026-12-31')
  await cycleForm.getByRole('button', { name: 'Ajouter un créneau' }).click()
  await cycleForm.getByRole('button', { name: 'Enregistrer le cycle' }).click(); await panel.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await panel.getByRole('button', { name: 'Inscriptions et mentors Automne' }).click()
  await panel.getByRole('button', { name: 'Ajouter une inscription ou un mentor' }).click()
  await panel.getByLabel('Personne', { exact: true }).selectOption(id); await panel.getByLabel('Rôle dans le cycle').selectOption('mentor')
  await panel.getByRole('button', { name: 'Enregistrer l’affectation' }).click(); await panel.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await expect(panel.getByText('Camille · Mentor')).toBeVisible(); expect(mutations).toBe(3)
  for (const width of [1280, 375, 320]) {
    await page.setViewportSize({ width, height: 900 })
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
    await panel.screenshot({ path: 'reports/aca001-' + width + '.png', style: 'header, [aria-label=Notifications] { visibility: hidden; }' })
  }
  await panel.getByRole('button', { name: 'Historique', exact: true }).click()
  await expect(panel.getByText('Aucun élément conservé.')).toBeVisible()
  await panel.getByRole('button', { name: 'Cycles', exact: true }).click()
  await expect(panel.getByText('Automne', { exact: true })).toBeVisible()
})
