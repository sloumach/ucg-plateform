import { expect, test } from '@playwright/test'

test('accord partenaire : apport séparé, second approbateur et invalidation de l’exposition sur mobile', async ({ page }) => {
  const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222'
  const stamp = (n: number) => '33333333-3333-4333-8333-' + String(n).padStart(12, '0')
  let actor = 42, confirmed = false, revision = stamp(1), sequence = 10
  let agreement: Record<string, unknown> | null = null, terms: Record<string, unknown> | null = null, financialVersion: string | null = null
  const exposures: Record<string, unknown>[] = [], history: Record<string, unknown>[] = [], errors: string[] = []
  const title = 'Accord ' + 'NomCommercialTrèsLong'.repeat(5), proof = '<script>SPON002_BAD</script> ' + 'AutorisationVérifiée'.repeat(6)
  page.on('pageerror', (error) => errors.push(error.message))
  page.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()) })
  const headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request(), url = new URL(request.url()), path = url.pathname.replace('/api/v1/', ''), method = request.method()
    if (method === 'OPTIONS') { await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET, POST, PATCH', 'Access-Control-Allow-Headers': 'Content-Type, X-Tenant-Revision, X-XSRF-TOKEN' } }); return }
    expect(path.includes('/people')).toBe(false)
    let data: unknown = []
    const meta = { request_id: 'spon002-browser', current_page: 1, last_page: 1, total: 1 }
    if (path === 'auth/me') data = { id: actor, name: 'Membre ' + actor, email: 'membre' + actor + '@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (path === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (path === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: id }]
    if (path === 'active-organization/confirmation') confirmed = true
    if (path === 'active-organization' || path === 'active-organization/confirmation') data = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'Europe/Paris', language: 'fr', roles: ['member'], permissions: ['organization.view', 'partnerships.read', 'partnerships.write', 'partnerships.validate', 'financial.read', 'financial.write', 'financial.validate'], is_owner: false, actor_user_id: actor, membership_id: id }, revision, confirmed }
    const sponsor = 'tenants/' + org + '/sponsors', root = sponsor + '/' + id + '/agreements', detail = root + '/' + id
    if (path === sponsor) data = [{ id, name: 'Sponsor exemple', industry: null, status: 'prospect', version: stamp(2), publication_status: 'private' }]
    if (path.endsWith('/sponsorship-references/members')) data = [{ id: '42', label: 'Membre 42' }, { id: '44', label: 'Membre 44' }]
    const changed = (action: string) => { history.push({ id: stamp(++sequence), action, from_status: null, to_status: agreement?.status, agreement_version: agreement?.version, actor_user_id: actor, occurred_at: '2026-10-16T12:00:00+00:00' }) }
    if (path === root && method === 'POST') {
      expect(confirmed).toBe(true); expect(request.headers()['x-tenant-revision']).toBe(revision)
      const body = request.postDataJSON(); expect(body.responsible_user_id).toBe(42); expect(body.organization_id).toBeUndefined()
      agreement = { ...body, id, sponsor_id: id, status: 'draft', version: stamp(++sequence), prepared_by: actor, approved_by: null, approved_at: null, publication_status: 'private' }; changed('created'); data = agreement
    } else if (path === root) data = agreement ? [agreement] : []
    if (path === detail) data = agreement
    if (path === detail + '/exposures' && method === 'POST') {
      const body = request.postDataJSON(); expect(body.agreement_version).toBe(agreement?.version)
      agreement = { ...agreement, status: 'draft', version: stamp(++sequence), prepared_by: actor }
      exposures.push({ ...body, id: stamp(++sequence), agreement_id: id, target_label: 'Alpha', status: 'active', version: stamp(++sequence), exposure_authorized: false, publication_status: 'private' })
      changed('exposure_saved'); data = { ...exposures[0], agreement_version: agreement.version }
    } else if (path === detail + '/exposures') data = exposures.map((scope) => ({ ...scope, exposure_authorized: agreement?.status === 'approved' }))
    if (path === detail + '/financial' && method === 'PATCH') {
      const body = request.postDataJSON(); expect(body.agreement_version).toBe(agreement?.version); expect(body.expected_version).toBe(financialVersion)
      expect(typeof body.terms.amount).toBe('string'); expect(body.terms.kind).toBe('mixed')
      terms = body.terms; financialVersion = stamp(++sequence); agreement = { ...agreement, version: stamp(++sequence), status: 'draft', approved_by: null, approved_at: null, prepared_by: actor }; changed('financial_saved')
    }
    if (path === detail + '/financial') { data = { id: terms ? id : null, terms, version: financialVersion, agreement_version: agreement?.version }; if (method === 'GET') changed('financial_viewed') }
    if (path === detail + '/transitions' && method === 'POST') {
      const body = request.postDataJSON(); expect(body).toEqual({ version: agreement?.version, target_status: 'approved', confirm_authorizations: true }); expect(actor).not.toBe(agreement?.prepared_by); expect(terms).not.toBeNull()
      agreement = { ...agreement, status: 'approved', version: stamp(++sequence), approved_by: actor, approved_at: '2026-10-16T12:00:00+00:00' }; changed('approved'); data = agreement
    }
    if (path === detail + '/history') data = history
    await route.fulfill({ headers, json: { data, meta, ...(method !== 'GET' ? { notification: { type: 'success', message: 'Enregistré.' } } : {}) } })
  })
  await page.setViewportSize({ width: 390, height: 844 })
  const panel = page.getByRole('region', { name: 'Prospects et partenariats', exact: true })
  const agreements = panel.getByRole('region', { name: 'Accords partenaires', exact: true })
  const dossier = agreements.getByRole('region', { name: 'Ouvrir l’accord', exact: true })
  const approve = async () => { await panel.getByRole('button', { name: 'Confirmer et continuer' }).click(); await expect(panel.getByRole('region', { name: 'Confirmer l’action' })).toHaveCount(0) }
  const open = async () => {
    await page.goto('/'); await panel.getByRole('button', { name: 'Ouvrir les prospects' }).click()
    await panel.getByRole('button', { name: 'Ouvrir le dossier' }).click()
    await panel.getByRole('button', { name: 'Accords', exact: true }).click()
    await expect(agreements.getByRole('heading', { name: 'Accords partenaires' })).toBeVisible()
  }
  await open()
  await expect(agreements.getByText('Aucun résultat pour cette recherche.')).toBeVisible()
  await agreements.getByRole('button', { name: 'Nouvel accord' }).click()
  const form = agreements.getByRole('form', { name: 'Enregistrer l’accord' })
  await form.getByLabel('Référence de l’accord').fill('accord-demo'); await form.getByLabel('Titre de l’accord').fill(title)
  await form.getByLabel('Début de l’accord').fill('2026-10-16'); await form.getByLabel('Fin de l’accord').fill('2026-12-31')
  await form.getByRole('button', { name: 'Enregistrer l’accord' }).click(); await approve()
  await expect(dossier.getByRole('heading', { name: /accord-demo/ })).toBeVisible()
  await dossier.getByRole('button', { name: 'Ajouter un périmètre' }).click()
  const scopeForm = dossier.getByRole('form', { name: 'Enregistrer le périmètre' })
  await scopeForm.getByLabel('Référence de l’autorisation vérifiée').fill(proof)
  await scopeForm.getByLabel('Catégorie d’exclusivité (facultative)').fill('hardware')
  await scopeForm.getByRole('button', { name: 'Enregistrer le périmètre' }).click(); await approve()
  await expect(dossier.getByText('Exposition non autorisée actuellement')).toBeVisible()
  await dossier.getByRole('button', { name: 'Conditions financières et apports', exact: true }).click()
  const financialForm = dossier.getByRole('form', { name: 'Enregistrer les conditions financières' })
  await financialForm.getByLabel('Type d’apport').selectOption('mixed')
  await financialForm.getByLabel('Montant', { exact: true }).fill('1250.00')
  await financialForm.getByLabel('Description de l’apport en nature').fill('Matériel réservé ' + 'Equipement'.repeat(14))
  await financialForm.getByRole('button', { name: 'Enregistrer les conditions financières' }).click(); await approve()
  await expect(dossier.getByText(/L’approbation nécessite un autre intervenant/)).toBeVisible()
  await expect(dossier.getByRole('button', { name: 'Approuver l’accord et ses expositions' })).toHaveCount(0)
  actor = 44; revision = stamp(99); confirmed = false; await open()
  await agreements.getByRole('button', { name: 'Ouvrir l’accord', exact: true }).click()
  const validation = dossier.getByRole('form', { name: 'Approuver l’accord et ses expositions' })
  await validation.getByLabel('Je confirme avoir vérifié les autorisations de tous les périmètres et les conditions financières.').check()
  await validation.getByRole('button', { name: 'Approuver l’accord et ses expositions' }).click(); await approve()
  await expect(dossier.getByText('Exposition autorisée pour la période courante')).toBeVisible()
  await expect(page.locator('script').filter({ hasText: 'SPON002_BAD' })).toHaveCount(0)
  for (const button of await page.getByRole('button', { name: 'Fermer la notification', exact: true }).all()) await button.click()
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(1)
  await dossier.screenshot({ path: 'reports/spon002-approved-mobile.png', style: 'header { visibility: hidden }' })
  await dossier.getByRole('button', { name: 'Conditions financières et apports', exact: true }).click()
  await financialForm.getByLabel('Montant', { exact: true }).fill('1750.00')
  await financialForm.getByRole('button', { name: 'Enregistrer les conditions financières' }).click(); await approve()
  await expect(dossier.getByText('Exposition non autorisée actuellement')).toBeVisible()
  await dossier.getByRole('button', { name: 'Historique de l’accord', exact: true }).click()
  await expect(dossier.getByText(/Approbation · Approuvé/)).toBeVisible()
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(1)
  expect(errors).toEqual([])
})
