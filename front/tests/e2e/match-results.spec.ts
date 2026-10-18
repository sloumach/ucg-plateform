import { expect, test } from '@playwright/test'
import type { MatchRecord, MatchResult, MatchResultEvent, MatchResultAction } from '../../src/api/matches'

test('deux comptes, proposition, approbation, contestation, nouvelle preuve et conflit sur mobile', async ({ page }) => {
  const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222', revision = '33333333-3333-4333-8333-333333333333'
  const a = '44444444-4444-4444-8444-444444444444', b = '55555555-5555-4555-8555-555555555555'
  const versions = [revision, '66666666-6666-4666-8666-666666666666', '77777777-7777-4777-8777-777777777777', '88888888-8888-4888-8888-888888888888', '99999999-9999-4999-8999-999999999999']
  const root = 'tenants/' + org + '/matches', proof = '<script>Rapport historique</script> ' + 'RéférenceLongue'.repeat(8)
  const headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }, errors: string[] = []
  let actor = 42, writes = 0, confirmed = false, stale = false, reads = 0
  let record: MatchRecord = { id, version: revision, title: 'Match de préparation', kind: 'scrim', format: 'bo1', status: 'in_progress', visibility: 'private', game_id: id, game_key: 'pilot', game_name: 'Jeu pilote',
    starts_at: '2026-10-19T10:00:00Z', ends_at: '2026-10-19T12:00:00Z', starts_at_origin: '2026-10-19T10:00:00+00:00', ends_at_origin: '2026-10-19T12:00:00+00:00', origin_timezone: 'UTC',
    responsible_person_id: a, responsible_label: 'Alex', editable: false, available_transitions: ['awaiting_validation', 'cancelled'],
    participants: [{ id: a, kind: 'person', source_id: a, label: 'Alex', reference_version: revision, slot: 1 }, { id: b, kind: 'person', source_id: b, label: 'Robin', reference_version: revision, slot: 2 }] }
  const history: MatchResultEvent[] = []
  const currentResult = (): MatchResult => {
    const current = history[0] ?? null, submission = history.find((e) => e.action === 'submit') ?? null
    let actions: MatchResultAction[] = []
    if ((!current || current.action === 'dispute') && actor === 42) actions = ['submit']
    if (current?.action === 'submit') actions = actor === 45 ? ['approve', 'dispute'] : ['dispute']
    if (current?.action === 'approve') actions = ['dispute']
    return { match_version: record.version, status: record.status, locked: !!current, current, submission, allowed_actions: actions }
  }
  page.on('pageerror', (error) => errors.push(error.message))
  page.on('console', (message) => { if (message.type() === 'error' && !message.text().includes('409')) errors.push(message.text()) })
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request(), url = new URL(request.url()), path = url.pathname.replace('/api/v1/', ''), method = request.method()
    if (method === 'OPTIONS') { await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET, POST, PATCH', 'Access-Control-Allow-Headers': 'Content-Type, X-Tenant-Revision, X-XSRF-TOKEN' } }); return }
    let data: unknown = [], status = 200
    if (path === 'auth/me') data = { id: actor, name: actor === 42 ? 'Auteur du résultat' : 'Responsable de validation', email: 'match@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (path === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (path.endsWith('/game-profile-catalogue')) data = { games: [] }
    if (path === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: id }]
    if (path === 'active-organization/confirmation') confirmed = true
    if (path === 'active-organization' || path === 'active-organization/confirmation') data = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr',
      roles: ['member'], permissions: ['organization.view', 'sports.read', ...(actor === 42 ? ['sports.write'] : ['sports.validate'])], is_owner: false, actor_user_id: actor, membership_id: id }, revision, confirmed }
    if (path === root) data = [record]
    if (path === root + '/' + id + '/results' && method === 'GET') { reads++; data = history.slice((Number(url.searchParams.get('page') ?? 1) - 1), Number(url.searchParams.get('page') ?? 1)) }
    if (path.startsWith(root) && method === 'POST') {
      expect(confirmed).toBe(true); expect(request.headers()['x-tenant-revision']).toBe(revision)
      const body = request.postDataJSON(); expect(body.version).toBe(record.version); writes++
      if (stale) {
        await route.fulfill({ status: 409, headers, contentType: 'application/json', body: JSON.stringify({ message: 'Le résultat a changé. Rechargez avant de décider.', code: 'MATCH_VERSION_STALE', errors: {}, meta: { request_id: 'result-conflict' } }) }); return
      }
      const action = path.endsWith('/approvals') ? 'approve' : path.endsWith('/disputes') ? 'dispute' : 'submit'
      if (action === 'approve') { expect(actor).toBe(45); expect(body.confirm_evidence).toBe(true) }
      const previous = currentResult(), target = action === 'submit' ? 'awaiting_validation' : action === 'approve' ? 'validated' : 'disputed'
      if (action !== 'submit') expect(body.submission_id).toBe(previous.submission?.id)
      if (action === 'submit') { expect(body.confirm_proof).toBe(true); expect(body.snapshot).toBeUndefined(); expect(body.outcome).toBe('played') }
      const event: MatchResultEvent = { id: versions[writes], number: writes, action, outcome: 'played', from_status: record.status, to_status: target, match_version: versions[writes],
        submission_id: action === 'submit' ? null : previous.submission!.id, proof_reference: action === 'submit' ? body.proof_reference : null, reason: body.reason, actor_user_id: actor, occurred_at: '2026-10-19T12:00:00Z',
        snapshot: action === 'submit' ? { outcome: 'played', winner_ids: [a], participants: [{ participant_id: a, label: 'Alex' }, { participant_id: b, label: 'Robin' }],
          series: { complete: true, draw: false, played: 1, winner_ids: [a], standings: [{ participant_id: a, score: 1 }, { participant_id: b, score: 0 }] }, rules: { format: 'bo1', game_limit: 1, allow_draw: false }, game_ids: [id] } : null }
      history.unshift(event); record = { ...record, status: target, version: versions[writes], available_transitions: [] }; data = currentResult(); status = action === 'submit' ? 201 : 200
    }
    await route.fulfill({ status, headers, contentType: 'application/json', body: JSON.stringify({ data, ...(path.endsWith('/results') && method === 'GET' ? { result: currentResult() } : {}),
      meta: { current_page: Number(url.searchParams.get('page') ?? 1), last_page: path.endsWith('/results') ? Math.max(1, history.length) : 1, total: path.endsWith('/results') ? history.length : Array.isArray(data) ? data.length : 0 } }) })
  })
  await page.setViewportSize({ width: 375, height: 812 }); await page.goto('/')
  const panel = page.getByRole('region', { name: 'Scrims et matchs' }), results = panel.getByRole('region', { name: 'Résultats et validation', exact: true })
  const open = async () => { await panel.getByRole('button', { name: 'Ouvrir les matchs' }).click(); await panel.getByRole('button', { name: 'Résultats et validation', exact: true }).click() }
  await open(); expect(reads).toBeGreaterThan(0)
  const submit = async (reference: string) => {
    const form = results.getByRole('form', { name: 'Soumettre le résultat' })
    await form.getByLabel('Référence de preuve').fill(reference)
    await form.getByLabel('Je confirme que cette preuve est conservée et consultable par l’approbateur habilité.').check()
    await form.getByLabel('Motif de la proposition ou de la décision').fill('Résultat proposé selon la preuve conservée.')
    await form.getByRole('button', { name: 'Soumettre le résultat' }).click()
    await results.getByRole('button', { name: 'Confirmer et continuer' }).click(); await expect(results).toHaveCount(0)
  }
  await submit(proof)
  await panel.getByRole('button', { name: 'Résultats et validation', exact: true }).click()
  await expect(results.getByRole('button', { name: 'Approuver le résultat', exact: true })).toHaveCount(0)
  actor = 45; confirmed = false; await page.reload(); await open()
  const approve = async () => {
    const form = results.getByRole('form', { name: 'Approuver le résultat' })
    await form.getByLabel('J’ai personnellement consulté la preuve et vérifié le résultat proposé.').check()
    await form.getByLabel('Motif de la proposition ou de la décision').fill('Preuve consultée et résultat conforme.')
    await form.getByRole('button', { name: 'Approuver le résultat' }).click(); await results.getByRole('button', { name: 'Confirmer et continuer' }).click()
  }
  await approve(); await expect(results).toHaveCount(0)
  await panel.getByRole('button', { name: 'Résultats et validation', exact: true }).click()
  const dispute = results.getByRole('form', { name: 'Contester le résultat' })
  await dispute.getByLabel('Motif de la proposition ou de la décision').fill('La preuve doit être complétée et vérifiée de nouveau.')
  await dispute.getByRole('button', { name: 'Contester le résultat' }).click(); await results.getByRole('button', { name: 'Confirmer et continuer' }).click(); await expect(results).toHaveCount(0)
  actor = 42; confirmed = false; await page.reload(); await open(); await submit('Nouveau rapport conservé R-43')
  actor = 45; confirmed = false; await page.reload(); await open()
  await expect(results.getByText('Nouveau rapport conservé R-43', { exact: true })).toBeVisible()
  for (const width of [375, 320]) {
    await page.setViewportSize({ width, height: 812 }); expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
    await results.screenshot({ path: 'reports/match004-mobile-' + width + '.png', style: 'header, [aria-label="Notifications"] { visibility: hidden !important; }' })
  }
  await results.getByRole('button', { name: 'Page 4', exact: true }).click()
  await expect(results.getByText('Référence de preuve : ' + proof, { exact: true })).toBeVisible()
  await expect(results.getByRole('form')).toHaveCount(0)
  await results.getByRole('button', { name: 'Page 1', exact: true }).click()
  stale = true; await approve()
  await expect(page.getByText('Le résultat a changé. Rechargez avant de décider.').first()).toBeVisible()
  await expect(panel.locator('script,img,iframe')).toHaveCount(0)
  expect(writes).toBe(5); expect(history).toHaveLength(4); expect(errors).toEqual([])
})
