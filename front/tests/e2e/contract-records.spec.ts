import { expect, test } from '@playwright/test'

test('contrats : préparation, validation distincte, signature déclarée et régularisation sur mobile', async ({ page }) => {
  const org = '11111111-1111-4111-8111-111111111111', revision = '22222222-2222-4222-8222-222222222222'
  const id = '33333333-3333-4333-8333-333333333333', personId = '44444444-4444-4444-8444-444444444444'
  const title = 'Dossier <script>CTR_BAD</script> ' + 'ConfidentielLong'.repeat(5)
  const name = 'Camille ' + 'NomAdministratifLong'.repeat(5)
  let actor = 42, confirmed = false, writes = 0
  let record: Record<string, unknown> | null = null
  const events: Record<string, unknown>[] = [], errors: string[] = []
  const graph: Record<string, string[]> = { draft: ['in_review', 'archived'], in_review: ['draft', 'approved', 'archived'], approved: ['in_review', 'sent'],
    sent: ['in_review', 'signed'], signed: ['active', 'ended', 'terminated'], active: ['ended', 'terminated'], terminated: ['archived'], ended: ['archived'], archived: [] }
  page.on('pageerror', (error) => errors.push(error.message))
  page.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()) })
  const headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request(), path = new URL(request.url()).pathname.replace('/api/v1/', ''), method = request.method()
    if (method === 'OPTIONS') { await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET, POST, PATCH', 'Access-Control-Allow-Headers': 'Content-Type, X-Tenant-Revision, X-XSRF-TOKEN' } }); return }
    let data: unknown = []
    const meta = { request_id: 'ctr001-browser', current_page: 1, last_page: 1, total: 1 }
    if (path === 'auth/me') data = { id: actor, name: actor === 42 ? 'Préparateur' : 'Validateur', email: 'staff@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (path === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (path === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: id }]
    if (path === 'active-organization/confirmation') confirmed = true
    if (path === 'active-organization' || path === 'active-organization/confirmation') data = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'],
      permissions: ['organization.view', 'administrative.read', 'administrative.write', 'administrative.validate'], is_owner: false, actor_user_id: actor, membership_id: id }, revision, confirmed }
    const base = 'tenants/' + org + '/contracts'
    if (path === base + '/people') data = [{ id: personId, label: name }]
    if (path === base && method === 'POST') {
      expect(confirmed).toBe(true); expect(request.headers()['x-tenant-revision']).toBe(revision)
      const body = request.postDataJSON()
      expect(body.organization_id).toBeUndefined(); expect(body.status).toBeUndefined(); expect(body.amount).toBeUndefined()
      expect(body).toMatchObject({ reference: 'dossier-pilote', title, type: 'player', responsible_person_id: personId })
      writes++
      record = { ...body, id, responsible_name: name, status: 'draft', version: revision, prepared_by: actor, approved_by: null, approved_at: null,
        signed_on: null, signature_reference: null, signature_recorded_at: null, terminated_on: null, signature_evidence_status: 'not_declared',
        in_force: false, sporting_eligibility: 'not_assessed', publication_status: 'private', parties_count: body.parties.length,
        obligations_count: body.obligations.length, pending_obligations_count: body.obligations.length, available_transitions: graph.draft,
        parties: body.parties.map((party: Record<string, unknown>, index: number) => ({ ...party, id: personId, position: index + 1 })),
        obligations: body.obligations.map((item: Record<string, unknown>, index: number) => ({ ...item, id: personId, position: index + 1, status: 'pending', version: revision })) }
      events.push({ id: revision, from_status: null, to_status: 'draft', contract_version: revision, actor_user_id: actor, occurred_at: '2026-10-14T12:00:00+00:00' }); data = record
    } else if (path === base) data = record ? [record] : []
    if (path === base + '/' + id) data = record
    if (path === base + '/' + id + '/transitions' && method === 'POST') {
      const body = request.postDataJSON(), from = record!.status
      expect(body.version).toBe(record!.version); expect(confirmed).toBe(true)
      if (body.status === 'approved') expect(actor).not.toBe(record!.prepared_by)
      if (body.status === 'signed') expect(body).toMatchObject({ signed_on: '2026-10-14', signature_reference: 'Original reçu hors plateforme', confirm_signature_received: true })
      writes++
      const version = '99999999-9999-4999-8999-' + String(writes).padStart(12, '0')
      record = { ...record, ...body, version, available_transitions: graph[body.status], in_force: body.status === 'active',
        ...(body.status === 'approved' ? { approved_by: actor, approved_at: '2026-10-14T12:00:00+00:00' } : {}),
        ...(body.status === 'signed' ? { signature_evidence_status: 'declared_external', signature_recorded_at: '2026-10-14T12:00:00+00:00' } : {}) }
      events.unshift({ id: version, from_status: from, to_status: body.status, contract_version: version, actor_user_id: actor, occurred_at: '2026-10-14T12:00:00+00:00' }); data = record
    } else if (path.endsWith('/transitions')) data = events
    if (path.endsWith('/obligations/' + personId) && method === 'PATCH') {
      const body = request.postDataJSON()
      expect(body).toMatchObject({ contract_version: record!.version, version: revision, status: 'waived' })
      writes++; record = { ...record, version: '88888888-8888-4888-8888-888888888888', pending_obligations_count: 0,
        obligations: [{ ...(record!.obligations as Record<string, unknown>[])[0], status: 'waived', version: personId }] }; data = record
    }
    await route.fulfill({ headers, json: { data, meta, ...(method !== 'GET' ? { notification: { type: 'success', message: 'Dossier enregistré.' } } : {}) } })
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
  await page.goto('/')
  await page.getByRole('button', { name: 'Contrats de l’organisation' }).click()
  const panel = page.getByRole('region', { name: 'Contrats de l’organisation', exact: true })
  await panel.getByRole('button', { name: 'Créer un dossier de contrat' }).click()
  const form = panel.getByRole('form', { name: 'Enregistrer le dossier de contrat' })
  await form.getByLabel('Référence stable').fill('dossier-pilote')
  await form.getByLabel('Intitulé du dossier').fill(title)
  await form.getByLabel('Date de début').fill('2026-01-01')
  await form.getByLabel('Date de fin incluse').fill('2026-12-31')
  await form.getByLabel('Responsable du suivi').selectOption(personId)
  await form.getByLabel('Nom administratif déclaré de la partie').fill(name)
  await form.getByRole('button', { name: 'Ajouter une obligation' }).click()
  await form.getByLabel('Intitulé de l’obligation').fill('Restitution du matériel ' + 'ObligationLongue'.repeat(5))
  await mobile()
  await form.getByRole('button', { name: 'Enregistrer le dossier de contrat' }).click()
  expect(writes).toBe(0); await approve()
  let dossier = panel.getByRole('region', { name: 'Consulter le dossier', exact: true })
  await expect(dossier.getByRole('heading', { name: title, exact: true })).toBeVisible()
  const move = async (status: string) => {
    const transition = dossier.getByRole('form', { name: 'Enregistrer le changement de statut' })
    await transition.getByLabel('Prochain statut').selectOption(status)
    await transition.getByRole('button', { name: 'Enregistrer le changement de statut' }).click(); await approve()
    await expect(dossier.getByText(status === 'active' ? 'Actif · En vigueur à la date UTC actuelle' : (status === 'in_review' ? 'En revue' : status === 'approved' ? 'Approuvé' : status === 'sent' ? 'Envoyé' : status === 'signed' ? 'Signature reçue' : status === 'terminated' ? 'Résilié' : 'Archivé') + ' · Hors état actif ou hors période à la date UTC actuelle', { exact: true })).toBeVisible()
  }
  await move('in_review')
  await expect(dossier.getByRole('option', { name: 'Approuvé', exact: true })).toHaveCount(0)
  actor = 43; await page.reload()
  await page.getByRole('button', { name: 'Contrats de l’organisation' }).click()
  await panel.getByRole('button', { name: 'Consulter le dossier' }).click()
  dossier = panel.getByRole('region', { name: 'Consulter le dossier', exact: true })
  await move('approved'); await move('sent')
  const transition = dossier.getByRole('form', { name: 'Enregistrer le changement de statut' })
  await transition.getByLabel('Prochain statut').selectOption('signed')
  await transition.getByLabel('Date de la signature reçue').fill('2026-10-14')
  await transition.getByLabel('Référence de l’original signé reçu').fill('Original reçu hors plateforme')
  await transition.getByLabel('Je confirme la réception d’une signature obtenue hors de la plateforme.').check()
  await move('signed'); await move('active')
  await expect(dossier.getByText('Le suivi du contrat ne détermine pas l’éligibilité sportive.')).toBeVisible()
  await transition.getByLabel('Prochain statut').selectOption('terminated')
  await transition.getByLabel('Date de résiliation déclarée').fill('2026-10-14')
  await move('terminated')
  const obligation = dossier.getByRole('form', { name: /^Obligation Restitution/ })
  await obligation.getByLabel('État de l’obligation').selectOption('waived')
  await obligation.getByRole('button', { name: 'Enregistrer le suivi de l’obligation' }).click(); await approve()
  await expect(obligation.locator('p').filter({ hasText: 'Levée dans le suivi' })).toBeVisible()
  await move('archived')
  await expect(dossier.getByRole('button', { name: 'Modifier le dossier' })).toHaveCount(0)
  await expect(dossier.getByRole('button', { name: 'Enregistrer le suivi de l’obligation' })).toHaveCount(0)
  await expect(page.locator('script').filter({ hasText: 'CTR_BAD' })).toHaveCount(0)
  for (const button of await page.getByRole('button', { name: 'Fermer la notification', exact: true }).all()) await button.click()
  await page.addStyleTag({ content: '* { font-family: Georgia, serif !important }' })
  await mobile()
  await dossier.screenshot({ path: 'reports/ctr001-archive-mobile.png', style: 'header { visibility: hidden }' })
  expect(writes).toBe(9); expect(events).toHaveLength(8); expect(errors).toEqual([])
})
