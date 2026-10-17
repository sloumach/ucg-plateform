import { expect, test } from '@playwright/test'

test('match privé à trois participants, confirmation, historique et annulation sur mobile', async ({ page }) => {
  const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222', revision = '33333333-3333-4333-8333-333333333333'
  const game = '44444444-4444-4444-8444-444444444444', person = '55555555-5555-4555-8555-555555555555'
  const team = '66666666-6666-4666-8666-666666666666', secondTeam = '77777777-7777-4777-8777-777777777777', opponent = '88888888-8888-4888-8888-888888888888'
  const versions = [revision, '99999999-9999-4999-8999-999999999999', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']
  const root = 'tenants/' + org + '/matches', title = '<script>Match de préparation</script> ' + 'RencontreLongue'.repeat(5)
  const labels: Record<string, string> = { [team]: '<img>Équipe première</img>', [secondTeam]: 'Équipe réserve', [opponent]: 'Adversaire public ' + 'NomLong'.repeat(8) }
  const errors: string[] = [], headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }
  let confirmed = false, reads = 0, transitions = 0, record: Record<string, unknown> | null = null
  const history: Record<string, unknown>[] = []
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
    if (path === root + '/references/game') data = [{ id: game, label: 'Jeu pilote' }]
    if (path === root + '/references/person') data = [{ id: person, label: 'Responsable pilote' }]
    if (path === root + '/references/team') data = [team, secondTeam].map((id) => ({ id, label: labels[id] }))
    if (path === root + '/references/opponent_snapshot') data = [{ id: opponent, label: labels[opponent] }]
    if (path.startsWith(root) && method !== 'GET') { expect(confirmed).toBe(true); expect(request.headers()['x-tenant-revision']).toBe(revision) }
    if (path === root && method === 'POST') {
      const body = request.postDataJSON()
      expect(body).toMatchObject({ title, game_id: game, format: 'lobby', kind: 'scrim', visibility: 'private', responsible_person_id: person,
        starts_at: '2026-10-19T10:00:00+00:00', ends_at: '2026-10-19T12:00:00+00:00', origin_timezone: 'UTC',
        participants: [{ kind: 'team', source_id: team }, { kind: 'team', source_id: secondTeam }, { kind: 'opponent_snapshot', source_id: opponent }] })
      expect(body).not.toHaveProperty('score'); expect(body).not.toHaveProperty('organization_id')
      record = { ...body, id, version: versions[0], game_name: 'Jeu pilote', game_key: 'pilot', responsible_label: 'Responsable pilote', starts_at_origin: body.starts_at, ends_at_origin: body.ends_at,
        status: 'proposed', editable: true, available_transitions: ['confirmed', 'cancelled'], participants: body.participants.map((p: { kind: string; source_id: string }, index: number) => ({ ...p, id: p.source_id, label: labels[p.source_id], reference_version: revision, slot: index + 1 })) }
      history.unshift({ id: versions[0], from_status: null, to_status: 'proposed', reason: null, match_version: versions[0], occurred_at: '2026-10-18T10:00:00Z' })
      data = record
    }
    if (path === root + '/' + id + '/transitions') {
      const body = request.postDataJSON()
      expect(body.version).toBe(versions[transitions]); expect(body.status).toBe(transitions === 0 ? 'confirmed' : 'cancelled')
      expect(body.reason).toBe(transitions === 0 ? 'Participants et horaire vérifiés.' : 'Rencontre annulée après concertation.')
      transitions++
      history.unshift({ id: versions[transitions], from_status: record!.status, to_status: body.status, reason: body.reason, match_version: versions[transitions], occurred_at: '2026-10-18T10:01:00Z' })
      record = { ...record, status: body.status, version: versions[transitions], editable: false, available_transitions: body.status === 'confirmed' ? ['proposed', 'in_progress', 'cancelled'] : [] }
      data = record
    }
    if (path === root && method === 'GET') { reads++; data = record ? [record] : [] }
    if (path === root + '/' + id + '/history') data = history
    await route.fulfill({ status: method === 'POST' && path === root ? 201 : 200, headers, contentType: 'application/json',
      body: JSON.stringify({ data, meta: { current_page: 1, last_page: 1, total: Array.isArray(data) ? data.length : 0 } }) })
  })
  await page.setViewportSize({ width: 375, height: 812 }); await page.goto('/')
  const panel = page.getByRole('region', { name: 'Scrims et matchs' })
  await expect(panel.getByRole('button', { name: 'Ouvrir les matchs' })).toBeVisible().catch((failure: unknown) => { throw new Error(String(failure) + '; browser errors: ' + JSON.stringify(errors)) }); expect(reads).toBe(0)
  await panel.getByRole('button', { name: 'Ouvrir les matchs' }).click()
  await expect(panel.getByText('Aucun match accessible dans cette sélection.')).toBeVisible()
  await panel.getByRole('button', { name: 'Nouveau match' }).click()
  const form = panel.getByRole('form', { name: 'Enregistrer le match' })
  await form.getByLabel('Titre du match').fill(title)
  await form.getByLabel('Jeu du match').selectOption(game)
  await form.getByLabel('Format du match').selectOption('lobby')
  await form.getByLabel('Responsable du match').selectOption(person)
  await form.getByLabel('Début dans le fuseau d’origine').fill('2026-10-19T10:00')
  await form.getByLabel('Fin dans le fuseau d’origine').fill('2026-10-19T12:00')
  await form.getByLabel('Nature du participant').selectOption('team')
  for (const source of [team, secondTeam]) {
    await form.getByLabel('Participant à ajouter').selectOption(source)
    await form.getByRole('button', { name: 'Ajouter ce participant' }).click()
  }
  await form.getByLabel('Nature du participant').selectOption('opponent_snapshot')
  await form.getByLabel('Participant à ajouter').selectOption(opponent)
  await form.getByRole('button', { name: 'Ajouter ce participant' }).click()
  await form.getByRole('button', { name: 'Enregistrer le match' }).click()
  await panel.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await expect(panel.getByRole('heading', { name: title, exact: true })).toBeVisible()
  await expect(form).toHaveCount(0)
  for (const [status, reason] of [['confirmed', 'Participants et horaire vérifiés.'], ['cancelled', 'Rencontre annulée après concertation.']]) {
    await panel.getByRole('button', { name: 'Changer le statut du match', exact: true }).click()
    const transition = panel.getByRole('form', { name: 'Changement de statut du match' })
    await transition.getByLabel('Nouveau statut du match').selectOption(status)
    await transition.getByLabel('Motif du changement de statut').fill(reason)
    await transition.getByRole('button', { name: 'Changer le statut du match', exact: true }).click()
    await panel.getByRole('button', { name: 'Confirmer et continuer' }).click()
    await expect(transition).toHaveCount(0)
    await expect(panel.getByRole('button', { name: 'Modifier le match' })).toHaveCount(0)
  }
  await expect(panel.getByRole('button', { name: 'Changer le statut du match' })).toHaveCount(0)
  await panel.getByRole('button', { name: 'Historique des statuts du match' }).click()
  const log = panel.getByRole('region', { name: 'Historique des statuts du match' })
  await expect(log.getByText('Rencontre annulée après concertation.', { exact: true })).toBeVisible()
  await expect(log.getByText('Participants et horaire vérifiés.', { exact: true })).toBeVisible()
  await expect(panel.locator('script,img,iframe')).toHaveCount(0)
  for (const width of [375, 320]) {
    await page.setViewportSize({ width, height: 812 })
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
    await panel.screenshot({ path: 'reports/match001-mobile-' + width + '.png', style: 'header, [aria-label="Notifications"] { visibility: hidden !important; }' })
  }
  expect(transitions).toBe(2); expect(errors).toEqual([])
})
