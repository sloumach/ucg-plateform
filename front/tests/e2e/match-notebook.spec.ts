import { expect, test } from '@playwright/test'
import type { MatchRecord, MatchNotebookEntry } from '../../src/api/matches'

test('préparation privée, annotation VOD partageable, historique et droits distincts sur mobile', async ({ page }) => {
  const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222', revision = '33333333-3333-4333-8333-333333333333'
  const a = '44444444-4444-4444-8444-444444444444', b = '55555555-5555-4555-8555-555555555555'
  const root = 'tenants/' + org + '/matches', path = root + '/' + id + '/notebook'
  const headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }, errors: string[] = []
  let actor = 42, writes = 0, confirmed = false
  const entries: MatchNotebookEntry[] = [], revisions: MatchNotebookEntry[] = []
  const record: MatchRecord = { id, version: revision, title: 'Match de préparation', kind: 'scrim', format: 'bo1', status: 'in_progress', visibility: 'private', game_id: id, game_key: 'pilot', game_name: 'Jeu pilote',
    starts_at: '2026-10-19T10:00:00Z', ends_at: '2026-10-19T12:00:00Z', starts_at_origin: '2026-10-19T10:00:00+00:00', ends_at_origin: '2026-10-19T12:00:00+00:00', origin_timezone: 'UTC',
    responsible_person_id: a, responsible_label: 'Alex', editable: false, available_transitions: ['awaiting_validation', 'cancelled'],
    participants: [{ id: a, kind: 'person', source_id: a, label: 'Alex', reference_version: revision, slot: 1 }, { id: b, kind: 'person', source_id: b, label: 'Robin', reference_version: revision, slot: 2 }] }

  page.on('pageerror', (error) => errors.push(error.message))
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request(), url = new URL(request.url()), target = url.pathname.replace('/api/v1/', ''), method = request.method()
    if (method === 'OPTIONS') { await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET, POST, PATCH', 'Access-Control-Allow-Headers': 'Content-Type, X-Tenant-Revision, X-XSRF-TOKEN' } }); return }
    let data: unknown = [], status = 200
    if (target === 'auth/me') data = { id: actor, name: 'Coach', email: 'match@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (target === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (target.endsWith('/game-profile-catalogue')) data = { games: [] }
    if (target === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: id }]
    if (target === 'active-organization/confirmation') confirmed = true
    if (target === 'active-organization' || target === 'active-organization/confirmation') data = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'],
      permissions: ['organization.view', 'sports.read', ...(actor === 42 ? ['sports.write', 'sports.notes.read', 'sports.notes.write', 'sports.strategies.read', 'sports.strategies.write', 'sports.publish'] : [])], is_owner: false, actor_user_id: actor, membership_id: id }, revision, confirmed }
    if (target === root) data = [record]
    if (target === path && method === 'POST') {
      expect(confirmed).toBe(true); writes++
      const body = request.postDataJSON() as MatchNotebookEntry
      const entry = { ...body, entry_id: writes === 1 ? a : b, version: writes === 1 ? revision : id, number: 1, vod_url: body.vod_url ?? null, timestamp_seconds: body.timestamp_seconds ?? null,
        state: body.state ?? null, due_at: body.due_at ?? null, actor_user_id: actor, occurred_at: '2026-10-19T11:00:00Z' }
      entries.unshift(entry); revisions.unshift(entry); data = entry; status = 201
    }
    if (target === path && method === 'GET') data = entries.filter((e) => (actor === 42 || e.audience === 'shared') && (!url.searchParams.get('audience') || e.audience === url.searchParams.get('audience')))
    if (target.endsWith('/history')) data = revisions.filter((e) => target.includes(e.entry_id))
    const meta = Array.isArray(data) ? { current_page: 1, last_page: 1, total: data.length } : undefined
    await route.fulfill({ status, headers, json: { data, ...(meta ? { meta } : {}), ...(target === path && method === 'GET' ? { writable_audiences: actor === 42 ? ['internal', 'strategy', 'shared'] : [] } : {}) } })
  })
  await page.setViewportSize({ width: 375, height: 900 }); await page.goto('/')
  const panel = page.getByRole('region', { name: 'Scrims et matchs', exact: true })
  const notebook = page.getByRole('region', { name: 'Préparation, VOD et débrief', exact: true })
  const open = async () => { await panel.getByRole('button', { name: 'Ouvrir les matchs' }).click(); await panel.getByRole('button', { name: 'Préparation, VOD et débrief', exact: true }).click() }
  await open()
  await notebook.getByRole('button', { name: 'Ajouter un contenu' }).click()
  let form = notebook.getByRole('form', { name: 'Enregistrer le contenu' })
  await form.getByLabel('Espace du contenu').selectOption('strategy'); await form.getByLabel('Titre du contenu').fill('Stratégie confidentielle')
  await form.getByLabel('Notes ou annotation').fill('<script>Plan de jeu interne</script>')
  await form.getByRole('button', { name: 'Enregistrer le contenu' }).click(); expect(writes).toBe(0)
  await notebook.getByRole('button', { name: 'Confirmer et continuer' }).click(); await expect(form).toHaveCount(0)
  await expect(notebook.getByText('<script>Plan de jeu interne</script>', { exact: true })).toBeVisible()
  await notebook.getByRole('button', { name: 'Versions du contenu' }).click()
  await expect(notebook.getByRole('region', { name: 'Versions du contenu' })).toContainText('Plan de jeu interne')
  await notebook.getByRole('button', { name: 'Fermer les versions' }).click()
  await notebook.getByRole('button', { name: 'Ajouter un contenu' }).click(); form = notebook.getByRole('form', { name: 'Enregistrer le contenu' })
  await form.getByLabel('Type de contenu').selectOption('vod'); await form.getByLabel('Espace du contenu').selectOption('shared')
  await form.getByLabel('Titre du contenu').fill('VOD debrief partageable')
  await form.getByLabel('Notes ou annotation').fill('Observation ' + 'AnnotationLongue'.repeat(12))
  await form.getByLabel('Lien VOD HTTPS').fill('https://example.com/vod/42'); await form.getByLabel('Horodatage en secondes').fill('123')
  await form.getByLabel('Je confirme le partage de ce contenu avec tous les lecteurs autorisés du match.').check()
  await form.getByRole('button', { name: 'Enregistrer le contenu' }).click(); await notebook.getByRole('button', { name: 'Confirmer et continuer' }).click(); await expect(form).toHaveCount(0)
  await expect(notebook.getByRole('link', { name: 'Ouvrir la VOD externe' })).toHaveAttribute('rel', 'noopener noreferrer')
  await expect(notebook.locator('script,iframe,img')).toHaveCount(0)
  for (const width of [375, 320]) {
    await page.setViewportSize({ width, height: 900 })
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
    await notebook.screenshot({ path: 'reports/match005-' + width + '.png' })
  }
  actor = 45; confirmed = false; await page.reload(); await open()
  await expect(notebook.getByText('VOD debrief partageable', { exact: true })).toBeVisible()
  await expect(notebook.getByText('Stratégie confidentielle', { exact: true })).toHaveCount(0)
  await expect(notebook.getByRole('button', { name: 'Ajouter un contenu' })).toHaveCount(0)
  await expect(notebook.getByRole('button', { name: 'Modifier ce contenu' })).toHaveCount(0)
  expect(writes).toBe(2); expect(errors).toEqual([])
})
