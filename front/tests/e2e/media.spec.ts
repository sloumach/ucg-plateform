import { expect, test } from '@playwright/test'

test('médias : scrim privé, autorisation différée, référence échappée et archive sur mobile', async ({ page }) => {
  const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222', version = '33333333-3333-4333-8333-333333333333'
  const root = 'tenants/' + org + '/media', errors: string[] = [], externalRequests: string[] = []
  const title = '<script>MEDIA001</script> ' + 'VidéoÉquipeLongue'.repeat(6)
  let record: Record<string, unknown> | null = null, confirmed = false
  const headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }
  page.on('pageerror', (error) => errors.push(error.message))
  page.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()) })
  page.on('request', (request) => { if (/youtube|twitch/.test(new URL(request.url()).hostname)) externalRequests.push(request.url()) })
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request(), path = new URL(request.url()).pathname.replace('/api/v1/', ''), method = request.method()
    if (method === 'OPTIONS') { await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET, POST, PATCH', 'Access-Control-Allow-Headers': 'Content-Type, X-Tenant-Revision, X-XSRF-TOKEN' } }); return }
    let data: unknown = []
    if (path === 'auth/me') data = { id: 42, name: 'Membre média', email: 'media@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (path === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (path === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: id }]
    if (path === 'active-organization/confirmation') confirmed = true
    if (path === 'active-organization' || path === 'active-organization/confirmation') data = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'Europe/Paris', language: 'fr', roles: ['member'], permissions: ['organization.view', 'media.read', 'media.write', 'media.publish'], is_owner: false, actor_user_id: 42, membership_id: id }, revision: version, confirmed }
    if (path === root + '/teams') data = [{ id, label: 'Équipe ' + 'Internationale'.repeat(5) }]
    if (path === root && method === 'POST') {
      expect(confirmed).toBe(true)
      const body = request.postDataJSON()
      expect(body.is_scrim).toBe(true); expect(body.visibility).toBe('private'); expect(body).not.toHaveProperty('score')
      record = { id, team_label: 'Équipe pilote', ...body, status_source: 'manual', publish_at: null, effective_visibility: 'private', archived_at: null, version }
      data = record
    } else if (path === root && method === 'GET') {
      const archived = new URL(request.url()).searchParams.get('archived') === '1'
      data = record && Boolean(record.archived_at) === archived ? [record] : []
    }
    if (path === root + '/' + id && method === 'PATCH') {
      const body = request.postDataJSON()
      expect(body.version).toBe(version)
      if (body.archive) record = { ...record, archived_at: '2026-10-16T12:00:00+00:00', effective_visibility: 'private' }
      else {
        expect(body.confirm_sharing).toBe(true); expect(body.visibility).toBe('organization')
        expect(body.publish_at).toMatch(/^2099-/)
        record = { ...record, ...body, effective_visibility: 'private' }
      }
      data = record
    }
    await route.fulfill({ status: method === 'POST' && path === root ? 201 : 200, headers, contentType: 'application/json', body: JSON.stringify({ data, meta: { current_page: 1, last_page: 1, total: Array.isArray(data) ? data.length : 0 } }) })
  })
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/')
  const panel = page.getByRole('region', { name: 'Streams, VOD et clips' })
  await panel.getByRole('button', { name: 'Ouvrir les médias' }).click()
  await panel.getByRole('button', { name: 'Nouveau média' }).click()
  let form = panel.getByRole('form', { name: 'Enregistrer le média' })
  await form.getByLabel('Titre du média').fill(title)
  await form.getByLabel('Équipe associée').selectOption(id)
  await form.getByLabel('Lien HTTPS du média').fill('https://www.youtube.com/watch?v=abcdefghijk')
  await form.getByLabel('Contexte du média').selectOption('true')
  await form.getByRole('button', { name: 'Enregistrer le média' }).click()
  await panel.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await expect(panel.getByRole('heading', { name: title })).toBeVisible()
  await expect(panel.locator('iframe, script, img')).toHaveCount(0)
  await panel.getByRole('button', { name: 'Modifier le média' }).click()
  form = panel.getByRole('form', { name: 'Enregistrer le média' })
  await form.getByLabel('Visibilité autorisée').selectOption('organization')
  await form.getByLabel('Date de partage différé (heure locale)').fill('2099-01-01T12:00')
  await form.getByLabel('Je confirme que le partage de ce média est autorisé').check()
  await form.getByRole('button', { name: 'Enregistrer le média' }).click()
  await panel.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await expect(panel.getByText(/Partage autorisé à partir du/)).toBeVisible()
  await expect(panel.getByText(/Privé — gestionnaires des médias/)).toBeVisible()
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
  await panel.screenshot({ path: 'reports/media001-mobile.png', style: 'header, [aria-label="Notifications"] { visibility: hidden !important; }' })
  await panel.getByRole('button', { name: 'Archiver le média', exact: true }).click()
  await panel.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await expect(panel.getByText('Aucun média accessible dans cette sélection.')).toBeVisible()
  await panel.getByLabel('Archives', { exact: true }).selectOption('1')
  await panel.getByRole('button', { name: 'Rechercher', exact: true }).click()
  await expect(panel.getByRole('heading', { name: title })).toBeVisible()
  await expect(panel.getByRole('button', { name: 'Modifier le média' })).toHaveCount(0)
  expect(errors).toEqual([]); expect(externalRequests).toEqual([])
})
