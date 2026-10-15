import { expect, test } from '@playwright/test'

test('rappels : deux canaux, confirmation, incident SMTP et boîte personnelle sur mobile', async ({ page }) => {
  const org = '11111111-1111-4111-8111-111111111111', revision = '22222222-2222-4222-8222-222222222222'
  const id = '33333333-3333-4333-8333-333333333333', person = '44444444-4444-4444-8444-444444444444'
  const base = 'tenants/' + org + '/contracts', detailPath = base + '/' + id
  let confirmed = false, writes = 0
  const permissions = ['organization.view', 'administrative.read', 'administrative.write']
  let record = { id, reference: 'pilote-rappels', type: 'player', title: 'Dossier de Camille', responsible_person_id: person, responsible_name: 'Camille',
    starts_on: '2026-01-01', ends_on: '2026-12-01', status: 'approved', version: revision, prepared_by: 41, approved_by: 43, approved_at: '2026-10-01T00:00:00Z',
    signed_on: null, signature_reference: null, signature_recorded_at: null, signature_recorded_by: null, signature_separation_required: false,
    financial_terms_present: false, financial_documents_restricted: false, terminated_on: null, signature_evidence_status: 'not_declared',
    in_force: false, sporting_eligibility: 'not_assessed', publication_status: 'private', parties_count: 1, obligations_count: 0, pending_obligations_count: 0,
    available_transitions: [], parties: [{ id: person, kind: 'external', person_id: null, name: 'Partie', position: 1 }], obligations: [] }
  let plan: Record<string, unknown> | null = null
  let delivery = { id: person, version: revision, contract_id: id, kind: 'deadline', due_on: '2026-12-01', offset_days: 30,
    in_app_status: 'sent', email_status: 'uncertain', reason: 'delivery_uncertain' as string | null, attempts: 1, created_at: '2026-11-01T00:00:00Z', email_sent_at: null as string | null }
  let history: typeof delivery[] = []
  const errors: string[] = [], headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }
  const nextVersion = () => '99999999-9999-4999-8999-' + String(++writes).padStart(12, '0')
  page.on('pageerror', (error) => errors.push(error.message))
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request(), path = new URL(request.url()).pathname.replace('/api/v1/', ''), method = request.method()
    if (method === 'OPTIONS') {
      await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET, POST, PATCH', 'Access-Control-Allow-Headers': 'Content-Type, X-Tenant-Revision, X-XSRF-TOKEN' } }); return
    }
    let data: unknown = []
    if (path === 'auth/me') data = { id: 42, name: 'Responsable', email: 'staff@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (path === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (path === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: id }]
    if (path === 'active-organization/confirmation') confirmed = true
    if (path === 'active-organization' || path === 'active-organization/confirmation') data = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'Europe/Paris', language: 'fr',
      roles: ['member'], permissions, actor_user_id: 42, is_owner: false, membership_id: id }, revision, confirmed }
    if (path === base) data = [record]
    if (path === detailPath) data = record
    if (path === detailPath + '/reminders') {
      data = plan
      if (method === 'PATCH') {
        expect(confirmed).toBe(true); expect(request.headers()['x-tenant-revision']).toBe(revision)
        const body = request.postDataJSON()
        expect(body).toEqual({ contract_version: record.version, expected_version: null, enabled: true,
          notice_on: '2026-11-01', renewal_on: '2026-12-02', offsets: [90, 30, 7], channels: ['in_app', 'email'] })
        plan = { ...body, id, version: nextVersion(), checked_at: null }
        record = { ...record, version: nextVersion() }; data = plan
        history = [delivery]
      }
    }
    if (path === detailPath + '/reminders/history') data = history
    if (path === base + '/reminders') data = history
    if (path === detailPath + '/reminders/' + person + '/resolution') {
      expect(method).toBe('POST'); expect(confirmed).toBe(true); expect(request.headers()['x-tenant-revision']).toBe(revision)
      expect(request.postDataJSON()).toEqual({ expected_version: delivery.version, action: 'confirm_sent' })
      delivery = { ...delivery, version: nextVersion(), email_status: 'sent', reason: null, email_sent_at: '2026-11-01T12:00:00Z' }; history = [delivery]; data = delivery
    }
    await route.fulfill({ status: 200, headers, json: { data, meta: { request_id: 'ctr005-browser', current_page: 1, last_page: 1, total: Array.isArray(data) ? data.length : 1, scheduler_enabled: true, email_ready: true },
      ...(method === 'POST' || method === 'PATCH' ? { notification: { type: 'success', message: 'Rappels enregistrés.' } } : {}) } })
  })
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/')
  await page.getByRole('button', { name: 'Contrats de l’organisation', exact: true }).click()
  await page.getByRole('button', { name: 'Consulter le dossier', exact: true }).click()
  await page.getByRole('button', { name: 'Échéances et rappels', exact: true }).click()
  const form = page.getByRole('form', { name: 'Enregistrer les rappels', exact: true })
  await expect(form.getByLabel('Activer les rappels')).toBeEnabled()
  await form.getByLabel('Activer les rappels').check()
  await form.getByLabel('Date de préavis').fill('2026-11-01')
  await form.getByLabel('Date de renouvellement').fill('2026-12-02')
  await expect(form.getByLabel('Dans l’application')).toBeChecked()
  await expect(form.getByLabel('E-mail', { exact: true })).toBeChecked()
  const confirm = async () => {
    const region = page.getByRole('region', { name: 'Confirmer l’action', exact: true })
    await expect(region).toContainText('Alpha')
    await region.getByRole('button', { name: 'Confirmer et continuer', exact: true }).click()
    await expect(region).toHaveCount(0)
  }
  await form.getByRole('button', { name: 'Enregistrer les rappels', exact: true }).click()
  expect(writes).toBe(0); await confirm()
  await expect(page.getByRole('button', { name: 'Échéances et rappels', exact: true })).toBeEnabled()
  await page.getByRole('button', { name: 'Échéances et rappels', exact: true }).click()
  await expect(page.getByText(/Aucune relance automatique/)).toBeVisible()
  const resolution = page.getByRole('form', { name: 'Enregistrer la décision sur l’envoi', exact: true })
  await expect(resolution.getByLabel('Décision après vérification')).toHaveValue('confirm_sent')
  await resolution.getByRole('button', { name: 'Enregistrer la décision sur l’envoi', exact: true }).click(); await confirm()
  await page.getByRole('button', { name: 'Mes rappels de contrats', exact: true }).click()
  const inbox = page.getByRole('region', { name: 'Mes rappels de contrats', exact: true })
  await expect(inbox).toContainText('Accepté par le service d’envoi')
  await inbox.getByRole('button', { name: 'Consulter le contrat', exact: true }).click()
  await expect(page.getByRole('region', { name: 'Consulter le dossier', exact: true })).toContainText('Dossier de Camille')
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
  expect(errors).toEqual([])
  await inbox.scrollIntoViewIfNeeded()
  await page.screenshot({ path: 'reports/ctr005-reminders-mobile.png' })
})
