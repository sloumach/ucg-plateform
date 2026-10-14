import { expect, test } from '@playwright/test'

test('références externes : capture conservée après renommage et archivage, sur mobile', async ({ page }) => {
  const org = '11111111-1111-4111-8111-111111111111', revision = '22222222-2222-4222-8222-222222222222'
  const id = '33333333-3333-4333-8333-333333333333'
  const name = '<script>OPPONENT_BAD</script>' + 'LongNomPublic'.repeat(5)
  const sourceLabel = 'Liste publique ' + 'SourceLongue'.repeat(8)
  const contextLabel = 'Compétition externe ' + 'ContexteLong'.repeat(6)
  let confirmed = false, writes = 0
  let opponent: Record<string, unknown> | null = null
  const snapshots: Record<string, unknown>[] = []
  const errors: string[] = []
  page.on('pageerror', (error) => errors.push(error.message))
  page.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()) })
  const headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request(), url = new URL(request.url()), path = url.pathname.replace('/api/v1/', ''), method = request.method()
    if (method === 'OPTIONS') {
      await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET, POST, PATCH', 'Access-Control-Allow-Headers': 'Content-Type, X-Tenant-Revision, X-XSRF-TOKEN' } }); return
    }
    let data: unknown = []
    const meta = { request_id: 'team006-browser', current_page: 1, last_page: 1, total: 1 }
    if (path === 'auth/me') data = { id: 42, name: 'Manager', email: 'manager@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (path === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (path === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: id }]
    if (path === 'active-organization/confirmation') confirmed = true
    if (path === 'active-organization' || path === 'active-organization/confirmation') data = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'], permissions: ['organization.view', 'sports.read', 'teams.manage'], is_owner: false, actor_user_id: 42, membership_id: id }, revision, confirmed }
    if (path.endsWith('/game-profile-catalogue')) data = { games: [] }
    if (path.endsWith('/games')) data = [{ id, name: 'VALORANT', enabled: true }, { id: revision, name: 'Jeu désactivé', enabled: false }]
    const root = 'tenants/' + org + '/external-opponents'
    if (path === root && method === 'POST') {
      expect(confirmed).toBe(true); expect(request.headers()['x-tenant-revision']).toBe(revision)
      const body = request.postDataJSON()
      expect(body).toMatchObject({ key: 'adversaire-public', kind: 'team', name, confirm_public_data: true })
      expect(body.organization_id).toBeUndefined(); expect(body.user_id).toBeUndefined()
      opponent = { ...body, id, game_key: 'valorant', game_name: 'VALORANT', version: revision, status: 'active', publication_status: 'private' }; data = opponent; writes++
    } else if (path === root) data = opponent ? [opponent] : []
    if (path === root + '/' + id && method === 'PATCH') {
      const body = request.postDataJSON()
      expect(body).toMatchObject({ version: opponent?.version, confirm_public_data: true })
      expect(body.observed_at).toBeUndefined(); expect(body.game_id).toBeUndefined(); expect(body.kind).toBeUndefined()
      opponent = { ...opponent, ...body, version: '44444444-4444-4444-8444-444444444444' }; data = opponent; writes++
    }
    if (path === root + '/' + id + '/snapshots' && method === 'POST') {
      const body = request.postDataJSON()
      expect(body).toEqual({ version: opponent?.version, context_label: contextLabel, confirm_public_data: true })
      snapshots.push({ ...opponent, id: revision, opponent_id: id, opponent_version: opponent?.version, context_label: contextLabel,
        captured_at: '2026-10-14T12:00:00+00:00', created_by: 42 })
      data = snapshots[0]; writes++
    } else if (path.endsWith('/snapshots')) data = snapshots
    await route.fulfill({ headers, json: { data, meta, ...(method !== 'GET' ? { notification: { type: 'success', message: 'Référence enregistrée.' } } : {}) } })
  })
  const mobile = async () => {
    for (const width of [375, 320]) {
      await page.setViewportSize({ width, height: 812 })
      await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
    }
  }
  const approve = async () => {
    const confirmation = page.getByRole('region', { name: 'Confirmer l’action' })
    await expect(confirmation).toContainText('Alpha')
    await mobile()
    await confirmation.getByRole('button', { name: 'Confirmer et continuer' }).click()
  }
  await page.goto('/')
  await page.getByRole('button', { name: 'Adversaires et organisations externes' }).click()
  const panel = page.getByRole('region', { name: 'Adversaires et organisations externes', exact: true })
  await panel.getByRole('button', { name: 'Ajouter une référence externe' }).click()
  const form = panel.getByRole('form', { name: 'Enregistrer la référence externe' })
  await form.getByLabel('Clé stable').fill('adversaire-public')
  await form.getByLabel('Jeu associé').selectOption(id)
  await form.getByLabel('Nom public ou pseudonyme').fill(name)
  await form.getByLabel('Source des informations').fill(sourceLabel)
  await form.getByLabel('URL publique de la source (HTTPS)').fill('https://example.test/' + 'source'.repeat(40))
  await form.getByLabel('Date d’observation des informations').fill('2026-01-01T00:00')
  await form.getByLabel('Je confirme que ces informations sont publiques et autorisées pour ce suivi.').check()
  await mobile()
  await form.getByRole('button', { name: 'Enregistrer la référence externe' }).click()
  expect(writes).toBe(0); await approve()
  await expect(panel.getByRole('heading', { name, exact: true })).toBeVisible()
  await expect(page.locator('script').filter({ hasText: 'OPPONENT_BAD' })).toHaveCount(0)
  await panel.getByRole('button', { name: 'Instantanés conservés' }).click()
  const history = panel.getByRole('region', { name: 'Instantanés conservés', exact: true })
  await expect(history.getByText('Aucun instantané conservé pour cette référence.')).toBeVisible()
  const capture = history.getByRole('form', { name: 'Conserver un instantané' })
  await capture.getByLabel('Libellé du contexte sportif').fill(contextLabel)
  await capture.getByLabel('Je confirme que ces informations sont publiques et autorisées pour ce suivi.').check()
  await capture.getByRole('button', { name: 'Conserver un instantané' }).click(); await approve()
  await expect(history.getByRole('heading', { name: contextLabel + ' · ' + name, exact: true })).toBeVisible()
  for (const button of await page.getByRole('button', { name: 'Fermer la notification', exact: true }).all()) await button.click()
  await mobile()
  await history.screenshot({ path: 'reports/team006-snapshot-mobile.png', style: 'header { visibility: hidden }' })
  await history.getByRole('button', { name: 'Fermer les instantanés' }).click()
  await panel.getByRole('button', { name: 'Modifier la référence externe' }).click()
  await form.getByLabel('Nom public ou pseudonyme').fill('Adversaire renommé')
  await form.getByLabel('Je confirme que ces informations sont publiques et autorisées pour ce suivi.').check()
  await form.getByRole('button', { name: 'Enregistrer la référence externe' }).click(); await approve()
  await expect(panel.getByRole('heading', { name: 'Adversaire renommé', exact: true })).toBeVisible()
  await panel.getByRole('button', { name: 'Archiver la référence externe' }).click(); await approve()
  await expect(panel.getByRole('button', { name: 'Modifier la référence externe' })).toHaveCount(0)
  await panel.getByRole('button', { name: 'Instantanés conservés' }).click()
  await expect(history.getByRole('heading', { name: contextLabel + ' · ' + name, exact: true })).toBeVisible()
  await expect(history.getByRole('button', { name: 'Conserver un instantané' })).toHaveCount(0)
  for (const button of await page.getByRole('button', { name: 'Fermer la notification', exact: true }).all()) await button.click()
  await page.addStyleTag({ content: '* { font-family: Georgia, serif !important }' })
  await mobile()
  await panel.screenshot({ path: 'reports/team006-archive-mobile.png', style: 'header { visibility: hidden }' })
  expect(writes).toBe(4); expect(errors).toEqual([])
})
