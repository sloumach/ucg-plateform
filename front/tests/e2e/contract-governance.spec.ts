import { expect, test } from '@playwright/test'

test('contrat financier : préparation, approbation, signature distincte et lecture restreinte sur mobile', async ({ page }) => {
  const org = '11111111-1111-4111-8111-111111111111', revision = '22222222-2222-4222-8222-222222222222'
  const id = '33333333-3333-4333-8333-333333333333', person = '44444444-4444-4444-8444-444444444444'
  let actor = 41, confirmed = false, writes = 0, financialReads = 0
  const allPermissions = ['organization.view', 'administrative.read', 'administrative.write', 'administrative.validate', 'financial.read', 'financial.write', 'financial.validate']
  let permissions = allPermissions
  let record: Record<string, unknown> = { id, reference: 'contrat-financier', type: 'player', title: 'Dossier financier privé', responsible_person_id: person, responsible_name: 'Camille',
    starts_on: '2026-01-01', ends_on: '2027-12-31', status: 'draft', version: revision, prepared_by: 41, approved_by: null, approved_at: null,
    signed_on: null, signature_reference: null, signature_recorded_at: null, signature_recorded_by: null, signature_separation_required: false,
    financial_terms_present: false, financial_documents_restricted: false, terminated_on: null, signature_evidence_status: 'not_declared',
    in_force: false, sporting_eligibility: 'not_assessed', publication_status: 'private', parties_count: 1, obligations_count: 0, pending_obligations_count: 0,
    available_transitions: ['in_review', 'archived'], parties: [{ id: person, kind: 'external', person_id: null, name: 'Partie', position: 1 }], obligations: [] }
  let financial: { id: string; version: string; prepared_by: number; updated_at: string; terms: Record<string, string | null> } | null = null
  const errors: string[] = [], base = 'tenants/' + org + '/contracts', detailPath = base + '/' + id
  const headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }
  const nextVersion = () => '99999999-9999-4999-8999-' + String(++writes).padStart(12, '0')
  page.on('pageerror', (error) => errors.push(error.message))
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request(), path = new URL(request.url()).pathname.replace('/api/v1/', ''), method = request.method()
    if (method === 'OPTIONS') {
      await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET, POST, PATCH', 'Access-Control-Allow-Headers': 'Content-Type, X-Tenant-Revision, X-XSRF-TOKEN' } }); return
    }
    let data: unknown = [], status = 200
    if (path === 'auth/me') data = { id: actor, name: 'Intervenant', email: 'staff@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (path === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (path === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: id }]
    if (path === 'active-organization/confirmation') confirmed = true
    if (path === 'active-organization' || path === 'active-organization/confirmation') data = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr',
      roles: ['member'], permissions, actor_user_id: actor, is_owner: false, membership_id: id }, revision, confirmed }
    if (path === base) data = [record]
    if (path === base + '/people') data = [{ id: person, label: 'Camille' }]
    if (path === detailPath) data = record
    if (path === detailPath && method === 'PATCH') {
      expect(confirmed).toBe(true); expect(request.headers()['x-tenant-revision']).toBe(revision)
      const { version, ...body } = request.postDataJSON()
      expect(version).toBe(record.version)
      expect(body).toEqual({ signature_separation_required: true })
      record = { ...record, ...body, prepared_by: actor, version: nextVersion() }; data = record
    }
    if (path === detailPath + '/financial-terms') {
      expect(permissions).toContain('financial.read')
      if (method === 'GET') { financialReads++; data = financial }
      if (method === 'PATCH') {
        expect(confirmed).toBe(true); expect(request.headers()['x-tenant-revision']).toBe(revision)
        const body = request.postDataJSON()
        expect(body.contract_version).toBe(record.version); expect(body.expected_version).toBe(financial?.version ?? null)
        expect(body.terms).toEqual({ currency: 'EUR', remuneration: '123456789012.99', remuneration_period: 'monthly', bonus: '1500.01', prize_share_percent: '12.50', payment_terms: '<script>PRIVATE_FINANCE</script>' })
        expect(body.organization_id).toBeUndefined()
        financial = { id, version: nextVersion(), prepared_by: actor, updated_at: '2026-10-15T00:00:00Z', terms: body.terms }
        record = { ...record, version: financial.version, prepared_by: actor, financial_terms_present: true, financial_documents_restricted: true }; data = financial
      }
    }
    if (path === detailPath + '/transitions' && method === 'POST') {
      const body = request.postDataJSON()
      expect(confirmed).toBe(true); expect(body.version).toBe(record.version)
      if (body.status === 'approved') { expect(actor).toBe(42); expect(record.prepared_by).toBe(41); record.approved_by = actor }
      if (body.status === 'signed') {
        expect(actor).toBe(43); expect(body.confirm_signature_received).toBe(true); expect(body.signature_reference).toBe('Copie externe reçue')
        record.signature_recorded_by = actor; record.signed_on = body.signed_on; record.signature_evidence_status = 'declared_external'
        record.signature_reference = body.signature_reference; record.signature_recorded_at = '2026-10-15T00:00:00Z'
      }
      const transitions: Record<string, string[]> = { in_review: ['draft', 'approved', 'archived'], approved: ['in_review', 'sent'], sent: ['in_review', 'signed'], signed: ['active', 'ended', 'terminated'] }
      record = { ...record, status: body.status, version: nextVersion(), available_transitions: transitions[body.status] }; data = record
    }
    await route.fulfill({ status, headers, json: { data, meta: { request_id: 'ctr004-browser', current_page: 1, last_page: 1, total: Array.isArray(data) ? data.length : 1 },
      ...(method === 'POST' || method === 'PATCH' ? { notification: { type: 'success', message: 'Décision conservée.' } } : {}) } })
  })
  await page.setViewportSize({ width: 390, height: 844 })
  const open = async () => {
    await page.goto('/')
    await page.getByRole('button', { name: 'Contrats de l’organisation', exact: true }).click()
    await page.getByRole('button', { name: 'Consulter le dossier', exact: true }).click()
    await expect(page.getByRole('region', { name: 'Consulter le dossier', exact: true })).toBeVisible()
  }
  const confirm = async () => {
    const region = page.getByRole('region', { name: 'Confirmer l’action', exact: true })
    await expect(region).toContainText('Alpha')
    await region.getByRole('button', { name: 'Confirmer et continuer', exact: true }).click()
    await expect(region).toHaveCount(0)
  }
  const transition = async (status: string) => {
    const form = page.getByRole('form', { name: 'Enregistrer le changement de statut', exact: true })
    await expect(form.getByLabel('Prochain statut')).toBeEnabled()
    await form.getByLabel('Prochain statut').selectOption(status)
    if (status === 'signed') {
      await form.getByLabel('Date de la signature reçue').fill('2026-10-14')
      await form.getByLabel('Référence de l’original signé reçu').fill('Copie externe reçue')
      await form.getByRole('checkbox').check()
    }
    await form.getByRole('button', { name: 'Enregistrer le changement de statut', exact: true }).click(); await confirm()
  }
  await open()
  await page.getByRole('button', { name: 'Modifier le dossier', exact: true }).click()
  let form = page.getByRole('form', { name: 'Enregistrer le dossier de contrat', exact: true })
  await expect(form.getByRole('checkbox')).toBeEnabled()
  await form.getByRole('checkbox').check()
  await form.getByRole('button', { name: 'Enregistrer le dossier de contrat', exact: true }).click(); await confirm()
  await page.getByRole('button', { name: 'Conditions financières restreintes', exact: true }).click()
  form = page.getByRole('form', { name: 'Enregistrer les conditions financières', exact: true })
  await expect(form.getByLabel('Rémunération', { exact: true })).toBeEnabled()
  await form.getByLabel('Rémunération', { exact: true }).fill('123456789012.99')
  await form.getByLabel('Prime', { exact: true }).fill('1500.01'); await form.getByLabel('Part des gains (%)').fill('12.50')
  await form.getByLabel('Périodicité de la rémunération').selectOption('monthly')
  await form.getByLabel('Modalités de paiement sensibles').fill('<script>PRIVATE_FINANCE</script>')
  await form.getByRole('button', { name: 'Enregistrer les conditions financières', exact: true }).click(); await confirm()
  await transition('in_review')
  actor = 42; confirmed = false; await open()
  await transition('approved'); await transition('sent')
  await expect(page.getByRole('option', { name: 'Signé', exact: true })).toHaveCount(0)
  actor = 43; confirmed = false; await open(); await transition('signed')
  expect(record.signature_recorded_by).toBe(43)
  const readsBeforeCoach = financialReads
  actor = 44; permissions = ['organization.view', 'administrative.read']; confirmed = false; await open()
  await expect(page.getByRole('button', { name: 'Conditions financières restreintes', exact: true })).toHaveCount(0)
  await expect(page.getByRole('button', { name: 'Documents et avenants', exact: true })).toHaveCount(0)
  await expect(page.getByText('Les documents de ce dossier nécessitent une habilitation de lecture financière.')).toBeVisible()
  await expect(page.getByText('123456789012.99')).toHaveCount(0)
  expect(financialReads).toBe(readsBeforeCoach)
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
  await page.getByRole('region', { name: 'Consulter le dossier', exact: true }).screenshot({ path: 'reports/ctr004-governance-mobile.png' })
  expect(writes).toBe(6); expect(errors).toEqual([])
})
