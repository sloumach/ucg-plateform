import { expect, test, type WebSocketRoute } from '@playwright/test'
import type { MatchRecord, MatchLiveScore } from '../../src/api/matches'

test('direct privé : abonnement, correction, doublons et révocation sur mobile', async ({ page }) => {
  const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222', revision = '33333333-3333-4333-8333-333333333333'
  const a = '44444444-4444-4444-8444-444444444444', b = '55555555-5555-4555-8555-555555555555'
  const root = 'tenants/' + org + '/matches', channel = 'private-tenants.' + org + '.matches.' + id
  const headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }
  const record: MatchRecord = { id, version: revision, title: '<script>Scrim privé</script> ' + 'NomLong'.repeat(12), kind: 'scrim', format: 'bo1', status: 'validated', visibility: 'private', game_id: id, game_key: 'pilot', game_name: 'Jeu pilote',
    starts_at: '2026-10-19T10:00:00Z', ends_at: '2026-10-19T12:00:00Z', starts_at_origin: '2026-10-19T10:00:00+00:00', ends_at_origin: '2026-10-19T12:00:00+00:00', origin_timezone: 'UTC', responsible_person_id: a, responsible_label: 'Alex', editable: false, available_transitions: [],
    participants: [{ id: a, kind: 'person', source_id: a, label: 'Alex', reference_version: revision, slot: 1 }, { id: b, kind: 'person', source_id: b, label: 'Robin', reference_version: revision, slot: 2 }] }
  let pending = false, corrected = false, revoked = false, reads = 0, authorizations = 0, unsubscriptions = 0
  let socket: WebSocketRoute | undefined
  await page.routeWebSocket('**/app/test-key**', (ws) => {
    socket = ws
    ws.onMessage((raw) => {
      const message = JSON.parse(String(raw))
      if (message.event === 'pusher:subscribe') {
        expect(message.data.channel).toBe(channel)
        expect(message.data.auth).toBe('test-key:signature')
        ws.send(JSON.stringify({ event: 'pusher_internal:subscription_succeeded', channel, data: '{}' }))
      }
      if (message.event === 'pusher:unsubscribe') unsubscriptions++
      if (message.event === 'pusher:ping') ws.send(JSON.stringify({ event: 'pusher:pong', data: '{}' }))
    })
    ws.send(JSON.stringify({ event: 'pusher:connection_established', data: JSON.stringify({ socket_id: '123.456', activity_timeout: 120 }) }))
  })
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request(), url = new URL(request.url()), path = url.pathname.replace('/api/v1/', '')
    if (request.method() === 'OPTIONS') { await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET, POST', 'Access-Control-Allow-Headers': 'Content-Type, X-Tenant-Revision, X-XSRF-TOKEN' } }); return }
    let data: unknown = []
    if (path === 'auth/me') data = { id: 42, name: 'Lecteur Match', email: 'reader@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (path === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (path.endsWith('/game-profile-catalogue')) data = { games: [] }
    if (path === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: id }]
    if (path === 'active-organization') data = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'], permissions: revoked ? ['organization.view'] : ['organization.view', 'sports.read'], is_owner: false, actor_user_id: 42, membership_id: id }, revision, confirmed: true }
    if (path === root) data = [record]
    if (path === root + '/' + id + '/realtime/auth') {
      authorizations++
      expect(request.headers()['x-tenant-revision']).toBe(revision)
      expect(request.postDataJSON()).toEqual({ socket_id: '123.456', channel_name: channel })
      await route.fulfill({ headers, contentType: 'application/json', body: JSON.stringify({ auth: 'test-key:signature' }) }); return
    }
    if (path === root + '/' + id + '/live') {
      reads++
      if (revoked) { await route.fulfill({ status: 403, headers, contentType: 'application/json', body: JSON.stringify({ message: 'Accès révoqué.', code: 'FORBIDDEN', meta: { request_id: 'live-revoked' } }) }); return }
      data = { match_version: revision, score_revision: corrected || pending ? a : null, recalculation_pending: pending, status: corrected || pending ? 'disputed' : 'validated', outcome: corrected || pending ? null : 'played', winner_ids: corrected || pending ? [] : [a], observed_at: new Date().toISOString(),
        series: pending ? null : { complete: true, draw: false, played: 1, winner_ids: [corrected ? b : a], standings: [{ participant_id: a, score: corrected ? 0 : 1 }, { participant_id: b, score: corrected ? 1 : 0 }] } } satisfies MatchLiveScore
    }
    await route.fulfill({ headers, contentType: 'application/json', body: JSON.stringify({ data, meta: { current_page: 1, last_page: 1, total: Array.isArray(data) ? data.length : 0 } }) })
  })
  await page.setViewportSize({ width: 375, height: 812 }); await page.goto('/')
  const panel = page.getByRole('region', { name: 'Scrims et matchs' })
  await panel.getByRole('button', { name: 'Ouvrir les matchs' }).click()
  await panel.getByRole('button', { name: 'Scores en direct', exact: true }).click()
  const live = page.getByRole('region', { name: 'Scores en direct', exact: true })
  await expect(live.getByText('Direct connecté.')).toBeVisible()
  await expect(live.getByText('Alex : 1', { exact: true })).toBeVisible()
  expect(authorizations).toBe(1)
  const emit = (eventId: string, tenant = org) => socket!.send(JSON.stringify({ event: 'match.score.changed', channel, data: JSON.stringify({ event_id: eventId, organization_id: tenant, match_id: id, changed_at: new Date().toISOString() }) }))
  pending = true; emit('correction-pending')
  await expect(live.getByText(/Recalcul en attente/)).toBeVisible()
  await expect(live.getByText('Alex : 1', { exact: true })).toHaveCount(0)
  pending = false; corrected = true; emit('correction-complete')
  await expect(live.getByText('Alex : 0', { exact: true })).toBeVisible()
  await expect(live.getByText(/Scores provisoires/)).toBeVisible()
  const count = reads
  emit('correction-complete'); emit('foreign', a)
  await page.waitForTimeout(500)
  expect(reads).toBe(count)
  for (const width of [375, 320]) {
    await page.setViewportSize({ width, height: 812 })
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
    await live.screenshot({ path: 'reports/match007-' + width + '.png' })
  }
  expect(await live.locator('script,img,iframe').count()).toBe(0)
  revoked = true; emit('revoked')
  await expect(page.getByText('Alex : 0', { exact: true })).toHaveCount(0)
  await expect(page.getByRole('region', { name: 'Scrims et matchs' })).toHaveCount(0)
  await expect.poll(() => unsubscriptions).toBe(1)
})
