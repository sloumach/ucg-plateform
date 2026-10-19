import { expect, test } from '@playwright/test'
import type { CompetitionRegistration } from '../../src/api/competitionRegistrations'

test('participation externe : roster, pièce, échéance UTC, historique et lecteur sur mobile', async ({ page }) => {
  const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222', revision = '33333333-3333-4333-8333-333333333333'
  const player = '44444444-4444-4444-8444-444444444444', manager = '55555555-5555-4555-8555-555555555555', docVersion = '66666666-6666-4666-8666-666666666666'
  const root = 'tenants/' + org + '/external-competitions', path = root + '/' + id + '/registration', headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }
  const name = '<script>Pièce privée</script> ' + 'IntituléLong'.repeat(8), playerName = '<img>Joueur privé</img>'
  let current: CompetitionRegistration | null = null, writer = true, confirmed = false, writes = 0
  const history: CompetitionRegistration[] = []
  const competition = { id, reference: 'EXTERNAL_2026', game_id: id, game_key: 'pilot', game_name: 'Jeu pilote', name: 'Compétition externe privée', organizer: 'Organisateur', region: 'Europe', season: '2026', contacts: [], website_url: null, version: revision, status: 'active', created_at: '2026-10-18T10:00:00Z', updated_at: '2026-10-18T10:00:00Z' }
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request(), method = request.method(), target = new URL(request.url()).pathname.replace('/api/v1/', '')
    if (method === 'OPTIONS') { await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET, POST, PATCH', 'Access-Control-Allow-Headers': 'Content-Type, X-Tenant-Revision, X-XSRF-TOKEN' } }); return }
    const context = { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'], permissions: ['organization.view', 'sports.read', 'sports.competitions.read', 'sports.registrations.read', ...(writer ? ['sports.registrations.write'] : [])], is_owner: false, actor_user_id: writer ? 42 : 43, membership_id: id }
    let data: unknown = []
    if (target === 'auth/me') data = { id: writer ? 42 : 43, name: writer ? 'Manager' : 'Lecteur', email: 'manager@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (target === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (target.endsWith('/game-profile-catalogue')) data = { games: [] }
    if (target === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: id }]
    if (target === 'active-organization') data = { context, revision, confirmed }
    if (target === 'active-organization/confirmation') { confirmed = true; data = { context, revision, confirmed } }
    if (target === root) data = [competition]
    if (target === path) {
      if (method === 'PATCH') {
        expect(writer && confirmed).toBe(true); expect(request.headers()['x-tenant-revision']).toBe(revision)
        const body = request.postDataJSON(); expect(body.competition_version).toBe(revision); expect(body.expected_version).toBe(current?.version ?? null)
        expect(body.roster[0].person_id).toBe(player); expect(body.roster[0].label).toBeUndefined(); expect(body.items[0].responsible_person_id).toBe(manager)
        expect(body.items[0].due_at).toBe('2026-10-28T17:30:15+00:00'); expect(body.items[0].document_version_id).toBe(docVersion); writes++
        const { competition_version: _parent, expected_version: _expected, reason, ...payload } = body
        payload.roster = payload.roster.map((entry: object) => ({ ...entry, label: playerName })); payload.items = payload.items.map((entry: object) => ({ ...entry, responsible_label: 'Manager privé' }))
        current = { version: writes === 1 ? revision : docVersion, number: writes, payload, reason, actor_user_id: 42, occurred_at: '2026-10-19T10:00:00Z', summary: { pending: body.items[0].status === 'pending' ? 1 : 0, due_soon: 0, overdue: 0 } }; history.push(current)
      }
      data = current
    }
    if (target === path + '/people') data = [{ id: player, label: playerName }, { id: manager, label: 'Manager privé' }]
    if (target === root + '/' + id + '/documents') data = [{ document_id: docVersion, version: docVersion, number: 1, kind: 'note', title: 'Note privée conservée', body: 'Référence de pièce reçue.', source_url: null, reason: 'Conservation du document source.', actor_user_id: 42, occurred_at: '2026-10-18T10:00:00Z' }]
    if (target === path + '/history') data = [...history].reverse()
    if (target === path + '/reminders') data = writer && current ? [{ id: docVersion, item_id: current.payload.items[0].id, stage: 'week', payload: { title: name, due_at: '2026-10-28T17:30:15+00:00', responsible_person_id: manager }, occurred_at: '2026-10-21T10:00:00Z' }] : []
    await route.fulfill({ headers, contentType: 'application/json', body: JSON.stringify({ data, meta: { request_id: 'registration-e2e', current_page: 1, last_page: 1, total: Array.isArray(data) ? data.length : 0 } }) })
  })
  await page.setViewportSize({ width: 375, height: 812 }); await page.goto('/')
  const competitions = page.getByRole('region', { name: 'Compétitions externes', exact: true })
  const open = async () => { await competitions.getByRole('button', { name: 'Ouvrir les compétitions' }).click(); await competitions.getByRole('button', { name: 'Suivi de participation', exact: true }).click() }
  await open(); const panel = page.getByRole('region', { name: 'Suivi de participation', exact: true })
  await expect(panel.getByText('Aucun suivi de participation enregistré.')).toBeVisible(); await panel.getByRole('button', { name: 'Modifier le suivi de participation' }).click()
  const form = panel.getByRole('form', { name: 'Enregistrer le suivi de participation' })
  await form.getByLabel('Paiement déclaré').selectOption('pending'); await form.getByLabel('Exigences d’âge de l’organisateur').fill('Exigence issue de la source, à vérifier manuellement.')
  await form.getByRole('button', { name: 'Ajouter un joueur' }).click(); await form.getByLabel('Joueur', { exact: true }).selectOption(player)
  await form.getByRole('button', { name: 'Ajouter un élément de suivi' }).click(); await form.getByLabel('Type d’élément').selectOption('document')
  await form.getByLabel('Intitulé de l’élément').fill(name); await form.getByLabel('Échéance en UTC').fill('2026-10-28T17:30:15')
  await form.getByLabel('Responsable', { exact: true }).selectOption(manager); await form.getByLabel('Version documentaire privée').selectOption(docVersion)
  await form.getByLabel('Motif du changement').fill('Préparation du roster et de la pièce demandée.')
  await form.getByRole('button', { name: 'Enregistrer le suivi de participation' }).click(); expect(writes).toBe(0); await page.getByRole('button', { name: 'Confirmer et continuer' }).click(); await expect(form).toHaveCount(0)
  await expect(panel.getByText(name, { exact: true })).toBeVisible(); expect(await panel.locator('script,img,iframe').count()).toBe(0)
  for (const width of [375, 320]) { await page.setViewportSize({ width, height: 812 }); expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true); await panel.screenshot({ path: 'reports/comp002-' + width + '.png' }) }
  await panel.getByRole('button', { name: 'Modifier le suivi de participation' }).click(); await form.getByLabel('État de l’élément').selectOption('done'); await form.getByLabel('Motif du changement').fill('La pièce a été remise à l’organisateur externe.')
  await form.getByRole('button', { name: 'Enregistrer le suivi de participation' }).click(); await page.getByRole('button', { name: 'Confirmer et continuer' }).click(); await expect(form).toHaveCount(0)
  await panel.getByRole('button', { name: 'Historique du suivi de participation' }).click(); await expect(panel.getByRole('region', { name: 'Historique du suivi de participation' }).getByText('Pièce demandée · À faire / manquant', { exact: true })).toBeVisible()
  writer = false; await page.reload(); await open(); await expect(panel.getByRole('button', { name: 'Modifier le suivi de participation' })).toHaveCount(0); await expect(panel.getByText('Fait / remis', { exact: false })).toBeVisible()
})
