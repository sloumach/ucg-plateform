import { expect, test } from '@playwright/test'

test('convocations : confirmation, réponse personnelle et historique sur mobile', async ({ page }) => {
  const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222'
  const version = '33333333-3333-4333-8333-333333333333', person = '44444444-4444-4444-8444-444444444444', invitationId = '55555555-5555-4555-8555-555555555555'
  const root = 'tenants/' + org + '/calendar', title = '<script>CAL004</script> ' + 'ActivitéÉquipeLongue'.repeat(3)
  const activity = { id, version, title, description: null, kind: 'training', starts_at: '2026-10-18T08:00:00+00:00', ends_at: '2026-10-18T09:00:00+00:00', origin_timezone: 'Europe/Paris',
    starts_at_origin: '2026-10-18T10:00:00+02:00', ends_at_origin: '2026-10-18T11:00:00+02:00', team_id: null, team_label: null, location: null, url: null, visibility: 'private',
    participants: [{ id: person, label: '<img>Joueur</img>' }], archived_at: null }
  let rows: Record<string, unknown>[] = [], confirmed = false, issues = 0, replies = 0
  const errors: string[] = []
  const headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }
  page.on('pageerror', (error) => errors.push(error.message))
  page.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()) })
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request(), url = new URL(request.url()), path = url.pathname.replace('/api/v1/', ''), method = request.method()
    if (method === 'OPTIONS') { await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET, POST, PATCH', 'Access-Control-Allow-Headers': 'Content-Type, X-Tenant-Revision, X-XSRF-TOKEN' } }); return }
    let data: unknown = [], status = 200
    if (path === 'auth/me') data = { id: 42, name: 'Membre calendrier', email: 'calendar@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (path === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (path === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: id }]
    if (path === 'active-organization/confirmation') confirmed = true
    if (path === 'active-organization' || path === 'active-organization/confirmation') data = {
      context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'Europe/Paris', language: 'fr', roles: ['member'],
        permissions: ['organization.view', 'calendar.read', 'calendar.write', 'calendar.invitations.manage', 'calendar.invitations.respond'], is_owner: false, actor_user_id: 42, membership_id: id }, revision: version, confirmed }
    if (path === root + '/activities') data = [activity]
    if (path === root + '/activities/' + id + '/invitations' && method === 'POST') {
      expect(confirmed).toBe(true); expect(request.headers()['x-tenant-revision']).toBe(version)
      const body = request.postDataJSON()
      expect(body).toEqual({ activity_version: version, response_deadline: '2026-10-18T07:00:00+00:00', reminder_offsets: [60, 120], confirm_invitations: true })
      issues++; status = 201
      rows = [{ id: invitationId, activity_id: id, activity_version: version, title, starts_at: activity.starts_at, origin_timezone: activity.origin_timezone, person_id: person,
        person_label: '<img>Joueur</img>', response: 'pending', response_deadline: body.response_deadline, reminder_offsets: body.reminder_offsets, responded_at: null,
        sent_at: '2026-10-17T10:00:00+00:00', current: true, expired: false, blocked_reason: null, delivery_count: 2, can_respond: true, version }]
    }
    if (path === root + '/invitations/' + invitationId && method === 'PATCH') {
      expect(confirmed).toBe(true); expect(request.headers()['x-tenant-revision']).toBe(version)
      expect(request.postDataJSON()).toEqual({ version, response: 'present' })
      replies++; rows = rows.map((row) => ({ ...row, response: 'present', version: person }))
      data = rows[0]
    }
    if (path === root + '/activities/' + id + '/invitations' || path === root + '/invitations') data = rows
    if (path === root + '/invitations/' + invitationId + '/deliveries') data = [
      { id, kind: 'invitation', offset_minutes: 0, created_at: '2026-10-17T10:00:00+00:00' },
      { id: person, kind: 'reminder', offset_minutes: 60, created_at: '2026-10-18T06:00:00+00:00' },
    ]
    await route.fulfill({ status, headers, contentType: 'application/json', body: JSON.stringify({ data,
      meta: { current_page: 1, last_page: 1, total: Array.isArray(data) ? data.length : 0 }, calendar_invitations: { delivery_enabled: true },
      calendar: { viewer_timezone: 'Europe/Paris', display_timezone: 'Europe/Paris' } }) })
  })
  await page.setViewportSize({ width: 375, height: 844 }); await page.goto('/')
  const calendar = page.getByRole('region', { name: 'Calendrier des activités' })
  await calendar.getByRole('button', { name: 'Ouvrir le calendrier' }).click()
  const management = calendar.getByRole('region', { name: 'Convocations de l’activité' })
  await management.getByRole('button', { name: 'Consulter les convocations' }).click()
  const issue = management.getByRole('form', { name: 'Préparer les convocations' })
  await issue.getByLabel('Échéance de réponse en UTC').fill('2026-10-18T07:00')
  await issue.getByLabel('Rappel facultatif en minutes 2').fill('120')
  await issue.getByLabel('Je confirme les convocations des participants inscrits').check()
  await expect.poll(async () => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
  await management.screenshot({ path: 'reports/cal004-issue-mobile.png', style: 'header, [aria-label="Notifications"] { visibility: hidden !important; }' })
  await issue.getByRole('button', { name: 'Préparer les convocations' }).click()
  expect(issues).toBe(0)
  await management.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await expect(management.getByText('<img>Joueur</img>', { exact: true })).toBeVisible()
  expect(issues).toBe(1)
  const inbox = calendar.getByRole('region', { name: 'Mes convocations', exact: true })
  await inbox.getByRole('button', { name: 'Consulter les convocations' }).click()
  const answer = inbox.getByRole('form', { name: 'Enregistrer ma réponse' })
  await answer.getByLabel('Réponse', { exact: true }).selectOption('present')
  await answer.getByRole('button', { name: 'Enregistrer ma réponse' }).click()
  await inbox.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await expect(inbox.getByText('Réponse : Présent')).toBeVisible()
  expect(replies).toBe(1)
  await inbox.getByRole('button', { name: 'Historique des remises' }).click()
  await expect(inbox.getByText(/60 minutes avant l’échéance/)).toBeVisible()
  await page.setViewportSize({ width: 320, height: 844 })
  await expect.poll(async () => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
  expect(await calendar.locator('script,img,iframe').count()).toBe(0)
  await inbox.screenshot({ path: 'reports/cal004-inbox-mobile.png', style: 'header, [aria-label="Notifications"] { visibility: hidden !important; }' })
  expect(errors).toEqual([])
})
