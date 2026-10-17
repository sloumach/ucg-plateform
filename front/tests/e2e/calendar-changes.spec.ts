import { expect, test } from '@playwright/test'

test('report motivé, historique, messages internes et annulation définitive sur mobile', async ({ page }) => {
  const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222'
  const revision = '33333333-3333-4333-8333-333333333333', nextVersion = '44444444-4444-4444-8444-444444444444'
  const root = 'tenants/' + org + '/calendar', reason = '<script>Adaptation du créneau de préparation</script>'
  const errors: string[] = [], headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }
  let confirmed = false, changed = false, cancelled = false, inboxReads = 0
  let activity = { id, version: revision, title: '<img>Préparation</img>', description: null, kind: 'training',
    starts_at: '2026-10-18T08:00:00+00:00', ends_at: '2026-10-18T09:00:00+00:00',
    starts_at_origin: '2026-10-18T08:00:00+00:00', ends_at_origin: '2026-10-18T09:00:00+00:00', origin_timezone: 'UTC',
    team_id: null, team_label: null, location: null, url: null, visibility: 'private', participants: [], resources: [], archived_at: null as string | null, cancelled_at: null as string | null }
  const before = { starts_at: activity.starts_at, ends_at: activity.ends_at, origin_timezone: 'UTC' }
  const after = { starts_at: '2026-10-18T10:00:00+00:00', ends_at: '2026-10-18T11:00:00+00:00', origin_timezone: 'UTC' }
  const report = { id, activity_id: id, activity_version: nextVersion, title: activity.title, kind: 'rescheduled', reason, before, after, occurred_at: '2026-10-17T10:00:00Z' }
  const cancellation = { ...report, id: nextVersion, activity_version: org, kind: 'cancelled', reason: 'Annulation opérationnelle du créneau.', before: after, occurred_at: '2026-10-17T11:00:00Z' }
  page.on('pageerror', (error) => errors.push(error.message))
  page.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()) })
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request(), url = new URL(request.url()), path = url.pathname.replace('/api/v1/', ''), method = request.method()
    if (method === 'OPTIONS') { await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET, POST, PATCH', 'Access-Control-Allow-Headers': 'Content-Type, X-Tenant-Revision, X-XSRF-TOKEN' } }); return }
    let data: unknown = []
    if (path === 'auth/me') data = { id: 42, name: 'Gestionnaire calendrier', email: 'calendar@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (path === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (path === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: id }]
    if (path === 'active-organization/confirmation') confirmed = true
    if (path === 'active-organization' || path === 'active-organization/confirmation') data = { context: {
      organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'], permissions: ['organization.view', 'calendar.read', 'calendar.write'], is_owner: false, actor_user_id: 42, membership_id: id }, revision, confirmed }
    if (path === root + '/activity-conflicts') {
      expect(request.postDataJSON()).toMatchObject({ starts_at: after.starts_at, ends_at: after.ends_at, change_reason: reason, version: revision })
      data = { conflicts: [], truncated: false, availability_checked: false, can_override: false, review_token: 'report-review', expires_in: 300 }
    }
    if (path === root + '/activities/' + id && method === 'PATCH') {
      expect(confirmed).toBe(true); expect(request.headers()['x-tenant-revision']).toBe(revision)
      const body = request.postDataJSON()
      if (body.archive) {
        expect(body).toEqual({ archive: true, version: nextVersion, change_reason: cancellation.reason })
        cancelled = true; activity = { ...activity, version: org, archived_at: cancellation.occurred_at, cancelled_at: cancellation.occurred_at }
      } else {
        expect(body).toMatchObject({ ...after, change_reason: reason, version: revision, conflict_review: 'report-review' })
        changed = true; activity = { ...activity, ...after, starts_at_origin: after.starts_at, ends_at_origin: after.ends_at, version: nextVersion }
      }
      data = activity
    }
    if (path === root + '/activities' && method === 'GET') {
      const archived = url.searchParams.get('management') === '1' && url.searchParams.get('archived') === '1'
      data = cancelled === archived ? [activity] : []
    }
    if (path === root + '/activities/' + id + '/changes') data = (cancelled ? [cancellation, report] : changed ? [report] : []).map((change) => ({ ...change, recipients_count: 1, delivered_count: 1, blocked_count: 0 }))
    if (path === root + '/changes') { inboxReads++; data = (cancelled ? [cancellation, report] : changed ? [report] : []).map((change) => ({ id: change.id, change, delivered_at: change.occurred_at, current: change.activity_version === activity.version })) }
    await route.fulfill({ status: 200, headers, contentType: 'application/json', body: JSON.stringify({ data,
      meta: { current_page: 1, last_page: 1, total: Array.isArray(data) ? data.length : 0 }, calendar_changes: { delivery_enabled: true },
      calendar: { viewer_timezone: 'UTC', display_timezone: 'UTC' } }) })
  })
  await page.setViewportSize({ width: 375, height: 812 }); await page.goto('/')
  const panel = page.getByRole('region', { name: 'Calendrier des activités' })
  await panel.getByRole('button', { name: 'Ouvrir le calendrier' }).click()
  await expect(panel.getByRole('heading', { name: activity.title, exact: true })).toBeVisible(); expect(inboxReads).toBe(0)
  await panel.getByRole('button', { name: 'Modifier l’activité' }).click()
  const form = panel.getByRole('form', { name: 'Enregistrer l’activité' })
  await form.getByLabel('Début dans le fuseau d’origine').fill('2026-10-18T10:00')
  await form.getByLabel('Fin dans le fuseau d’origine').fill('2026-10-18T11:00')
  await form.getByLabel('Motif opérationnel du changement').fill(reason)
  await form.getByRole('button', { name: 'Vérifier les conflits' }).click()
  await expect(form.getByText('Aucun conflit détecté dans le périmètre contrôlé.')).toBeVisible()
  await form.getByRole('button', { name: 'Enregistrer l’activité' }).click()
  await panel.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await expect(form).toHaveCount(0)
  await panel.getByRole('button', { name: 'Historique des reports et annulations', exact: true }).click()
  const history = panel.getByRole('region', { name: 'Historique des reports et annulations' })
  await expect(history.getByText('Motif : ' + reason, { exact: true })).toBeVisible()
  await expect(history.getByText('Personnes concernées : 1 · Notifications remises : 1 · Remises empêchées par les accès actuels : 0')).toBeVisible()
  await history.getByRole('button', { name: 'Fermer les changements de planning' }).click()
  await panel.getByRole('button', { name: 'Annuler l’activité', exact: true }).click()
  const cancellationForm = panel.getByRole('form', { name: 'Annulation de l’activité' })
  await cancellationForm.getByLabel('Motif opérationnel du changement').fill(cancellation.reason)
  await cancellationForm.getByRole('button', { name: 'Annuler l’activité', exact: true }).click()
  await panel.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await expect(panel.getByText('Aucune activité accessible dans cette période.')).toBeVisible()
  const inbox = panel.getByRole('region', { name: 'Mes notifications de planning' })
  await inbox.getByRole('button', { name: 'Consulter les changements de planning' }).click()
  await expect(inbox.getByRole('heading', { name: 'Activité annulée · ' + activity.title })).toBeVisible()
  await expect(inbox.getByText('Ce message décrit un changement passé. Consultez le planning actuel.')).toBeVisible()
  await expect(inbox.getByText(/Personnes concernées/)).toHaveCount(0)
  const filters = panel.getByRole('form', { name: 'Filtres du calendrier' })
  await filters.getByLabel('Périmètre de consultation').selectOption('1'); await filters.getByLabel('Afficher les archives en mode gestion').check()
  await filters.getByRole('button', { name: 'Afficher la période' }).click()
  await expect(panel.getByText('Activité annulée', { exact: true })).toBeVisible()
  await expect(panel.getByRole('button', { name: 'Modifier l’activité' })).toHaveCount(0)
  await expect(panel.locator('img,script,iframe')).toHaveCount(0)
  for (const width of [375, 320]) {
    await page.setViewportSize({ width, height: 812 })
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
    await inbox.screenshot({ path: 'reports/cal006-mobile-' + width + '.png', style: 'header, [aria-label="Notifications"] { visibility: hidden !important; }' })
  }
  expect(errors).toEqual([])
})
