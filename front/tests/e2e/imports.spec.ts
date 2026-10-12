import { expect, test } from '@playwright/test'

test('import CSV : dépôt privé confirmé, mapping, erreurs, création et rapport sur mobile', async ({ page }) => {
  const org = '11111111-1111-4111-8111-111111111111', revision = '22222222-2222-4222-8222-222222222222', id = '33333333-3333-4333-8333-333333333333'
  let confirmed = false, uploaded = false, imported = false
  let run: Record<string, unknown> = { id, version: revision, status: 'uploaded', headers: ['pseudo', 'email'], mapping: {}, defaults: {}, row_count: 2, counts: {}, failure_code: null, rows: [], pagination: { current_page: 1, last_page: 1, total: 0 } }
  const headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const path = new URL(route.request().url()).pathname.replace('/api/v1/', ''), method = route.request().method()
    if (method === 'OPTIONS') { await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET, POST, PATCH', 'Access-Control-Allow-Headers': 'Content-Type, X-Tenant-Revision, X-XSRF-TOKEN' } }); return }
    let data: unknown = []
    const meta = { request_id: 'hum005-browser', current_page: 1, last_page: 1, total: 0 }
    if (path === 'auth/me') data = { id: 42, name: 'Manager', email: 'manager@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (path === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (path === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: 'membership' }]
    if (path === 'active-organization/confirmation') confirmed = true
    if (path === 'active-organization' || path === 'active-organization/confirmation') data = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'], permissions: ['organization.view', 'sports.read', 'sports.write', 'administrative.read', 'administrative.write'], is_owner: false, actor_user_id: 42, membership_id: 'membership' }, revision, confirmed }
    if (path.endsWith('/game-profile-catalogue')) data = { games: [] }
    const root = 'tenants/' + org + '/people-imports'
    if (path === root) {
      if (method === 'POST') {
        expect(confirmed).toBe(true); expect(imported).toBe(false)
        expect(route.request().headers()['content-type']).toMatch(/^multipart\/form-data; boundary=/)
        expect(route.request().postData()).toContain('filename="people.csv"')
        expect(route.request().postData()).toContain('Alex,alex@example.test')
        expect(route.request().headers()['x-tenant-revision']).toBe(revision)
        uploaded = true; data = run
      } else data = uploaded ? [run] : []
    }
    if (path === root + '/' + id) data = run
    if (path.endsWith('/preview')) {
      expect(imported).toBe(false)
      const body = route.request().postDataJSON()
      expect(body.mapping).toEqual({ pseudonym: 0, contact_email: 1 })
      expect(body.defaults).toEqual({ language: 'fr', timezone: 'UTC', status: 'active' })
      run = { ...run, version: '44444444-4444-4444-8444-444444444444', status: 'previewed', mapping: body.mapping, defaults: body.defaults, counts: { ready: 1, invalid: 1 }, rows: [
        { id: 'row1', line: 2, status: 'ready', values: { pseudonym: 'Alex', contact_email: 'alex@example.test' }, errors: [], person_id: null },
        { id: 'row2', line: 3, status: 'invalid', values: { pseudonym: '<script>BAD</script>', contact_email: 'invalid' }, errors: ['INVALID_CONTACT_EMAIL'], person_id: null },
      ], pagination: { current_page: 1, last_page: 1, total: 2 } }
      data = run
    }
    if (path.endsWith('/confirm')) {
      const body = route.request().postDataJSON()
      expect(body).toEqual({ version: '44444444-4444-4444-8444-444444444444', confirm: true, acknowledge_rejected: true })
      imported = true
      run = { ...run, status: 'completed', counts: { created: 1, invalid: 1 }, rows: (run.rows as Record<string, unknown>[]).map((row) => row.status === 'ready' ? { ...row, status: 'created', person_id: 'person-uuid' } : row) }; data = run
    }
    if (path.endsWith('/report')) { await route.fulfill({ headers: { ...headers, 'Content-Type': 'text/csv' }, body: 'line,status,errors,person_id\r\n2,created,,person-uuid\r\n3,invalid,INVALID_CONTACT_EMAIL,\r\n' }); return }
    await route.fulfill({ headers, json: { data, meta, ...(method === 'POST' ? { notification: { type: 'success', message: 'Étape d’import enregistrée.' } } : {}) } })
  })
  await page.goto('/')
  await page.getByRole('button', { name: 'Importer des personnes par CSV' }).click()
  const upload = page.getByRole('form', { name: 'Déposer le CSV privé' })
  await upload.getByLabel('Fichier CSV').setInputFiles({ name: 'people.csv', mimeType: 'text/csv', buffer: Buffer.from('pseudo,email\nAlex,alex@example.test\n<script>BAD</script>,invalid') })
  await upload.getByRole('button', { name: 'Déposer le CSV privé' }).click()
  expect(uploaded).toBe(false)
  const approve = async () => {
    const region = page.getByRole('region', { name: 'Confirmer l’action' })
    await expect(region).toContainText('Alpha')
    await region.getByRole('button', { name: 'Confirmer et continuer' }).click()
  }
  await approve()
  const mapping = page.getByRole('form', { name: 'Correspondance des colonnes' })
  await mapping.getByLabel('E-mail de contact').selectOption('1')
  await mapping.getByRole('button', { name: 'Prévisualiser sans créer' }).click(); await approve()
  await expect(page.getByText('<script>BAD</script>', { exact: true })).toBeVisible()
  await expect(page.getByText('Valeur invalide : E-mail de contact.')).toBeVisible()
  expect(imported).toBe(false)
  const confirmation = page.getByRole('form', { name: 'Confirmer l’import' })
  await confirmation.getByLabel('Je confirme la création des lignes prêtes dans cette organisation.').check()
  await confirmation.getByLabel('J’ai vérifié les lignes refusées ou en doublon ; elles ne seront pas importées.').check()
  await confirmation.getByRole('button', { name: 'Confirmer l’import' }).click(); await approve()
  await expect(page.getByRole('button', { name: 'Confirmer l’import' })).toHaveCount(0)
  const downloaded = page.waitForEvent('download')
  await page.getByRole('button', { name: 'Télécharger le rapport sans données privées' }).click()
  expect((await downloaded).suggestedFilename()).toBe('people-import-report.csv')
  for (const button of await page.getByRole('button', { name: 'Fermer la notification', exact: true }).all()) await button.click()
  const panel = page.getByRole('region', { name: 'Importer des personnes par CSV', exact: true })
  for (const width of [375, 320]) {
    await page.setViewportSize({ width, height: 812 })
    await expect(panel).toBeVisible()
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
  }
  await panel.screenshot({ path: 'reports/hum005-import-mobile.png' })
})
