import { expect, test } from '@playwright/test'
import type { ExternalCompetition, CompetitionDocument, CompetitionRevision } from '../../src/api/externalCompetitions'

test('compétition externe privée : dossier, règlement versionné, téléchargement et archivage sur mobile', async ({ page }) => {
  const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222', revision = '33333333-3333-4333-8333-333333333333'
  const v2 = '44444444-4444-4444-8444-444444444444', v3 = '55555555-5555-4555-8555-555555555555', docId = '66666666-6666-4666-8666-666666666666'
  const root = 'tenants/' + org + '/external-competitions', headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }
  const name = '<script>Compétition privée</script> ' + 'NomLong'.repeat(12), source = '<script>Règlement initial privé</script>'
  let writer = true, confirmed = false, stale = false, writes = 0
  let record: ExternalCompetition | null = null
  const history: CompetitionRevision[] = [], documents: CompetitionDocument[] = []
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request(), url = new URL(request.url()), path = url.pathname.replace('/api/v1/', ''), method = request.method()
    if (method === 'OPTIONS') { await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET, POST, PATCH', 'Access-Control-Allow-Headers': 'Content-Type, X-Tenant-Revision, X-XSRF-TOKEN' } }); return }
    const context = { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'], permissions: ['organization.view', 'sports.read', 'sports.competitions.read', ...(writer ? ['sports.competitions.write'] : [])], is_owner: false, actor_user_id: writer ? 42 : 43, membership_id: id }
    let data: unknown = [], status = 200
    if (path === 'auth/me') data = { id: writer ? 42 : 43, name: writer ? 'Manager' : 'Lecteur', email: 'reader@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (path === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (path.endsWith('/game-profile-catalogue')) data = { games: [] }
    if (path === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: id }]
    if (path === 'active-organization') data = { context, revision, confirmed }
    if (path === 'active-organization/confirmation') { confirmed = true; data = { context, revision, confirmed } }
    if (path === root && method === 'GET') data = record && record.status === (url.searchParams.get('status') ?? 'active') ? [record] : []
    if (path === root + '/games') data = [{ id, label: 'Jeu pilote' }]
    if (path === root + '/' + id + '/history') data = [...history].reverse()
    if (path.startsWith(root) && ['POST', 'PATCH'].includes(method)) {
      expect(writer && confirmed).toBe(true); expect(request.headers()['x-tenant-revision']).toBe(revision); writes++
      const body = request.postDataJSON()
      if (stale) { await route.fulfill({ status: 409, headers, contentType: 'application/json', body: JSON.stringify({ message: 'Le dossier a changé. Rechargez-le.', code: 'EXTERNAL_COMPETITION_VERSION_STALE', errors: {}, meta: { request_id: 'competition-stale' } }) }); return }
      if (path === root) {
        expect(body.reference).toBe('CIRCUIT_2026'); expect(body.game_id).toBe(id); expect(body.contacts[0].email).toBe('private@example.test'); expect(body.visibility).toBeUndefined()
        record = { ...body, id, version: revision, game_key: 'pilot', game_name: 'Jeu pilote', status: 'active', created_at: '2026-10-18T10:00:00Z', updated_at: '2026-10-18T10:00:00Z' }; data = record; status = 201
      } else if (path === root + '/' + id) {
        expect(body.version).toBe(record!.version)
        if (body.archive) { expect(Object.keys(body).sort()).toEqual(['archive', 'reason', 'version']); record = { ...record!, status: 'archived', version: v3 } }
        else record = { ...record!, ...body, version: v2 }
        data = record
      } else if (path.endsWith('/documents') || path.endsWith('/documents/' + docId)) {
        expect(body.competition_version).toBe(record!.version)
        if (method === 'PATCH') expect(body.version).toBe(documents.at(-1)!.version)
        const doc: CompetitionDocument = { ...body, document_id: docId, version: documents.length ? v2 : docId, number: documents.length + 1, kind: 'rules', actor_user_id: 42, occurred_at: '2026-10-18T10:30:00Z' }
        documents.push(doc); data = doc; status = method === 'POST' ? 201 : 200
      }
      if ((path === root || path === root + '/' + id) && record) history.push({ id: record.version, version: record.version, number: history.length + 1, snapshot: record, changed_fields: ['name'], reason: body.reason, actor_user_id: 42, occurred_at: record.updated_at })
    }
    if (path === root + '/' + id + '/documents' && method === 'GET') data = documents.length ? [documents.at(-1)!] : []
    if (path === root + '/' + id + '/documents/' + docId + '/history') data = [...documents].reverse()
    if (path.includes('/versions/') && path.endsWith('/download')) {
      const doc = documents.find((entry) => path.includes('/versions/' + entry.version + '/'))!
      await route.fulfill({ headers, contentType: 'text/plain; charset=UTF-8', body: doc.title + '\n\n' + doc.body }); return
    }
    await route.fulfill({ status, headers, contentType: 'application/json', body: JSON.stringify({ data, meta: { current_page: 1, last_page: 1, total: Array.isArray(data) ? data.length : 0 } }) })
  })
  await page.setViewportSize({ width: 375, height: 812 }); await page.goto('/')
  const panel = page.getByRole('region', { name: 'Compétitions externes', exact: true })
  const open = async () => { await panel.getByRole('button', { name: 'Ouvrir les compétitions' }).click() }
  const confirm = async () => { await page.getByRole('button', { name: 'Confirmer et continuer' }).click() }
  await open(); await panel.getByRole('button', { name: 'Nouvelle compétition externe' }).click()
  const form = panel.getByRole('form', { name: 'Enregistrer le dossier' })
  await form.getByLabel('Référence interne').fill('circuit_2026'); await form.getByLabel('Jeu de la compétition').selectOption(id)
  await form.getByLabel('Nom de la compétition').fill(name); await form.getByLabel('Organisateur externe').fill('Organisateur indépendant')
  await form.getByLabel('Région', { exact: true }).fill('Europe'); await form.getByLabel('Saison', { exact: true }).fill('2026')
  await form.getByRole('button', { name: 'Ajouter un contact' }).click(); await form.getByLabel('Nom du contact').fill('<img>Contact privé</img>'); await form.getByLabel('E-mail du contact').fill('private@example.test')
  await form.getByLabel('Motif du changement').fill('Participation consignée après consultation du règlement.')
  await form.getByRole('button', { name: 'Enregistrer le dossier' }).click(); expect(writes).toBe(0); await confirm(); await expect(form).toHaveCount(0)
  await panel.getByRole('button', { name: 'Documents privés de compétition', exact: true }).click()
  const docs = panel.getByRole('region', { name: 'Documents privés de compétition', exact: true })
  await docs.getByRole('button', { name: 'Nouveau document privé' }).click()
  const documentForm = docs.getByRole('form', { name: 'Enregistrer la version privée' })
  await documentForm.getByLabel('Titre du document').fill('Règlement <img>privé</img>'); await documentForm.getByLabel('Contenu texte du document').fill(source)
  await documentForm.getByLabel('Motif du changement').fill('Version source reçue de l’organisateur.')
  await documentForm.getByRole('button', { name: 'Enregistrer la version privée' }).click(); await confirm(); await expect(documentForm).toHaveCount(0)
  await expect(docs.getByText(source, { exact: true })).toBeVisible()
  await docs.getByRole('button', { name: 'Nouvelle version du document' }).click()
  await documentForm.getByLabel('Contenu texte du document').fill('Règlement révisé et conservé.'); await documentForm.getByLabel('Motif du changement').fill('Une nouvelle version a été communiquée.')
  await documentForm.getByRole('button', { name: 'Enregistrer la version privée' }).click(); await confirm(); await expect(documentForm).toHaveCount(0)
  await docs.getByRole('button', { name: 'Versions du document privé' }).click(); await expect(docs.getByText(source, { exact: true })).toBeVisible()
  const download = page.waitForEvent('download'); await docs.getByRole('region', { name: 'Versions du document privé' }).getByRole('button', { name: 'Télécharger la version texte' }).last().click()
  expect((await download).suggestedFilename()).toBe('competition-document-' + docId + '.txt')
  for (const width of [375, 320]) { await page.setViewportSize({ width, height: 812 }); expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true); await panel.screenshot({ path: 'reports/comp001-' + width + '.png' }) }
  expect(await panel.locator('script,img,iframe').count()).toBe(0)
  await docs.getByRole('button', { name: 'Fermer les documents' }).click(); await panel.getByRole('button', { name: 'Modifier le dossier' }).click()
  await form.getByLabel('Nom de la compétition').fill('Nom concurrent'); await form.getByLabel('Motif du changement').fill('Essai concurrent volontaire pour la recette.')
  stale = true; const before = writes; await form.getByRole('button', { name: 'Enregistrer le dossier' }).click(); await confirm()
  await expect(page.getByText('Le dossier a changé. Rechargez-le.').first()).toBeVisible(); await page.waitForTimeout(300); expect(writes).toBe(before + 1)
  stale = false; await page.reload(); await open(); await panel.getByRole('button', { name: 'Archiver le dossier' }).click()
  const archive = panel.getByRole('form', { name: 'Archiver le dossier' }); await archive.getByLabel('Motif du changement').fill('Fin de participation, conservation des documents privés.')
  await archive.getByRole('button', { name: 'Archiver le dossier' }).click(); await confirm(); await expect(archive).toHaveCount(0)
  writer = false; await page.reload(); await open(); const filters = panel.getByRole('form', { name: 'Filtres des compétitions' }); await filters.getByLabel('État du dossier').selectOption('archived'); await filters.getByRole('button', { name: 'Appliquer les filtres' }).click()
  await expect(panel.getByText(name, { exact: true })).toBeVisible(); await expect(panel.getByRole('button', { name: 'Modifier le dossier' })).toHaveCount(0)
  await panel.getByRole('button', { name: 'Documents privés de compétition', exact: true }).click(); await expect(docs.getByText('Règlement révisé et conservé.', { exact: true })).toBeVisible()
  await expect(docs.getByRole('button', { name: 'Nouveau document privé' })).toHaveCount(0)
})
