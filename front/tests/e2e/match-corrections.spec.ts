import { expect, test } from '@playwright/test'
import type { MatchRecord, MatchGame, MatchCorrection, MatchResult, MatchResultEvent } from '../../src/api/matches'

test('correction auditée, recalcul en attente, reprise et nouvelle validation indépendante sur mobile', async ({ page }) => {
  const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222', revision = '33333333-3333-4333-8333-333333333333'
  const a = '44444444-4444-4444-8444-444444444444', b = '55555555-5555-4555-8555-555555555555', cid = '66666666-6666-4666-8666-666666666666', next = '77777777-7777-4777-8777-777777777777'
  const root = 'tenants/' + org + '/matches', correctionsPath = root + '/' + id + '/corrections'
  const headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }, errors: string[] = []
  let actor = 42, confirmed = false, pending = false, applied = false, requests = 0, retries = 0, proposal = false, approved = false
  let record: MatchRecord = { id, version: revision, title: 'Match de préparation', kind: 'scrim', format: 'bo1', status: 'validated', visibility: 'private', game_id: id, game_key: 'pilot', game_name: 'Jeu pilote',
    starts_at: '2026-10-19T10:00:00Z', ends_at: '2026-10-19T12:00:00Z', starts_at_origin: '2026-10-19T10:00:00+00:00', ends_at_origin: '2026-10-19T12:00:00+00:00', origin_timezone: 'UTC',
    responsible_person_id: a, responsible_label: 'Alex', editable: false, available_transitions: [],
    participants: [{ id: a, kind: 'person', source_id: a, label: 'Alex', reference_version: revision, slot: 1 }, { id: b, kind: 'person', source_id: b, label: 'Robin', reference_version: revision, slot: 2 }] }

  const results = (corrected: boolean) => [{ participant_id: a, label: 'Alex', score: corrected ? 0 : 13, lineup_revision_id: null }, { participant_id: b, label: 'Robin', score: corrected ? 13 : 7, lineup_revision_id: null }]
  const game = (): MatchGame => ({ id: b, number: 1, match_version: revision, outcome: 'played', map: '<script>Carte pilote</script>', mode: null, game_patch: '1.0',
    reason: 'Partie source conservée.', actor_user_id: 42, occurred_at: '2026-10-19T11:00:00Z', results: results(applied), correction_id: applied ? cid : null })
  const correction = (): MatchCorrection => ({ id: cid, number: 1, match_version: next, state: pending ? 'pending' : 'applied', reason: '<script>Correction selon le rapport</script>', actor_user_id: 42, occurred_at: '2026-10-19T11:10:00Z', applied_at: applied ? '2026-10-19T11:11:00Z' : null,
    before: [{ game_id: b, number: 1, outcome: 'played', results: results(false) }], after: [{ game_id: b, number: 1, outcome: 'played', results: results(true) }] })
  const series = () => ({ complete: true, draw: false, played: 1, winner_ids: [applied ? b : a], standings: [{ participant_id: a, score: applied ? 0 : 1 }, { participant_id: b, score: applied ? 1 : 0 }] })
  const submission = (): MatchResultEvent => ({ id: proposal ? cid : a, number: proposal ? 4 : 1, action: 'submit', outcome: 'played', from_status: proposal ? 'disputed' : 'in_progress', to_status: 'awaiting_validation', match_version: record.version,
    submission_id: null, proof_reference: proposal ? 'Nouveau rapport corrigé R-43' : 'Ancien rapport conservé R-42', reason: 'Résultat proposé selon la preuve.', actor_user_id: 42, occurred_at: '2026-10-19T11:12:00Z',
    snapshot: { outcome: 'played', winner_ids: [applied ? b : a], participants: record.participants.map((p) => ({ participant_id: p.id, label: p.label })), series: series(), rules: { format: 'bo1', game_limit: 1, allow_draw: false }, game_ids: [b] } })
  const result = (): MatchResult => ({ match_version: record.version, status: record.status, locked: true, score_revision: requests ? cid : null, recalculation_pending: pending,
    submission: submission(), current: { ...submission(), action: approved || !requests ? 'approve' : proposal ? 'submit' : 'dispute', submission_id: submission().id },
    allowed_actions: pending ? [] : actor === 42 && applied && !proposal ? ['submit'] : actor === 45 && proposal && !approved ? ['approve'] : [] })
  page.on('pageerror', (error) => errors.push(error.message))
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request(), url = new URL(request.url()), path = url.pathname.replace('/api/v1/', ''), method = request.method()
    if (method === 'OPTIONS') { await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET, POST, PATCH', 'Access-Control-Allow-Headers': 'Content-Type, X-Tenant-Revision, X-XSRF-TOKEN' } }); return }
    let data: unknown = [], status = 200, extra: Record<string, unknown> = {}
    if (path === 'auth/me') data = { id: actor, name: 'Coach', email: 'match@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (path === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (path.endsWith('/game-profile-catalogue')) data = { games: [] }
    if (path === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: id }]
    if (path === 'active-organization/confirmation') confirmed = true
    if (path === 'active-organization' || path === 'active-organization/confirmation') data = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'],
      permissions: ['organization.view', 'sports.read', ...(actor === 42 ? ['sports.write', 'sports.correct'] : ['sports.validate'])], is_owner: false, actor_user_id: actor, membership_id: id }, revision, confirmed }
    if (path === root) data = [record]
    if (path === correctionsPath && method === 'POST') {
      const body = request.postDataJSON(); expect(confirmed).toBe(true); expect(body.score_revision).toBeNull(); expect(body.version).toBe(revision)
      expect(body.games).toEqual([{ game_id: b, outcome: 'played', results: [{ participant_id: a, score: 0 }, { participant_id: b, score: 13 }] }]); expect(body.idempotency_key).toMatch(/^[a-f0-9-]{36}$/)
      requests++; pending = true; record = { ...record, status: 'disputed', version: next }; data = correction(); status = 202
    }
    if (path === correctionsPath + '/' + cid + '/retries') { retries++; pending = false; applied = true; data = correction(); status = 202 }
    if (path === correctionsPath && method === 'GET') { data = requests ? [correction()] : []; extra = { corrections: { match_version: record.version, score_revision: requests ? cid : null, pending, can_correct: actor === 42 } } }
    if (path === root + '/' + id + '/games') { data = [game()]; extra = { scorecard: { match_version: record.version, score_revision: requests ? cid : null, recalculation_pending: pending, result_locked: true, rules: { format: 'bo1', game_limit: 1, allow_draw: false }, series: pending ? null : series() } } }
    if (path === root + '/' + id + '/results' && method === 'POST') {
      const body = request.postDataJSON(); expect(body.score_revision).toBe(cid); expect(body.version).toBe(next); expect(body.proof_reference).toBe('Nouveau rapport corrigé R-43')
      proposal = true; record = { ...record, status: 'awaiting_validation', version: cid }; data = result(); status = 201
    }
    if (path.endsWith('/results/approvals')) { expect(actor).toBe(45); approved = true; record = { ...record, status: 'validated', version: a }; data = result() }
    if (path === root + '/' + id + '/results' && method === 'GET') { data = [result().current]; extra = { result: result() } }
    const meta = Array.isArray(data) ? { current_page: 1, last_page: 1, total: data.length } : undefined
    await route.fulfill({ status, headers, json: { data, ...extra, ...(meta ? { meta } : {}) } })
  })
  await page.setViewportSize({ width: 375, height: 900 }); await page.goto('/')
  const panel = page.getByRole('region', { name: 'Scrims et matchs', exact: true }), corrections = page.getByRole('region', { name: 'Corrections des scores', exact: true })
  await panel.getByRole('button', { name: 'Ouvrir les matchs' }).click(); await panel.getByRole('button', { name: 'Corrections des scores', exact: true }).click()
  await corrections.getByRole('button', { name: 'Préparer une correction' }).click()
  const form = corrections.getByRole('form', { name: 'Demander la correction et le recalcul' })
  await form.getByLabel('Corriger cette partie #1').check(); await form.getByRole('spinbutton').nth(0).fill('0'); await form.getByRole('spinbutton').nth(1).fill('13')
  await form.getByLabel('Motif de la correction').fill('Correction motivée selon le rapport source.')
  await form.getByLabel('Je confirme ces corrections et la remise en validation du résultat.').check()
  await form.getByRole('button', { name: 'Demander la correction et le recalcul' }).click(); expect(requests).toBe(0)
  await corrections.getByRole('button', { name: 'Confirmer et continuer' }).click(); await expect(corrections).toHaveCount(0)
  await panel.getByRole('button', { name: 'Corrections des scores', exact: true }).click()
  await expect(corrections.getByText('En attente de recalcul', { exact: false })).toBeVisible(); await expect(corrections.getByRole('button', { name: 'Préparer une correction' })).toHaveCount(0)
  await corrections.getByRole('button', { name: 'Relancer le recalcul' }).click(); await corrections.getByRole('button', { name: 'Confirmer et continuer' }).click(); await expect(corrections).toHaveCount(0)
  await panel.getByRole('button', { name: 'Corrections des scores', exact: true }).click(); await expect(corrections.getByText('Recalcul terminé', { exact: false })).toBeVisible()
  await expect(corrections.getByText('<script>Correction selon le rapport</script>', { exact: true })).toBeVisible(); await expect(corrections.locator('script,img,iframe,a')).toHaveCount(0)
  for (const width of [375, 320]) {
    await page.setViewportSize({ width, height: 900 }); expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
    await corrections.screenshot({ path: 'reports/match006-' + width + '.png' })
  }
  await panel.getByRole('button', { name: 'Résultats et validation', exact: true }).click()
  const resultsPanel = page.getByRole('region', { name: 'Résultats et validation', exact: true }), submit = resultsPanel.getByRole('form', { name: 'Soumettre le résultat' })
  await submit.getByLabel('Référence de preuve').fill('Nouveau rapport corrigé R-43')
  await submit.getByLabel('Je confirme que cette preuve est conservée et consultable par l’approbateur habilité.').check()
  await submit.getByLabel('Motif de la proposition ou de la décision').fill('Nouvelle preuve après correction et recalcul.')
  await submit.getByRole('button', { name: 'Soumettre le résultat' }).click(); await resultsPanel.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await expect(resultsPanel).toHaveCount(0)
  actor = 45; confirmed = false; await page.reload(); await panel.getByRole('button', { name: 'Ouvrir les matchs' }).click(); await panel.getByRole('button', { name: 'Résultats et validation', exact: true }).click()
  const approve = resultsPanel.getByRole('form', { name: 'Approuver le résultat' })
  await approve.getByLabel('J’ai personnellement consulté la preuve et vérifié le résultat proposé.').check(); await approve.getByLabel('Motif de la proposition ou de la décision').fill('Nouvelle preuve consultée et corrections vérifiées.')
  await approve.getByRole('button', { name: 'Approuver le résultat' }).click(); await resultsPanel.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await expect(resultsPanel).toHaveCount(0)
  expect(approved).toBe(true); expect(requests).toBe(1); expect(retries).toBe(1); expect(errors).toEqual([])
})
