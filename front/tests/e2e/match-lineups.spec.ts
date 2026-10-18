import { expect, test } from '@playwright/test'
import type { MatchLineupRevision, MatchRecord } from '../../src/api/matches'

test('affectation, remplacement pendant le match et historique figé sur mobile', async ({ page }) => {
  const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222', revision = '33333333-3333-4333-8333-333333333333'
  const team = '44444444-4444-4444-8444-444444444444', person = '55555555-5555-4555-8555-555555555555', sub = '66666666-6666-4666-8666-666666666666', lineup = '77777777-7777-4777-8777-777777777777'
  const root = 'tenants/' + org + '/matches', lineupsPath = root + '/' + id + '/participants/' + team + '/lineups'
  const versions = [revision, '88888888-8888-4888-8888-888888888888', '99999999-9999-4999-8999-999999999999', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']
  const title = 'Match de préparation', label = '<script>Composition retenue</script> ' + 'NomLong'.repeat(8)
  const headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }, errors: string[] = []
  let confirmed = false, writes = 0, lineageReads = 0
  let record: MatchRecord = { id, version: revision, title, kind: 'scrim', format: 'bo3', status: 'confirmed', visibility: 'private', game_id: id, game_key: 'pilot', game_name: 'Jeu pilote',
    starts_at: '2026-10-19T10:00:00Z', ends_at: '2026-10-19T12:00:00Z', starts_at_origin: '2026-10-19T10:00:00+00:00', ends_at_origin: '2026-10-19T12:00:00+00:00', origin_timezone: 'UTC',
    responsible_person_id: person, responsible_label: 'Alex', editable: false, available_transitions: ['proposed', 'in_progress', 'cancelled'],
    participants: [{ id: team, kind: 'team', source_id: team, label: 'Alpha', reference_version: revision, slot: 1 }, { id: sub, kind: 'person', source_id: id, label: 'Autre participant', reference_version: revision, slot: 2 }] }
  const history: MatchLineupRevision[] = []
  page.on('pageerror', (error) => errors.push(error.message))
  page.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()) })
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request(), path = new URL(request.url()).pathname.replace('/api/v1/', ''), method = request.method()
    if (method === 'OPTIONS') { await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET, POST, PATCH', 'Access-Control-Allow-Headers': 'Content-Type, X-Tenant-Revision, X-XSRF-TOKEN' } }); return }
    let data: unknown = []
    if (path === 'auth/me') data = { id: 42, name: 'Responsable Match', email: 'match@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (path === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (path.endsWith('/game-profile-catalogue')) data = { games: [] }
    if (path === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: id }]
    if (path === 'active-organization/confirmation') confirmed = true
    if (path === 'active-organization' || path === 'active-organization/confirmation') data = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr',
      roles: ['member'], permissions: ['organization.view', 'sports.read', 'sports.write'], is_owner: false, actor_user_id: 42, membership_id: id }, revision, confirmed }
    if (path === root) data = [record]
    if (path === lineupsPath + '/choices') data = [{ id: lineup, label }]
    if (path === lineupsPath && method === 'GET') { data = history; lineageReads++ }
    if (path.startsWith(root) && method === 'POST') {
      expect(confirmed).toBe(true); expect(request.headers()['x-tenant-revision']).toBe(revision)
      const body = request.postDataJSON(); expect(body.version).toBe(versions[writes])
      writes++
      if (path === lineupsPath) {
        expect(body).toEqual({ version: versions[0], lineup_id: lineup, reason: 'Sélection vérifiée avant la rencontre.' })
        history.unshift({ id: versions[1], participant_id: team, match_version: versions[1], number: 1, action: 'assign', phase: 'confirmed', actor_user_id: 42, occurred_at: '2026-10-18T10:00:00Z', reason: body.reason,
          snapshot: { id: lineup, label, team_name: 'Alpha', ruleset_id: null, ruleset_version: null, eligibility_report_id: null,
            participants: [{ person_id: person, pseudonym: '<img>Alex historique</img>', slot_key: 'starter', position: 1 }, { person_id: sub, pseudonym: 'Robin historique', slot_key: 'substitute', position: 2 }] },
          assessment: { status: 'unassessed', effective_at: '2026-10-19T10:00:00Z', evaluated_at: '2026-10-18T10:00:00Z' } })
        data = history[0]
      }
      if (path.endsWith('/transitions')) { expect(body.status).toBe('in_progress'); record = { ...record, status: 'in_progress', available_transitions: ['awaiting_validation', 'cancelled'] }; data = record }
      if (path === lineupsPath + '/substitutions') {
        expect(body).toEqual({ version: versions[2], out_person_id: person, in_person_id: sub, reason: 'Remplacement pendant la rencontre.' })
        const previous = history[0]
        history.unshift({ ...previous, id: versions[3], match_version: versions[3], number: 2, action: 'substitute', phase: 'in_progress', occurred_at: '2026-10-19T10:30:00Z', reason: body.reason,
          snapshot: { ...previous.snapshot, participants: previous.snapshot.participants.map((entry) => ({ ...entry, slot_key: entry.slot_key === 'starter' ? 'substitute' : 'starter' })) } })
        data = history[0]
      }
      record = { ...record, version: versions[writes] }
      if (path.endsWith('/transitions')) data = record
    }
    await route.fulfill({ status: method === 'POST' && path.includes('/lineups') ? 201 : 200, headers, contentType: 'application/json',
      body: JSON.stringify({ data, meta: { current_page: 1, last_page: 1, total: Array.isArray(data) ? data.length : 0 } }) })
  })
  await page.setViewportSize({ width: 375, height: 812 }); await page.goto('/')
  const panel = page.getByRole('region', { name: 'Scrims et matchs' })
  await panel.getByRole('button', { name: 'Ouvrir les matchs' }).click(); expect(lineageReads).toBe(0)
  const open = () => panel.getByRole('button', { name: 'Composition et remplaçants · Alpha', exact: true }).click()
  await open()
  const composition = panel.getByRole('region', { name: 'Composition et remplaçants', exact: true })
  await composition.getByRole('button', { name: 'Affecter une composition', exact: true }).click()
  const assign = composition.getByRole('form', { name: 'Affecter une composition' })
  await assign.getByLabel('Composition finalisée').selectOption(lineup)
  await assign.getByLabel('Motif de la composition ou du remplacement').fill('Sélection vérifiée avant la rencontre.')
  await assign.getByRole('button', { name: 'Enregistrer la composition' }).click()
  await composition.getByRole('button', { name: 'Confirmer et continuer' }).click(); await expect(composition).toHaveCount(0)
  await panel.getByRole('button', { name: 'Changer le statut du match', exact: true }).click()
  const transition = panel.getByRole('form', { name: 'Changement de statut du match' })
  await transition.getByLabel('Nouveau statut du match').selectOption('in_progress')
  await transition.getByLabel('Motif du changement de statut').fill('Démarrage de la rencontre pilote.')
  await transition.getByRole('button', { name: 'Changer le statut du match', exact: true }).click()
  await panel.getByRole('button', { name: 'Confirmer et continuer' }).click(); await expect(transition).toHaveCount(0)
  await open()
  const substitution = composition.getByRole('form', { name: 'Effectuer un remplacement' })
  await expect(composition.getByRole('button', { name: 'Affecter une composition' })).toHaveCount(0)
  await substitution.getByLabel('Motif de la composition ou du remplacement').fill('Remplacement pendant la rencontre.')
  await substitution.getByRole('button', { name: 'Enregistrer le remplacement' }).click()
  await composition.getByRole('button', { name: 'Confirmer et continuer' }).click(); await expect(composition).toHaveCount(0)
  await open()
  await expect(composition.getByText('Remplacement pendant la rencontre.', { exact: true })).toBeVisible()
  await expect(composition.getByText('Sélection vérifiée avant la rencontre.', { exact: true })).toBeVisible()
  await expect(substitution.getByLabel('Titulaire sortant')).toHaveValue(sub)
  await expect(substitution.getByLabel('Remplaçant entrant')).toHaveValue(person)
  await expect(panel.locator('script,img,iframe')).toHaveCount(0)
  for (const width of [375, 320]) {
    await page.setViewportSize({ width, height: 812 })
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
    await composition.screenshot({ path: 'reports/match002-mobile-' + width + '.png', style: 'header, [aria-label="Notifications"] { visibility: hidden !important; }' })
  }
  expect(writes).toBe(3); expect(errors).toEqual([])
})
