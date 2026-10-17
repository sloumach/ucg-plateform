import { expect, test } from '@playwright/test'
test('calendrier : fuseaux, heure répétée, participants et annulation sur mobile', async ({ page }) => {
  const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222', version = '33333333-3333-4333-8333-333333333333'
  const person = '44444444-4444-4444-8444-444444444444', team = '55555555-5555-4555-8555-555555555555'
  const root = 'tenants/' + org + '/calendar', title = '<script>CAL001</script> ' + 'ActivitéÉquipeLongue'.repeat(4)
  const errors: string[] = [], external: string[] = []
  let record: Record<string, unknown> | null = null, confirmed = false
  const headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }
  page.on('pageerror', (error) => errors.push(error.message))
  page.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()) })
  page.on('request', (request) => { if (new URL(request.url()).hostname === 'meeting.example.test') external.push(request.url()) })
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request(), url = new URL(request.url()), path = url.pathname.replace('/api/v1/', ''), method = request.method()
    if (method === 'OPTIONS') { await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET, POST, PATCH', 'Access-Control-Allow-Headers': 'Content-Type, X-Tenant-Revision, X-XSRF-TOKEN' } }); return }
    let data: unknown = []
    if (path === 'auth/me') data = { id: 42, name: 'Membre calendrier', email: 'calendar@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (path === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (path === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: id }]
    if (path === 'active-organization/confirmation') confirmed = true
    if (path === 'active-organization' || path === 'active-organization/confirmation') data = {
      context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'], permissions: ['organization.view', 'calendar.read', 'calendar.write', 'calendar.publish'], is_owner: false, actor_user_id: 42, membership_id: id }, revision: version, confirmed }
    if (path === root + '/activity-conflicts') data = { conflicts: [], truncated: false, availability_checked: false, can_override: false, review_token: 'review-token', expires_in: 300 }
    if (path === root + '/teams') data = [{ id: team, label: '<script>Équipe</script>' }]
    if (path === root + '/people') data = [{ id: person, label: '<img>Joueur</img>' }]
    if (path.startsWith(root) && method !== 'GET' && path !== root + '/activity-conflicts') { expect(confirmed).toBe(true); expect(request.headers()['x-tenant-revision']).toBe(version) }
    if (path === root + '/activities' && method === 'POST') {
      const body = request.postDataJSON()
      expect(body.visibility).toBe('private'); expect(body.kind).toBe('scrim')
      expect(body.starts_at).toBe('2026-10-25T02:30:00+01:00'); expect(body.ends_at).toBe('2026-10-25T03:30:00+01:00')
      expect(body.origin_timezone).toBe('Europe/Paris'); expect(body.participant_ids).toEqual([person]); expect(body.team_id).toBe(team)
      expect(body).not.toHaveProperty('match_id')
      record = { ...body, id, version, participants: [{ id: person, label: '<img>Joueur</img>' }], team_label: '<script>Équipe</script>', archived_at: null,
        starts_at: '2026-10-25T01:30:00+00:00', ends_at: '2026-10-25T02:30:00+00:00', starts_at_origin: body.starts_at, ends_at_origin: body.ends_at }
      data = record
    }
    if (path === root + '/activities/' + id && method === 'PATCH') {
      const body = request.postDataJSON(); expect(body.version).toBe(version)
      if (body.archive) record = { ...record, archived_at: '2026-10-26T12:00:00+00:00', cancelled_at: '2026-10-26T12:00:00+00:00' }
      else { expect(Object.keys(body).sort()).toEqual(['conflict_review', 'title', 'version']); record = { ...record, title: body.title } }
      data = record
    }
    if (path === root + '/activities' && method === 'GET') {
      expect(url.searchParams.get('from_date')).toBeTruthy(); expect(url.searchParams.get('to_date')).toBeTruthy()
      const archived = url.searchParams.get('management') === '1' && url.searchParams.get('archived') === '1'
      data = record && Boolean(record.archived_at) === archived ? [record] : []
    }
    await route.fulfill({ status: method === 'POST' && path === root + '/activities' ? 201 : 200, headers, contentType: 'application/json',
      body: JSON.stringify({ data, meta: { current_page: 1, last_page: 1, total: Array.isArray(data) ? data.length : 0 },
        calendar: { viewer_timezone: 'Europe/Paris', display_timezone: url.searchParams.get('timezone') || 'Europe/Paris', from_utc: '2026-10-24T22:00:00+00:00', to_utc: '2026-10-25T23:00:00+00:00' } }) })
  })
  await page.setViewportSize({ width: 390, height: 844 }); await page.goto('/')
  const panel = page.getByRole('region', { name: 'Calendrier des activités' })
  await panel.getByRole('button', { name: 'Ouvrir le calendrier' }).click()
  await expect(panel.getByText('Aucune activité accessible dans cette période.')).toBeVisible()
  const filters = panel.getByRole('form', { name: 'Filtres du calendrier' })
  await filters.getByLabel('Du (inclus)').fill('2026-10-25'); await filters.getByLabel('Au (exclu)').fill('2026-10-26')
  await filters.getByLabel('Fuseau d’affichage (facultatif)').fill('Asia/Tokyo')
  await filters.getByRole('button', { name: 'Afficher la période' }).click()
  await expect(panel.getByText('Horaires affichés dans le fuseau : Asia/Tokyo')).toBeVisible()
  await panel.getByRole('button', { name: 'Nouvelle activité' }).click()
  let form = panel.getByRole('form', { name: 'Enregistrer l’activité' })
  await form.getByLabel('Titre de l’activité').fill(title)
  await form.getByLabel('Type d’activité').selectOption('scrim')
  await form.getByLabel('Description', { exact: true }).fill('<img src=x onerror=alert(1)> Description privée')
  await form.getByLabel('Début dans le fuseau d’origine').fill('2026-10-25T02:30')
  await form.getByLabel('Fin dans le fuseau d’origine').fill('2026-10-25T03:30')
  await expect(form.getByRole('button', { name: 'Vérifier les conflits' })).toBeDisabled()
  await form.getByLabel('Occurrence du début').selectOption('2026-10-25T02:30:00+01:00')
  await form.getByLabel('Équipe associée').selectOption(team)
  await form.getByLabel('<img>Joueur</img>', { exact: true }).check()
  await form.getByLabel('Lien HTTPS de l’activité').fill('https://meeting.example.test/room?token=example')
  await form.getByRole('button', { name: 'Vérifier les conflits' }).click()
  await expect(form.getByText('Aucun conflit détecté dans le périmètre contrôlé.')).toBeVisible()
  await form.getByRole('button', { name: 'Enregistrer l’activité' }).click()
  await panel.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await expect(panel.getByRole('heading', { name: title, exact: true })).toBeVisible()
  await expect(panel.getByText(/Fuseau d’origine : Europe\/Paris/)).toBeVisible()
  await expect(panel.getByText(/25 oct\. 2026, 10:30/)).toBeVisible()
  await expect(panel.getByRole('link', { name: 'Ouvrir le lien de l’activité' })).toHaveAttribute('referrerpolicy', 'no-referrer')
  await expect(panel.locator('script,iframe,img')).toHaveCount(0)
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
  await panel.screenshot({ path: 'reports/cal001-mobile.png', style: 'header, [aria-label="Notifications"] { visibility: hidden !important; }' })
  await panel.getByRole('button', { name: 'Modifier l’activité' }).click()
  form = panel.getByRole('form', { name: 'Enregistrer l’activité' })
  await form.getByLabel('Titre de l’activité').fill('Titre corrigé')
  await form.getByRole('button', { name: 'Vérifier les conflits' }).click()
  await expect(form.getByText('Aucun conflit détecté dans le périmètre contrôlé.')).toBeVisible()
  await form.getByRole('button', { name: 'Enregistrer l’activité' }).click()
  await panel.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await expect(panel.getByRole('heading', { name: 'Titre corrigé' })).toBeVisible()
  await panel.getByRole('button', { name: 'Annuler l’activité', exact: true }).click()
  const cancellation = panel.getByRole('form', { name: 'Annulation de l’activité' })
  await cancellation.getByLabel('Motif opérationnel du changement').fill('Annulation opérationnelle du créneau.')
  await cancellation.getByRole('button', { name: 'Annuler l’activité', exact: true }).click()
  await panel.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await expect(panel.getByText('Aucune activité accessible dans cette période.')).toBeVisible()
  await filters.getByLabel('Périmètre de consultation').selectOption('1'); await filters.getByLabel('Afficher les archives en mode gestion').check()
  await filters.getByRole('button', { name: 'Afficher la période' }).click()
  await expect(panel.getByText('Activité annulée', { exact: true })).toBeVisible()
  await expect(panel.getByRole('button', { name: 'Modifier l’activité' })).toHaveCount(0)
  expect(errors).toEqual([]); expect(external).toEqual([])
})
