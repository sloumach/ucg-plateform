import { expect, test } from '@playwright/test'

test('académie : séance, exercices, présence corrigée, horaire périmé et lecture mobile', async ({ page }) => {
  page.on('pageerror', (error) => { throw error })
  const org = '11111111-1111-4111-8111-111111111111', revision = '22222222-2222-4222-8222-222222222222', id = '33333333-3333-4333-8333-333333333333'
  const root = 'tenants/' + org, base = root + '/academy/cohorts/' + id + '/cycles/' + id + '/sessions'
  const headers = { 'Access-Control-Allow-Origin': new URL(test.info().project.use.baseURL ?? 'http://localhost:5187').origin, 'Access-Control-Allow-Credentials': 'true' }
  const context = { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'], permissions: ['organization.view', 'academy.read', 'academy.manage', 'academy.attendance.manage', 'calendar.read'], actor_user_id: 42, is_owner: false, membership_id: org }
  const cohort = { id, name: 'Académie pilote', status: 'active', version: revision }
  const cycle = { ...cohort, name: 'Automne', cohort_id: id, starts_on: '2026-10-01', ends_on: '2026-12-31', timezone: 'UTC', timetable: [] }
  const activity = { id, version: revision, label: 'Coaching collectif', starts_at: '2026-10-22T10:00:00+00:00', ends_at: '2026-10-22T11:00:00+00:00', timezone: 'UTC' }
  let session: Record<string, unknown> | null = null, attendance: Record<string, unknown> | null = null, mutations = 0, changed = false
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
    if (path.endsWith('/cohorts')) data = [cohort]
    if (path.endsWith('/cycles')) data = [cycle]
    if (path.endsWith('/enrollments')) data = [{ id, role: 'mentor', status: 'active', person_label: 'Coach' }, { id: org, role: 'student', status: 'active', person_label: 'Camille' }]
    if (path.endsWith('/session-activities')) data = [activity]
    if (path === base) data = session ? [{ ...session, schedule_state: changed ? 'changed' : 'current' }] : []
    if (path.endsWith('/attendances')) data = attendance ? [attendance] : []
    if (path.endsWith('/history')) data = [{ id, kind: 'attendance', resource_id: org, actor_user_id: 42, recorded_at: '2026-10-22T12:00:00Z' }]
    if (['POST', 'PATCH'].includes(request.method()) && path.startsWith(base)) {
      mutations++; expect(request.headers()['x-tenant-revision']).toBe(revision)
      const body = request.postDataJSON()
      if (path === base) { expect(body.exercises).toEqual([{ title: 'Placement collectif', instructions: null, minutes: 20 }]); expect(body.activity_version).toBe(revision); session = { ...body, id, version: revision, status: 'planned', responsible_label: 'Coach', activity, starts_at: activity.starts_at, ends_at: activity.ends_at, attendance_count: 0 }; data = session }
      else if (path.endsWith('/attendances')) { expect(body).toEqual({ enrollment_id: org, status: 'present' }); attendance = { ...body, id: org, version: revision, person_label: 'Camille', late_minutes: null }; if (session) session.attendance_count = 1; data = attendance }
      else { expect(body).toEqual({ version: revision, status: 'late', late_minutes: 15, change_reason: 'Correction après vérification.' }); attendance = { ...attendance, ...body, version: id }; data = attendance }
    }
    await route.fulfill({ status: request.method() === 'POST' ? 201 : 200, headers, contentType: 'application/json', body: JSON.stringify({ data, meta: { request_id: 'aca002-e2e', current_page: 1, last_page: 1, total: Array.isArray(data) ? data.length : 0 }, ...(['POST', 'PATCH'].includes(request.method()) ? { notification: { type: 'success', message: 'Suivi enregistré.' } } : {}) }) })
  })
  await page.setViewportSize({ width: 375, height: 900 }); await page.goto('/')
  const academy = page.getByRole('region', { name: 'Académie : cohortes et cycles', exact: true })
  await academy.getByRole('button', { name: 'Académie : cohortes et cycles', exact: true }).click()
  await academy.getByRole('button', { name: 'Consulter Académie pilote' }).click()
  await academy.getByRole('button', { name: 'Inscriptions et mentors Automne' }).click()
  const panel = academy.getByRole('region', { name: 'Séances, présences et exercices', exact: true })
  await panel.getByRole('button', { name: 'Séances, présences et exercices', exact: true }).click()
  await panel.getByRole('button', { name: 'Créer une séance' }).click()
  const form = panel.getByRole('form', { name: 'Enregistrer la séance' })
  await form.getByLabel('Titre de la séance').fill('Séance tactique')
  await form.getByLabel('Activité du calendrier').selectOption(id); await form.getByLabel('Mentor responsable').selectOption(id)
  await form.getByRole('button', { name: 'Ajouter un exercice' }).click(); await form.getByLabel('Nom de l’exercice').fill('Placement collectif')
  await form.getByLabel('Compte rendu partagé de la séance').fill('Séance de préparation collective.')
  await form.getByRole('button', { name: 'Enregistrer la séance' }).click(); expect(mutations).toBe(0)
  await panel.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await panel.getByRole('button', { name: 'Consulter les présences Séance tactique' }).click()
  await panel.getByRole('button', { name: 'Saisir une présence' }).click()
  await panel.getByLabel('Inscription concernée').selectOption(org)
  await panel.getByRole('button', { name: 'Enregistrer la présence' }).click(); await panel.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await panel.getByRole('button', { name: 'Corriger la présence de Camille' }).click()
  await panel.getByLabel('Statut de présence').selectOption('late'); await panel.getByLabel('Retard (minutes)').fill('15')
  await panel.getByLabel('Motif opérationnel de la modification').fill('Correction après vérification.')
  await panel.getByRole('button', { name: 'Enregistrer la présence' }).click(); await panel.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await expect(panel.getByText('Camille · En retard · 15 min')).toBeVisible(); expect(mutations).toBe(3)
  await panel.getByRole('button', { name: 'Historique de la séance' }).click()
  await expect(panel.getByText(/Modification de présence · 2026/)).toBeVisible()
  for (const width of [1280, 375, 320]) {
    await page.setViewportSize({ width, height: 900 })
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
    await panel.screenshot({ path: 'reports/aca002-' + width + '.png', style: 'header, [aria-label=Notifications] { visibility: hidden; }' })
  }
  changed = true
  await panel.getByRole('button', { name: 'Fermer les séances' }).click(); await panel.getByRole('button', { name: 'Séances, présences et exercices', exact: true }).click()
  await expect(panel.getByText('Le calendrier a changé. Confirmez les horaires avant une nouvelle saisie de présence.')).toBeVisible()
  await panel.getByRole('button', { name: 'Consulter les présences Séance tactique' }).click()
  await expect(panel.getByText('Camille · En retard · 15 min')).toBeVisible()
  await expect(panel.getByRole('button', { name: 'Saisir une présence' })).toHaveCount(0)
})
