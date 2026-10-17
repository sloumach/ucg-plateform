import { expect, test } from '@playwright/test'

test('disponibilités personnelles : heure répétée, historique, annulation et mobile', async ({ page }) => {
  const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222', version = '33333333-3333-4333-8333-333333333333'
  const person = '44444444-4444-4444-8444-444444444444', root = 'tenants/' + org + '/calendar'
  const errors: string[] = [], label = '<script>CAL002</script> ' + 'PersonneLongue'.repeat(5)
  let record: Record<string, unknown> | null = null, confirmed = false
  const history: Record<string, unknown>[] = []
  const headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }
  page.on('pageerror', (error) => errors.push(error.message))
  page.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()) })
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request(), url = new URL(request.url()), path = url.pathname.replace('/api/v1/', ''), method = request.method()
    if (method === 'OPTIONS') { await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET, POST, PATCH', 'Access-Control-Allow-Headers': 'Content-Type, X-Tenant-Revision, X-XSRF-TOKEN' } }); return }
    let data: unknown = []
    if (path === 'auth/me') data = { id: 42, name: 'Membre planning', email: 'planning@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (path === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (path === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: id }]
    if (path === 'active-organization/confirmation') confirmed = true
    if (path === 'active-organization' || path === 'active-organization/confirmation') data = {
      context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'], permissions: ['organization.view', 'availability.self'], is_owner: false, actor_user_id: 42, membership_id: id }, revision: version, confirmed }
    if (path.startsWith(root) && method !== 'GET') { expect(confirmed).toBe(true); expect(request.headers()['x-tenant-revision']).toBe(version) }
    if (path === root + '/availabilities' && method === 'POST') {
      const body = request.postDataJSON()
      expect(body.status).toBe('unavailable'); expect(body).not.toHaveProperty('person_id'); expect(body).not.toHaveProperty('medical_reason')
      expect(body.starts_at).toBe('2026-10-25T02:30:00+01:00'); expect(body.ends_at).toBe('2026-10-25T03:30:00+01:00')
      record = { ...body, id, version, person_id: person, person_label: label, number: 1, cancelled_at: null, can_edit: true, can_cancel: true, can_history: true,
        starts_at: '2026-10-25T01:30:00+00:00', ends_at: '2026-10-25T02:30:00+00:00', starts_at_origin: body.starts_at, ends_at_origin: body.ends_at }
      history.unshift({ ...record, id: '77777777-7777-4777-8777-777777777771', action: 'created', created_at: '2026-10-17T10:00:00+00:00' }); data = record
    }
    if (path === root + '/availabilities/' + id && method === 'PATCH') {
      const body = request.postDataJSON(); expect(body.version).toBe(record!.version)
      if (body.cancel) {
        expect(Object.keys(body).sort()).toEqual(['cancel', 'version'])
        record = { ...record, cancelled_at: '2026-10-17T12:00:00+00:00', can_edit: false, can_cancel: false, number: 3, version: '66666666-6666-4666-8666-666666666666' }
      } else {
        expect(Object.keys(body).sort()).toEqual(['status', 'version'])
        record = { ...record, status: body.status, number: 2, version: '55555555-5555-4555-8555-555555555555' }
      }
      history.unshift({ ...record, id: body.cancel ? '77777777-7777-4777-8777-777777777773' : '77777777-7777-4777-8777-777777777772', action: body.cancel ? 'cancelled' : 'updated', created_at: '2026-10-17T11:00:00+00:00' }); data = record
    }
    if (path === root + '/availabilities/' + id + '/history') data = history
    if (path === root + '/availabilities' && method === 'GET') data = record && Boolean(record.cancelled_at) === (url.searchParams.get('cancelled') === '1') ? [record] : []
    await route.fulfill({ status: method === 'POST' && path === root + '/availabilities' ? 201 : 200, headers, contentType: 'application/json',
      body: JSON.stringify({ data, meta: { current_page: 1, last_page: 1, total: Array.isArray(data) ? data.length : 0 },
        availability: { viewer_person_id: person, viewer_timezone: 'Europe/Paris', display_timezone: url.searchParams.get('timezone') || 'Europe/Paris',
          from_utc: '2026-10-24T22:00:00+00:00', to_utc: '2026-10-25T23:00:00+00:00', can_self: true, can_manage: false } }) })
  })
  await page.setViewportSize({ width: 390, height: 844 }); await page.goto('/')
  const panel = page.getByRole('region', { name: 'Disponibilités et absences', exact: true })
  await panel.getByRole('button', { name: 'Ouvrir les disponibilités' }).click()
  await expect(panel.getByText(/Aucun créneau dans cette période/)).toBeVisible()
  const filters = panel.getByRole('form', { name: 'Filtres des disponibilités' })
  await filters.getByLabel('Du (inclus)').fill('2026-10-25'); await filters.getByLabel('Au (exclu)').fill('2026-10-26')
  await filters.getByLabel('Fuseau d’affichage (facultatif)').fill('Asia/Tokyo'); await filters.getByRole('button', { name: 'Afficher la période' }).click()
  await expect(panel.getByText('Horaires affichés dans le fuseau : Asia/Tokyo')).toBeVisible()
  await panel.getByRole('button', { name: 'Nouveau créneau', exact: true }).click()
  let form = panel.getByRole('form', { name: 'Enregistrer le créneau' })
  await form.getByLabel('Disponibilité', { exact: true }).selectOption('unavailable')
  await form.getByLabel('Début dans le fuseau d’origine').fill('2026-10-25T02:30')
  await form.getByLabel('Fin dans le fuseau d’origine').fill('2026-10-25T03:30')
  await form.getByLabel('Commentaire facultatif').fill('<img src=x onerror=alert(1)> Commentaire personnel')
  await expect(form.getByRole('button', { name: 'Enregistrer le créneau' })).toBeDisabled()
  await form.getByLabel('Occurrence du début').selectOption('2026-10-25T02:30:00+01:00')
  await form.getByRole('button', { name: 'Enregistrer le créneau' }).click(); await panel.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await expect(panel.getByRole('heading', { name: label + ' · Indisponible', exact: true })).toBeVisible()
  await expect(panel.getByText(/25 oct\. 2026, 10:30/)).toBeVisible()
  await expect(panel.locator('script,img,iframe')).toHaveCount(0)
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
  await panel.getByRole('button', { name: 'Modifier le créneau' }).click()
  form = panel.getByRole('form', { name: 'Enregistrer le créneau' })
  await form.getByLabel('Disponibilité', { exact: true }).selectOption('uncertain')
  await form.getByRole('button', { name: 'Enregistrer le créneau' }).click(); await panel.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await expect(panel.getByRole('heading', { name: label + ' · Incertain', exact: true })).toBeVisible()
  await panel.getByRole('button', { name: 'Voir l’historique' }).click()
  const historyPanel = panel.getByRole('region', { name: 'Historique du créneau' })
  await expect(historyPanel.getByText(/Version 2 · Modification/)).toBeVisible(); await expect(historyPanel.getByText(/Version 1 · Création/)).toBeVisible()
  await panel.screenshot({ path: 'reports/cal002-mobile.png', style: 'header, [aria-label="Notifications"] { visibility: hidden !important; }' })
  await panel.getByRole('button', { name: 'Annuler le créneau', exact: true }).click(); await panel.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await expect(panel.getByText(/Aucun créneau dans cette période/)).toBeVisible()
  await filters.getByLabel('Afficher les créneaux annulés').check(); await filters.getByRole('button', { name: 'Afficher la période' }).click()
  await expect(panel.getByText('Créneau annulé', { exact: true })).toBeVisible()
  await expect(panel.getByRole('button', { name: 'Modifier le créneau' })).toHaveCount(0)
  expect(errors).toEqual([])
})
