import { createHash } from 'node:crypto'
import { expect, test } from '@playwright/test'

test('documents privés : versions conservées, copie signée figée et avenant lié sur mobile', async ({ page }) => {
  const org = '11111111-1111-4111-8111-111111111111', revision = '22222222-2222-4222-8222-222222222222'
  const id = '33333333-3333-4333-8333-333333333333', person = '44444444-4444-4444-8444-444444444444'
  const reference = 'contrat-documentaire', rootTitle = 'Original <script>DOC_BAD</script> ' + 'DocumentConfidentielLong'.repeat(4)
  let confirmed = false, writes = 0
  let record: Record<string, unknown> = { id, reference, type: 'player', title: 'Dossier documentaire', responsible_person_id: person, responsible_name: 'Camille',
    starts_on: '2026-01-01', ends_on: '2027-12-31', status: 'draft', version: revision, prepared_by: 42, approved_by: null, approved_at: null,
    signed_on: null, signature_reference: null, signature_recorded_at: null, terminated_on: null, signature_evidence_status: 'not_declared',
    in_force: false, sporting_eligibility: 'not_assessed', publication_status: 'private', parties_count: 1, obligations_count: 0,
    pending_obligations_count: 0, available_transitions: ['in_review', 'archived'], parties: [{ id: person, kind: 'external', person_id: null, name: 'Partie privée', position: 1 }], obligations: [] }
  const documents: Record<string, unknown>[] = [], versions: Record<string, unknown>[] = [], errors: string[] = []
  const files: Record<string, string> = { 'preparation-1.pdf': '%PDF-1.4\n% PREPARATION 1\n%%EOF\n', 'preparation-2.pdf': '%PDF-1.4\n% PREPARATION 2\n%%EOF\n',
    'copie-signee.pdf': '%PDF-1.4\n% COPIE SIGNEE\n%%EOF\n', 'avenant.pdf': '%PDF-1.4\n% AVENANT\n%%EOF\n' }
  const headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }
  page.on('pageerror', (error) => errors.push(error.message))
  page.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()) })
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request(), url = new URL(request.url()), path = url.pathname.replace('/api/v1/', ''), method = request.method()
    if (method === 'OPTIONS') { await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET, POST, PATCH', 'Access-Control-Allow-Headers': 'Content-Type, X-Tenant-Revision, X-XSRF-TOKEN' } }); return }
    let data: unknown = []
    const meta = { request_id: 'ctr002-browser', current_page: 1, last_page: 1, total: 1, contract_version: String(record.version) }
    if (path === 'auth/me') data = { id: 43, name: 'Validateur', email: 'staff@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (path === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (path === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: id }]
    if (path === 'active-organization/confirmation') confirmed = true
    if (path === 'active-organization' || path === 'active-organization/confirmation') data = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'],
      permissions: ['organization.view', 'administrative.read', 'administrative.write', 'administrative.validate'], actor_user_id: 43, is_owner: false, membership_id: id }, revision, confirmed }
    const base = 'tenants/' + org + '/contracts', docs = base + '/' + id + '/documents'
    if (path === base) data = [record]
    if (path === base + '/' + id) data = record
    if (path === docs && method === 'GET') data = documents
    if (path === docs && method === 'POST') {
      expect(confirmed).toBe(true); expect(request.headers()['x-tenant-revision']).toBe(revision)
      const body = request.postDataJSON()
      expect(body.contract_version).toBe(record.version)
      expect(body.organization_id).toBeUndefined(); expect(body.amount).toBeUndefined()
      if (body.kind === 'amendment') expect(body.original_document_id).toBe(documents[0].id)
      writes++; record = { ...record, version: '99999999-9999-4999-8999-' + String(writes).padStart(12, '0') }
      const doc = { id: '66666666-6666-4666-8666-' + String(writes).padStart(12, '0'), reference: body.reference, kind: body.kind, title: body.title,
        original_document_id: body.original_document_id ?? null, effective_on: body.effective_on ?? null, versions_count: 0, signed_version: null,
        created_by: 43, created_at: '2026-10-14T12:00:00+00:00', signature_evidence_status: 'not_declared', publication_status: 'private' }
      documents.push(doc); data = doc
    }
    const matched = path.match(/\/documents\/([^/]+)\/(versions|signature)$/)
    if (matched) {
      const document = documents.find((item) => item.id === matched[1])!
      if (matched[2] === 'versions' && method === 'GET') data = versions.filter((item) => item.document_id === document.id).toReversed()
      if (matched[2] === 'versions' && method === 'POST') {
        expect(confirmed).toBe(true); expect(request.headers()['x-tenant-revision']).toBe(revision)
        expect(request.headers()['content-type']).toContain('multipart/form-data; boundary=')
        const body = request.postData()!, filename = body.match(/filename="([^"]+)"/)![1]
        expect(body).toContain(files[filename]); expect(body).not.toContain('name="sha256"')
        expect(body.match(/name="contract_version"\r\n\r\n([^\r\n]+)/)![1]).toBe(record.version)
        const purpose = body.match(/name="purpose"\r\n\r\n([^\r\n]+)/)![1]
        expect(document.signed_version).toBeNull()
        writes++; record = { ...record, version: '99999999-9999-4999-8999-' + String(writes).padStart(12, '0') }
        document.versions_count = Number(document.versions_count) + 1
        const version = { id: '77777777-7777-4777-8777-' + String(writes).padStart(12, '0'), document_id: document.id, number: document.versions_count,
          purpose, filename, sha256: createHash('sha256').update(files[filename]).digest('hex'), bytes: files[filename].length,
          created_by: 43, created_at: '2026-10-14T12:00:00+00:00', scan_status: 'pending', download_available: false, publication_status: 'private' }
        versions.push(version); data = version
      }
      if (matched[2] === 'signature' && method === 'POST') {
        const body = request.postDataJSON()
        expect(confirmed).toBe(true); expect(body.contract_version).toBe(record.version); expect(body.confirm_signed_copy).toBe(true)
        const selected = versions.find((item) => item.id === body.document_version_id)!
        expect(selected.purpose).toBe('signed_copy'); expect(selected.document_id).toBe(document.id)
        writes++; record = { ...record, version: '99999999-9999-4999-8999-' + String(writes).padStart(12, '0') }
        document.signed_version = { id: selected.id, number: selected.number, sha256: selected.sha256, signed_on: body.signed_on, recorded_by: 43, recorded_at: '2026-10-14T12:00:00+00:00' }
        document.signature_evidence_status = 'declared_external'; data = document
      }
    }
    meta.contract_version = String(record.version)
    await route.fulfill({ status: method === 'POST' && !path.endsWith('/signature') && path.includes('/documents') ? 201 : 200, headers,
      json: { data, meta, ...(method !== 'GET' ? { notification: { type: 'success', message: 'Document conservé.' } } : {}) } })
  })
  const mobile = async () => {
    for (const width of [375, 320]) {
      await page.setViewportSize({ width, height: 812 })
      await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
    }
  }
  const approve = async () => {
    const confirmation = page.getByRole('region', { name: 'Confirmer l’action' })
    await expect(confirmation).toContainText('Alpha'); await mobile()
    await confirmation.getByRole('button', { name: 'Confirmer et continuer' }).click()
  }
  const openDocuments = async () => {
    await page.getByRole('button', { name: 'Contrats de l’organisation' }).click()
    await page.getByRole('button', { name: 'Consulter le dossier' }).click()
    await page.getByRole('button', { name: 'Documents et avenants' }).click()
  }
  await page.goto('/'); await openDocuments()
  let panel = page.getByRole('region', { name: 'Documents et avenants', exact: true })
  await panel.getByRole('button', { name: 'Ajouter un document ou un avenant' }).click()
  let form = panel.getByRole('form', { name: 'Ajouter un document ou un avenant' })
  await form.getByLabel('Référence du document').fill('original'); await form.getByLabel('Intitulé du document').fill(rootTitle)
  await form.getByRole('button', { name: 'Ajouter un document ou un avenant' }).click(); await approve()
  await panel.getByRole('button', { name: 'Consulter les versions · original' }).click()
  const upload = async (filename: string, number: number) => {
    const form = panel.getByRole('form', { name: 'Conserver une nouvelle version' })
    await form.getByLabel('Fichier PDF privé (5 Mo maximum)').setInputFiles({ name: filename, mimeType: 'application/pdf', buffer: Buffer.from(files[filename]) })
    await form.getByRole('button', { name: 'Conserver une nouvelle version' }).click(); await approve()
    await expect(panel.getByRole('article', { name: 'Version ' + number, exact: true })).toContainText(filename)
    await mobile()
  }
  await upload('preparation-1.pdf', 1); await upload('preparation-2.pdf', 2)
  const firstHash = versions[0].sha256
  record = { ...record, status: 'signed', approved_by: 43, signed_on: '2026-10-14', signature_reference: 'Copie reçue', signature_evidence_status: 'declared_external', available_transitions: ['active', 'ended', 'terminated'] }
  await page.reload(); await openDocuments()
  panel = page.getByRole('region', { name: 'Documents et avenants', exact: true })
  await panel.getByRole('button', { name: 'Consulter les versions · original' }).click()
  await upload('copie-signee.pdf', 3)
  form = panel.getByRole('form', { name: 'Identifier la version signée' })
  await form.getByRole('checkbox').check(); await form.getByRole('button', { name: 'Identifier la version signée' }).click(); await approve()
  await expect(panel.getByRole('form', { name: 'Conserver une nouvelle version' })).toHaveCount(0)
  await expect(panel.getByRole('article', { name: 'Version 3', exact: true })).toContainText('Version déclarée signée')
  await panel.getByRole('button', { name: 'Ajouter un document ou un avenant' }).click()
  form = panel.getByRole('form', { name: 'Ajouter un document ou un avenant' })
  await form.getByLabel('Référence du document').fill('avenant-1'); await form.getByLabel('Intitulé du document').fill('Avenant lié ' + 'ConditionsLongues'.repeat(5))
  await form.getByLabel('Date d’effet déclarée de l’avenant').fill('2027-01-01')
  await form.getByRole('button', { name: 'Ajouter un document ou un avenant' }).click(); await approve()
  await panel.getByRole('button', { name: 'Consulter les versions · avenant-1' }).click()
  await upload('avenant.pdf', 1)
  await panel.getByRole('button', { name: 'Consulter les versions · original' }).click()
  await expect(panel.getByRole('article', { name: 'Version 1', exact: true })).toContainText(String(firstHash))
  expect(versions).toHaveLength(4); expect(documents[0].versions_count).toBe(3); expect(documents[1].original_document_id).toBe(documents[0].id)
  await expect(panel.getByRole('link')).toHaveCount(0)
  await page.addStyleTag({ content: '* { font-family: Georgia, serif !important; }' }); await mobile()
  for (const close of await page.getByRole('button', { name: 'Fermer la notification', exact: true }).all()) await close.click()
  await panel.screenshot({ path: 'reports/ctr002-documents-mobile.png' })
  expect(writes).toBe(7); expect(errors).toEqual([])
})
