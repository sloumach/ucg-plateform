import { expect, test } from '@playwright/test'

test('réservation : ressource, conflit privé, exception motivée et historique sur mobile', async ({ page }) => {
  const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222', version = '33333333-3333-4333-8333-333333333333'
  const resourceId = '44444444-4444-4444-8444-444444444444', otherId = '55555555-5555-4555-8555-555555555555'
  const root = 'tenants/' + org + '/calendar', label = '<script>Salle partagée</script> RessourceLongue', reason = '<img src=x> Accord opérationnel explicite'
  const headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }
  const errors: string[] = []; let confirmed = false, resource: Record<string, unknown> | null = null, activity: Record<string, unknown> | null = null, reviewed = false
  page.on('pageerror', (error) => errors.push(error.message)); page.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()) })
  const conflict = { type: 'activity', id: otherId, version, title: '<script>Autre activité privée</script>', starts_at: '2026-10-18T08:00:00+00:00', ends_at: '2026-10-18T09:00:00+00:00', causes: ['resource'], person_ids: [], resource_ids: [resourceId] }
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request(), url = new URL(request.url()), path = url.pathname.replace('/api/v1/', ''), method = request.method()
    if (method === 'OPTIONS') { await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET, POST, PATCH', 'Access-Control-Allow-Headers': 'Content-Type, X-Tenant-Revision, X-XSRF-TOKEN' } }); return }
    let data: unknown = []
    if (path === 'auth/me') data = { id: 42, name: 'Gestionnaire planning', email: 'planning@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (path === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (path === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: id }]
    if (path === 'active-organization/confirmation') confirmed = true
    if (path === 'active-organization' || path === 'active-organization/confirmation') data = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'],
      permissions: ['organization.view', 'calendar.read', 'calendar.write', 'calendar.resources.manage', 'calendar.conflicts.override', 'availability.read'], is_owner: false, actor_user_id: 42, membership_id: id }, revision: version, confirmed }
    if (path.startsWith(root) && method !== 'GET') expect(request.headers()['x-tenant-revision']).toBe(version)
    if (path === root + '/resources' && method === 'POST') {
      expect(confirmed).toBe(true); const body = request.postDataJSON(); expect(body).toEqual({ key: 'salle-a', label, kind: 'room' })
      resource = { ...body, id: resourceId, status: 'active', version }; data = resource
    }
    if (path === root + '/resources' && method === 'GET') data = resource ? [resource] : []
    if (path === root + '/activity-conflicts') {
      expect(request.postDataJSON().resource_ids).toEqual([resourceId]); reviewed = true
      data = { conflicts: [conflict], truncated: false, availability_checked: true, can_override: true, review_token: 'reviewed-reservation', expires_in: 300 }
    }
    if (path === root + '/activities' && method === 'POST') {
      expect(confirmed && reviewed).toBe(true); const body = request.postDataJSON()
      expect(body.conflict_review).toBe('reviewed-reservation'); expect(body.conflict_reason).toBe(reason); expect(body.confirm_conflicts).toBe(true); expect(body.resource_ids).toEqual([resourceId])
      activity = { ...body, id, version, starts_at: '2026-10-18T08:00:00+00:00', ends_at: '2026-10-18T09:00:00+00:00', starts_at_origin: body.starts_at, ends_at_origin: body.ends_at,
        participants: [], resources: [{ id: resourceId, label }], team_label: null, archived_at: null }; data = activity
    }
    if (path === root + '/activities' && method === 'GET') data = activity ? [activity] : []
    if (path === root + '/activities/' + id + '/conflict-overrides') data = [{ id: otherId, activity_version: version, reason, conflict_count: 1, conflicts: [conflict], availability_checked: true, created_at: '2026-10-17T10:00:00+00:00' }]
    await route.fulfill({ status: method === 'POST' && [root + '/resources', root + '/activities'].includes(path) ? 201 : 200, headers, contentType: 'application/json',
      body: JSON.stringify({ data, meta: { current_page: 1, last_page: 1, total: Array.isArray(data) ? data.length : 0 },
        calendar: { viewer_timezone: 'Europe/Paris', display_timezone: 'Europe/Paris', from_utc: '2026-10-17T22:00:00+00:00', to_utc: '2026-10-18T22:00:00+00:00' } }) })
  })
  await page.setViewportSize({ width: 390, height: 844 }); await page.goto('/')
  const panel = page.getByRole('region', { name: 'Calendrier des activités', exact: true })
  await panel.getByRole('button', { name: 'Ouvrir le calendrier' }).click()
  await panel.getByRole('button', { name: 'Ouvrir les ressources réservables' }).click()
  await panel.getByRole('button', { name: 'Nouvelle ressource' }).click()
  const resourceForm = panel.getByRole('form', { name: 'Enregistrer la ressource' })
  await resourceForm.getByLabel('Référence de la ressource').fill('salle-a'); await resourceForm.getByLabel('Nom de la ressource').fill(label)
  await resourceForm.getByRole('button', { name: 'Enregistrer la ressource' }).click(); await panel.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await expect(panel.getByText(label + ' · Salle · salle-a')).toBeVisible()
  await panel.getByRole('button', { name: 'Nouvelle activité' }).click()
  const form = panel.getByRole('form', { name: 'Enregistrer l’activité' })
  await form.getByLabel('Titre de l’activité').fill('Réservation avec exception')
  await form.getByLabel('Début dans le fuseau d’origine').fill('2026-10-18T10:00'); await form.getByLabel('Fin dans le fuseau d’origine').fill('2026-10-18T11:00')
  await form.getByLabel(label, { exact: true }).check()
  await form.getByRole('button', { name: 'Vérifier les conflits' }).click()
  await expect(form.getByText('<script>Autre activité privée</script>')).toBeVisible()
  await expect(form.getByRole('button', { name: 'Enregistrer l’activité' })).toBeDisabled()
  await form.getByLabel('Motif opérationnel de l’exception').fill(reason)
  await form.getByLabel('Je confirme avoir examiné ces conflits et autorise cette exception').check()
  await expect(form.getByRole('button', { name: 'Enregistrer l’activité' })).toBeEnabled()
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
  await form.screenshot({ path: 'reports/cal003-review-mobile.png', style: 'header, [aria-label="Notifications"] { visibility: hidden !important; }' })
  await form.getByRole('button', { name: 'Enregistrer l’activité' }).click(); await panel.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await expect(panel.getByRole('heading', { name: 'Réservation avec exception' })).toBeVisible()
  await panel.getByRole('button', { name: 'Voir les exceptions de réservation' }).click()
  const history = panel.getByRole('region', { name: 'Exceptions de réservation', exact: true })
  await expect(history.getByText(reason, { exact: true })).toBeVisible(); await expect(history.locator('script,img,iframe')).toHaveCount(0)
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
  await history.screenshot({ path: 'reports/cal003-history-mobile.png' }); expect(errors).toEqual([])
})
