import { expect, test } from '@playwright/test'
import type { CompetitionOutcome } from '../../src/api/competitionOutcomes'
test('compétition : matchs, rang sourcé, réception exacte et droits financiers sur mobile', async ({ page }) => {
  const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222', revision = '33333333-3333-4333-8333-333333333333', matchId = '44444444-4444-4444-8444-444444444444'
  const root = 'tenants/' + org + '/external-competitions', sport = root + '/' + id + '/outcomes/sport', awards = root + '/' + id + '/outcomes/awards'
  const headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }, title = '<script>Match privé</script> ' + 'Long'.repeat(25)
  const competition = { id, reference: 'EXTERNAL_2026', game_id: id, game_key: 'pilot', game_name: 'Jeu pilote', name: 'Compétition externe privée', organizer: 'Organisateur', region: 'Europe', season: '2026', contacts: [], website_url: null, version: revision, status: 'active', created_at: '2026-10-18T10:00:00Z', updated_at: '2026-10-18T10:00:00Z' }
  let current: CompetitionOutcome | null = null, money: CompetitionOutcome | null = null, writer = true, confirmed = false, financeReads = 0, writes = 0
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request(), method = request.method(), target = new URL(request.url()).pathname.replace('/api/v1/', '')
    if (method === 'OPTIONS') { await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET, POST, PATCH', 'Access-Control-Allow-Headers': 'Content-Type, X-Tenant-Revision, X-XSRF-TOKEN' } }); return }
    const context = { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'], permissions: ['organization.view', 'sports.read', 'sports.competitions.read', ...(writer ? ['sports.competitions.write', 'financial.read', 'financial.write'] : [])], is_owner: false, actor_user_id: writer ? 42 : 43, membership_id: id }
    let data: unknown = []
    if (target === 'auth/me') data = { id: writer ? 42 : 43, name: 'Manager', email: 'manager@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (target === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (target.endsWith('/game-profile-catalogue')) data = { games: [] }
    if (target === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: id }]
    if (target === 'active-organization') data = { context, revision, confirmed }
    if (target === 'active-organization/confirmation') { confirmed = true; data = { context, revision, confirmed } }
    if (target === root) data = [competition]
    if (target === sport || target === awards) {
      const financial = target === awards; if (financial) financeReads++
      if (method === 'PATCH') {
        expect(writer && confirmed).toBe(true); expect(request.headers()['x-tenant-revision']).toBe(revision)
        const body = request.postDataJSON(); expect(body.competition_version).toBe(revision); writes++
        const { competition_version: _parent, expected_version: _expected, reason, matches, ...payload } = body
        if (financial) { expect(payload.announced_minor).toBe(100000); expect(payload.received_minor).toBe(12345); expect(payload.currency).toBe('EUR') }
        else { expect(matches).toEqual([{ reference_id: null, match_id: matchId, match_version: revision }]); expect(payload.place).toBe(2); expect(payload).not.toHaveProperty('received_minor') }
        const row: CompetitionOutcome = { version: revision, number: 1, stream: financial ? 'awards' : 'sport', payload, matches: financial ? [] : [{ reference_id: matchId, match: { id: matchId, version: revision, title, status: 'validated' } }], reason, occurred_at: '2026-10-19T10:00:00Z' }
        if (financial) money = row; else current = row
      }
      data = financial ? money : current && { ...current, matches: current.matches.map((l) => ({ ...l, match: writer ? l.match : null })) }
    }
    if (target === sport + '/choices') data = [{ id: matchId, version: revision, title, status: 'validated' }]
    if (target === sport + '/matches/' + matchId + '/result') data = { reference_id: matchId, approved: true, status: 'validated', outcome: 'played', winners: [{ participant_id: id, label: '<img>Équipe A</img>' }], series: { complete: true, winner_ids: [id], standings: [{ participant_id: id, label: '<img>Équipe A</img>', score: 1 }] }, observed_at: '2026-10-19T10:00:00Z' }
    if (target === sport + '/history') data = current ? [current] : []
    if (target === awards + '/history') data = money ? [money] : []
    await route.fulfill({ headers, contentType: 'application/json', body: JSON.stringify({ data, meta: { request_id: 'comp003-e2e', current_page: 1, last_page: 1, total: Array.isArray(data) ? data.length : 0 } }) })
  })
  await page.setViewportSize({ width: 375, height: 812 }); await page.goto('/')
  const competitions = page.getByRole('region', { name: 'Compétitions externes', exact: true })
  await competitions.getByRole('button', { name: 'Ouvrir les compétitions' }).click(); await competitions.getByRole('button', { name: 'Matchs et classement', exact: true }).click()
  const panel = page.getByRole('region', { name: 'Matchs et classement', exact: true }); await expect(panel.getByText('Aucun suivi enregistré.')).toBeVisible(); await panel.getByRole('button', { name: 'Modifier ce suivi' }).click()
  const form = panel.getByRole('form', { name: 'Enregistrer ce suivi' }); await form.getByLabel('Source ou référence du rapport').fill('<script>Rapport officiel</script>'); await form.getByLabel('Date du constat').fill('2026-10-18'); await form.getByLabel('Rang communiqué').fill('2'); await form.getByLabel('Nombre de participants').fill('16')
  await form.getByLabel('Choisir un match accessible').selectOption(matchId); await form.getByRole('button', { name: 'Rattacher ce match' }).click(); await form.getByLabel('Motif du changement').fill('Rapport reçu et classement communiqué.'); await form.getByRole('button', { name: 'Enregistrer ce suivi' }).click(); expect(writes).toBe(0); await page.getByRole('button', { name: 'Confirmer et continuer' }).click(); await expect(form).toHaveCount(0)
  await panel.getByRole('button', { name: 'Consulter le résultat actuel' }).click(); await expect(panel.getByText('Résultat approuvé', { exact: false })).toBeVisible(); await expect(panel.getByText('<img>Équipe A</img> : 1')).toBeVisible(); expect(await panel.locator('script,img,iframe').count()).toBe(0)
  for (const width of [375, 320]) { await page.setViewportSize({ width, height: 812 }); expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true); await panel.screenshot({ path: 'reports/comp003-sport-' + width + '.png' }) }
  await competitions.getByRole('button', { name: 'Dotations de compétition', exact: true }).click(); const financial = page.getByRole('region', { name: 'Dotations de compétition', exact: true }); await expect(financial.getByText('Aucun suivi enregistré.')).toBeVisible(); await financial.getByRole('button', { name: 'Modifier ce suivi' }).click()
  const financialForm = financial.getByRole('form'); await financialForm.getByLabel('Source ou référence du rapport').fill('Relevé privé et annonce de l’organisateur'); await financialForm.getByLabel('Date du constat').fill('2026-10-18'); await financialForm.getByLabel('Dotation annoncée').fill('1000'); await financialForm.getByLabel('Montant réellement reçu').fill('123,45'); await financialForm.getByLabel('Date de réception constatée').fill('2026-10-18'); await financialForm.getByLabel('Motif du changement').fill('Réception partielle vérifiée sur le relevé.'); await financialForm.getByRole('button', { name: 'Enregistrer ce suivi' }).click(); await page.getByRole('button', { name: 'Confirmer et continuer' }).click(); await expect(financialForm).toHaveCount(0); await expect(financial.getByText('Montant réellement reçu : 123,45 EUR')).toBeVisible()
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true); await financial.screenshot({ path: 'reports/comp003-awards-320.png' })
  writer = false; const before = financeReads; await page.reload(); await competitions.getByRole('button', { name: 'Ouvrir les compétitions' }).click(); expect(await competitions.getByRole('button', { name: 'Dotations de compétition', exact: true }).count()).toBe(0); await competitions.getByRole('button', { name: 'Matchs et classement', exact: true }).click(); await expect(panel.getByText('Match à accès restreint')).toBeVisible(); await expect(panel.getByRole('button', { name: 'Modifier ce suivi' })).toHaveCount(0); expect(financeReads).toBe(before)
})
