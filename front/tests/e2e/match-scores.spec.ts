import { expect, test } from '@playwright/test'
import type { MatchGame, MatchRecord, MatchScorecard } from '../../src/api/matches'

test('règles, remake, score BO3 et conflit sans rejeu sur mobile', async ({ page }) => {
  const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222', revision = '33333333-3333-4333-8333-333333333333'
  const a = '44444444-4444-4444-8444-444444444444', b = '55555555-5555-4555-8555-555555555555'
  const versions = [revision, '66666666-6666-4666-8666-666666666666', '77777777-7777-4777-8777-777777777777', '88888888-8888-4888-8888-888888888888']
  const root = 'tenants/' + org + '/matches', label = '<script>Carte historique</script> ' + 'LongNom'.repeat(12)
  const headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }, errors: string[] = []
  let writes = 0, confirmed = false, stale = false, reads = 0
  let record: MatchRecord = { id, version: revision, title: 'Match de préparation', kind: 'scrim', format: 'bo3', status: 'in_progress', visibility: 'private', game_id: id, game_key: 'pilot', game_name: 'Jeu pilote',
    starts_at: '2026-10-19T10:00:00Z', ends_at: '2026-10-19T12:00:00Z', starts_at_origin: '2026-10-19T10:00:00+00:00', ends_at_origin: '2026-10-19T12:00:00+00:00', origin_timezone: 'UTC',
    responsible_person_id: a, responsible_label: 'Alex', editable: false, available_transitions: ['awaiting_validation', 'cancelled'],
    participants: [{ id: a, kind: 'person', source_id: a, label: 'Alex', reference_version: revision, slot: 1 }, { id: b, kind: 'person', source_id: b, label: 'Robin', reference_version: revision, slot: 2 }] }
  let scorecard: MatchScorecard = { match_version: revision, rules: null, series: null }
  const history: MatchGame[] = []
  page.on('pageerror', (error) => errors.push(error.message))
  page.on('console', (message) => { if (message.type() === 'error' && !message.text().includes('409')) errors.push(message.text()) })
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request(), url = new URL(request.url()), path = url.pathname.replace('/api/v1/', ''), method = request.method()
    if (method === 'OPTIONS') { await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET, POST, PATCH', 'Access-Control-Allow-Headers': 'Content-Type, X-Tenant-Revision, X-XSRF-TOKEN' } }); return }
    let data: unknown = [], status = 200
    if (path === 'auth/me') data = { id: 42, name: 'Responsable Match', email: 'match@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (path === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (path.endsWith('/game-profile-catalogue')) data = { games: [] }
    if (path === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: id }]
    if (path === 'active-organization/confirmation') confirmed = true
    if (path === 'active-organization' || path === 'active-organization/confirmation') data = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr',
      roles: ['member'], permissions: ['organization.view', 'sports.read', 'sports.write'], is_owner: false, actor_user_id: 42, membership_id: id }, revision, confirmed }
    if (path === root) data = [record]
    if (path === root + '/' + id + '/games' && method === 'GET') { reads++; data = url.searchParams.get('page') === '2' ? history.slice(1) : history.slice(0, 1) }
    if (path.startsWith(root) && method === 'POST') {
      expect(confirmed).toBe(true); expect(request.headers()['x-tenant-revision']).toBe(revision)
      const body = request.postDataJSON(); expect(body.version).toBe(scorecard.match_version)
      writes++
      if (stale) {
        await route.fulfill({ status: 409, headers, contentType: 'application/json', body: JSON.stringify({ message: 'Le match a changé. Rechargez le dossier.', code: 'MATCH_VERSION_STALE', errors: {}, meta: { request_id: 'score-conflict' } }) }); return
      }
      if (path.endsWith('/score-rules')) {
        expect(body).toEqual({ version: versions[0], game_limit: 3, allow_draw: false, reason: 'Règlement BO3 confirmé avant saisie.' })
        scorecard = { match_version: versions[1], rules: { format: 'bo3', game_limit: 3, allow_draw: false }, series: { complete: false, draw: false, played: 0, winner_ids: [], standings: [{ participant_id: a, score: 0 }, { participant_id: b, score: 0 }] } }
      } else {
        const remake = writes === 2
        expect(body.outcome).toBe(remake ? 'remake' : 'played')
        expect(body.results).toEqual([{ participant_id: a, score: remake ? null : 13 }, { participant_id: b, score: remake ? null : 8 }])
        const game: MatchGame = { id: versions[writes], number: history.length + 1, match_version: versions[writes], outcome: body.outcome, map: body.map, mode: body.mode, game_patch: body.game_patch,
          reason: body.reason, actor_user_id: 42, occurred_at: '2026-10-19T11:00:00Z', results: body.results.map((r: { participant_id: string; score: number | null }, i: number) => ({ ...r, label: i === 0 ? 'Alex' : 'Robin', lineup_revision_id: null })) }
        history.unshift(game)
        scorecard = { ...scorecard, match_version: versions[writes], series: { ...scorecard.series!, played: remake ? 0 : 1, standings: [{ participant_id: a, score: remake ? 0 : 1 }, { participant_id: b, score: 0 }] } }
      }
      record = { ...record, version: scorecard.match_version }; data = scorecard; status = 201
    }
    await route.fulfill({ status, headers, contentType: 'application/json', body: JSON.stringify({ data, ...(path.endsWith('/games') && method === 'GET' ? { scorecard } : {}),
      meta: { current_page: Number(url.searchParams.get('page') ?? 1), last_page: path.endsWith('/games') && history.length > 1 ? 2 : 1, total: path.endsWith('/games') ? history.length : Array.isArray(data) ? data.length : 0 } }) })
  })
  await page.setViewportSize({ width: 375, height: 812 }); await page.goto('/')
  const panel = page.getByRole('region', { name: 'Scrims et matchs' })
  await panel.getByRole('button', { name: 'Ouvrir les matchs' }).click(); expect(reads).toBe(0)
  const open = () => panel.getByRole('button', { name: 'Parties et scores', exact: true }).click()
  await open()
  const scores = panel.getByRole('region', { name: 'Parties et scores', exact: true })
  const configure = scores.getByRole('form', { name: 'Fixer les règles de score' })
  await configure.getByLabel('Motif des règles ou du résultat').fill('Règlement BO3 confirmé avant saisie.')
  await configure.getByRole('button', { name: 'Fixer les règles de score' }).click()
  await scores.getByRole('button', { name: 'Confirmer et continuer' }).click(); await expect(scores).toHaveCount(0)
  for (const outcome of ['remake', 'played']) {
    await open()
    const form = scores.getByRole('form', { name: 'Ajouter une partie' })
    await form.getByLabel('Résultat de la partie').selectOption(outcome)
    await form.getByLabel('Carte de la partie').fill(label)
    await form.getByLabel('Version du jeu').fill('1.0')
    await form.getByLabel('Motif des règles ou du résultat').fill(outcome === 'remake' ? 'Remake après incident technique.' : 'Score relevé après la partie jouée.')
    if (outcome === 'played') { await form.getByLabel('Score de la partie · Alex').fill('13'); await form.getByLabel('Score de la partie · Robin').fill('8') }
    await form.getByRole('button', { name: 'Enregistrer la partie' }).click()
    await scores.getByRole('button', { name: 'Confirmer et continuer' }).click(); await expect(scores).toHaveCount(0)
  }
  await open(); await expect(scores.getByText('Alex : 1', { exact: true })).toBeVisible()
  await expect(scores.getByText('Score relevé après la partie jouée.', { exact: true })).toBeVisible()
  for (const width of [375, 320]) {
    await page.setViewportSize({ width, height: 812 })
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
    await scores.screenshot({ path: 'reports/match003-mobile-' + width + '.png', style: 'header, [aria-label="Notifications"] { visibility: hidden !important; }' })
  }
  await scores.getByRole('button', { name: 'Page suivante' }).click()
  await expect(scores.getByText('Remake après incident technique.', { exact: true })).toBeVisible()
  await expect(scores.getByRole('form', { name: 'Ajouter une partie' })).toHaveCount(0)
  await scores.getByRole('button', { name: 'Page précédente' }).click()
  stale = true
  const form = scores.getByRole('form', { name: 'Ajouter une partie' })
  await form.getByLabel('Carte de la partie').fill('Arena')
  await form.getByLabel('Version du jeu').fill('1.0')
  await form.getByLabel('Score de la partie · Alex').fill('13'); await form.getByLabel('Score de la partie · Robin').fill('8')
  await form.getByLabel('Motif des règles ou du résultat').fill('Saisie concurrente du résultat sportif.')
  await form.getByRole('button', { name: 'Enregistrer la partie' }).click()
  await scores.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await expect(page.getByText('Le match a changé. Rechargez le dossier.').first()).toBeVisible()
  await expect(panel.locator('script,img,iframe')).toHaveCount(0)
  expect(writes).toBe(4); expect(history).toHaveLength(2); expect(errors).toEqual([])
})
