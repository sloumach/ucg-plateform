import { expect, test } from '@playwright/test'

test('académie : objectif individuel, partage, note justifiée et lecture personnelle mobile', async ({ page }) => {
  page.on('pageerror', (error) => { throw error })
  const org = '11111111-1111-4111-8111-111111111111', revision = '22222222-2222-4222-8222-222222222222', id = '33333333-3333-4333-8333-333333333333'
  const root = 'tenants/' + org, base = root + '/academy/cohorts/' + id + '/cycles/' + id + '/objectives'
  const headers = { 'Access-Control-Allow-Origin': new URL(test.info().project.use.baseURL ?? 'http://localhost:5188').origin, 'Access-Control-Allow-Credentials': 'true' }
  const cohort = { id, name: 'Académie pilote', status: 'active', version: revision }
  const cycle = { ...cohort, name: 'Automne', cohort_id: id, starts_on: '2026-10-01', ends_on: '2026-12-31', timezone: 'UTC', timetable: [] }
  let player = false, mutations = 0, goal: Record<string, unknown> | null = null
  const shared: Record<string, unknown>[] = [], notes: Record<string, unknown>[] = [], personalRequests: string[] = []
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request(), path = new URL(request.url()).pathname.replace('/api/v1/', '')
    if (request.method() === 'OPTIONS') { await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET,POST,PATCH', 'Access-Control-Allow-Headers': 'Content-Type,X-Tenant-Revision,X-XSRF-TOKEN' } }); return }
    if (player) personalRequests.push(path)
    const context = { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'], permissions: player ? ['organization.view', 'academy.self.read'] : ['organization.view', 'academy.read', 'academy.manage', 'academy.feedback.manage', 'academy.notes.manage'], actor_user_id: player ? 43 : 42, is_owner: false, membership_id: org }
    let data: unknown = []
    if (path === 'auth/me') data = { id: player ? 43 : 42, name: player ? 'Camille' : 'Coach', email: 'user@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (path === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (path === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: org }]
    if (path === 'active-organization') data = { context, revision, confirmed: true }
    if (path.endsWith('/game-profile-catalogue')) data = { games: [] }
    if (path.endsWith('/cohorts')) data = [cohort]
    if (path.endsWith('/cycles')) data = [cycle]
    if (path.endsWith('/enrollments')) data = [{ id, role: 'mentor', status: 'active', person_label: 'Coach' }, { id: org, role: 'student', status: 'active', person_label: 'Camille' }]
    if (path === base) data = goal ? [goal] : []
    if (path.endsWith('/feedback')) data = player ? shared.map((row) => ({ id: row.id, content: row.content, created_at: row.created_at })) : shared
    if (path.endsWith('/notes')) data = notes
    if (path.endsWith('/history')) data = [{ id, kind: 'feedback', resource_id: id, actor_user_id: 42, recorded_at: '2026-10-23T00:00:00Z' }]
    if (path.endsWith('/my-objectives')) data = goal ? [{ id, title: goal.title, description: goal.description, due_on: goal.due_on, status: goal.status, scope: goal.scope }] : []
    if (request.method() === 'POST' && path.startsWith(base)) {
      expect(player).toBe(false); mutations++; expect(request.headers()['x-tenant-revision']).toBe(revision)
      const body = request.postDataJSON()
      if (path === base) { expect(body.target_enrollment_id).toBe(org); goal = { ...body, id, version: revision, status: 'active', scope: 'individual', responsible_label: 'Coach', target_label: 'Camille' }; data = goal }
      else {
        const internal = path.endsWith('/notes')
        expect(Object.hasOwn(body, 'audience')).toBe(false)
        if (internal) expect(body.justification).toBe('Préparation du prochain entretien.')
        const row = { ...body, id: internal ? org : id, version: revision, status: 'active', audience: internal ? 'internal' : 'shared', author_user_id: 42, created_at: '2026-10-23T00:00:00Z' }
        if (internal) notes.push(row); else shared.push(row); data = row
      }
    }
    await route.fulfill({ status: request.method() === 'POST' ? 201 : 200, headers, contentType: 'application/json', body: JSON.stringify({ data, meta: { request_id: 'aca003-browser', current_page: 1, last_page: 1, total: Array.isArray(data) ? data.length : 0 }, ...(request.method() === 'POST' ? { notification: { type: 'success', message: 'Suivi enregistré.' } } : {}) }) })
  })
  await page.setViewportSize({ width: 375, height: 900 }); await page.goto('/')
  const academy = page.getByRole('region', { name: 'Académie : cohortes et cycles', exact: true })
  await academy.getByRole('button', { name: 'Académie : cohortes et cycles', exact: true }).click()
  await academy.getByRole('button', { name: 'Consulter Académie pilote' }).click(); await academy.getByRole('button', { name: 'Inscriptions et mentors Automne' }).click()
  const panel = academy.getByRole('region', { name: 'Objectifs et feedback', exact: true })
  await panel.getByRole('button', { name: 'Objectifs et feedback', exact: true }).click(); await panel.getByRole('button', { name: 'Créer un objectif' }).click()
  const form = panel.getByRole('form', { name: 'Enregistrer l’objectif' })
  await form.getByLabel('Titre de l’objectif').fill('Placement individuel'); await form.getByLabel('Description pédagogique').fill('Préparer les déplacements collectifs.')
  await form.getByLabel('Mentor responsable').selectOption(id); await form.getByLabel('Destinataire de l’objectif').selectOption(org)
  await form.getByRole('button', { name: 'Enregistrer l’objectif' }).click(); expect(mutations).toBe(0); await panel.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await panel.getByRole('button', { name: 'Feedback partagé Placement individuel' }).click(); await panel.getByRole('button', { name: 'Publier le feedback partagé' }).click()
  const feedbackForm = panel.getByRole('form', { name: 'Publier le feedback partagé' })
  await feedbackForm.getByLabel('Contenu pédagogique').fill('Communiquez vos intentions.'); await feedbackForm.getByRole('button', { name: 'Publier le feedback partagé' }).click(); expect(mutations).toBe(1)
  await panel.getByRole('button', { name: 'Confirmer et continuer' }).click(); await expect(panel.getByText('Communiquez vos intentions.')).toBeVisible()
  await panel.getByRole('button', { name: 'Notes internes Placement individuel' }).click(); await panel.getByRole('button', { name: 'Enregistrer la note interne' }).click()
  const noteForm = panel.getByRole('form', { name: 'Enregistrer la note interne' })
  await noteForm.getByLabel('Contenu pédagogique').fill('Observation privée du mentor.'); await noteForm.getByLabel('Justification de la note interne').fill('Préparation du prochain entretien.')
  await noteForm.getByRole('button', { name: 'Enregistrer la note interne' }).click(); expect(mutations).toBe(2); await panel.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await expect(panel.getByText('Observation privée du mentor.')).toBeVisible(); expect(mutations).toBe(3)
  for (const width of [1280, 375, 320]) {
    await page.setViewportSize({ width, height: 900 }); expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
    await panel.screenshot({ path: 'reports/aca003-staff-' + width + '.png', style: 'header, [aria-label=Notifications] { visibility: hidden; }' })
  }
  player = true; await page.reload()
  const personal = page.getByRole('region', { name: 'Mes objectifs et retours', exact: true })
  await personal.getByRole('button', { name: 'Mes objectifs et retours', exact: true }).click(); await personal.getByRole('button', { name: 'Feedback partagé Placement individuel' }).click()
  await expect(personal.getByText('Communiquez vos intentions.')).toBeVisible(); await expect(page.getByText('Observation privée du mentor.')).toHaveCount(0)
  await expect(page.getByRole('button', { name: /Notes internes|Publier le feedback|Créer un objectif/ })).toHaveCount(0)
  expect(personalRequests.filter((path) => path.includes('/academy/')).every((path) => path.startsWith(root + '/academy/my-objectives'))).toBe(true)
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
  await personal.screenshot({ path: 'reports/aca003-personal-320.png' })
})
