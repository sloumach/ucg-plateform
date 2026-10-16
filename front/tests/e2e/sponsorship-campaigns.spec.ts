import { expect, test } from '@playwright/test'

test('campagne partenaire : retard, preuve privée puis validation indépendante sur mobile', async ({ page }) => {
  const org = '11111111-1111-4111-8111-111111111111', sponsorId = '22222222-2222-4222-8222-222222222222'
  const agreementId = '33333333-3333-4333-8333-333333333333', campaignId = '44444444-4444-4444-8444-444444444444', scopeId = '55555555-5555-4555-8555-555555555555'
  const deliverableId = '66666666-6666-4666-8666-666666666666', stamp = (n: number) => '77777777-7777-4777-8777-' + String(n).padStart(12, '0')
  let actor = 42, confirmed = false, seq = 10
  let campaign: Record<string, unknown> | null = null, deliverable: Record<string, unknown> | null = null
  const proof = '<script>SPON003_PROOF</script> ' + 'PreuveDeRéalisation'.repeat(25), title = 'Vidéo ' + 'PartenaireTrèsLong'.repeat(8)
  const errors: string[] = [], history: Record<string, unknown>[] = []
  const headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }
  const agreement = { id: agreementId, sponsor_id: sponsorId, opportunity_id: null, reference: 'accord-campagne', title: 'Accord campagne', starts_on: '2026-01-01', ends_on: '2026-12-31', responsible_user_id: 42, responsible_label: 'Membre 42', status: 'approved', prepared_by: 42, approved_by: 43, approved_at: '2026-01-01T12:00:00Z', version: stamp(1), publication_status: 'private' }
  const sponsorRoot = 'tenants/' + org + '/sponsors', agreementRoot = sponsorRoot + '/' + sponsorId + '/agreements', agreementPath = agreementRoot + '/' + agreementId
  const campaignRoot = agreementPath + '/campaigns', campaignPath = campaignRoot + '/' + campaignId, promiseRoot = campaignPath + '/deliverables', promisePath = promiseRoot + '/' + deliverableId
  page.on('pageerror', (error) => errors.push(error.message))
  page.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()) })
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request(), url = new URL(request.url()), path = url.pathname.replace('/api/v1/', ''), method = request.method()
    if (method === 'OPTIONS') { await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET, POST, PATCH', 'Access-Control-Allow-Headers': 'Content-Type, X-Tenant-Revision, X-XSRF-TOKEN' } }); return }
    expect(path.includes('/financial')).toBe(false); expect(path.includes('/people')).toBe(false)
    let data: unknown = [], summary: Record<string, number> | undefined
    const meta = { request_id: 'spon003-browser', current_page: 1, last_page: 1, total: 0 }
    if (path === 'auth/me') data = { id: actor, name: 'Membre ' + actor, email: 'membre' + actor + '@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (path === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (path === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: sponsorId }]
    if (path === 'active-organization/confirmation') confirmed = true
    if (path === 'active-organization' || path === 'active-organization/confirmation') data = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'Europe/Paris', language: 'fr', roles: ['member'], permissions: ['organization.view', 'partnerships.read', 'partnerships.write', 'partnerships.validate'], is_owner: false, actor_user_id: actor, membership_id: sponsorId }, revision: stamp(2), confirmed }
    if (path === sponsorRoot) data = [{ id: sponsorId, name: 'Sponsor campagne', industry: null, status: 'partner', version: stamp(3), publication_status: 'private' }]
    if (path === agreementRoot) data = [agreement]
    if (path === agreementPath) data = agreement
    if (path === agreementPath + '/exposures') data = [{ id: scopeId, agreement_id: agreementId, target_type: 'organization', target_id: org, target_label: 'Organisation Alpha', status: 'active', version: stamp(4), channel: 'social', description: null, authorization_reference: 'Consentement vérifié', exclusivity_key: null, exposure_authorized: true, publication_status: 'private' }]
    if (path.endsWith('/sponsorship-references/members')) data = [{ id: '42', label: 'Membre 42' }, { id: '44', label: 'Membre 44' }]
    const event = (action: string) => history.push({ id: stamp(++seq), campaign_id: campaignId, deliverable_id: deliverable?.id ?? null, action, from_status: null, to_status: deliverable?.status ?? 'active', actor_user_id: actor, occurred_at: '2026-10-16T00:00:00Z' })
    if (path === campaignRoot && method === 'POST') {
      expect(confirmed).toBe(true); expect(request.headers()['x-tenant-revision']).toBe(stamp(2))
      const body = request.postDataJSON(); expect(body.agreement_version).toBe(agreement.version); expect(body.responsible_user_id).toBe(42)
      campaign = { id: campaignId, agreement_id: agreementId, title: body.title, starts_on: body.starts_on, ends_on: body.ends_on, responsible_user_id: 42, responsible_label: 'Membre 42', status: 'active', version: stamp(++seq), publication_status: 'private' }
      event('campaign_created'); data = campaign
    } else if (path === campaignRoot) data = campaign ? [campaign] : []
    if (path === campaignPath) data = campaign
    if (path === promiseRoot && method === 'POST') {
      const body = request.postDataJSON(); expect(body.agreement_version).toBe(agreement.version); expect(body.campaign_version).toBe(campaign?.version); expect(body.exposure_id).toBe(scopeId)
      campaign = { ...campaign, version: stamp(++seq) }
      deliverable = { id: deliverableId, agreement_id: agreementId, campaign_id: campaignId, exposure_id: scopeId, kind: body.kind, title: body.title, responsible_user_id: 42, responsible_label: 'Membre 42', due_at: body.due_at, status: 'promised', version: stamp(++seq), prepared_by: actor, proof_reference: null, delivered_at: null, delivered_by: null, validated_at: null, validated_by: null, overdue: true, delivered_late: false, exposure_authorized: true, validation_matches_agreement: false, publication_status: 'private' }
      event('promised'); data = { ...deliverable, campaign_version: campaign.version, agreement_version: agreement.version }
    } else if (path === promiseRoot) data = deliverable ? [deliverable] : []
    if (path === promisePath + '/deliveries') {
      const body = request.postDataJSON(); expect(body.version).toBe(deliverable?.version); expect(body.proof_reference).toBe(proof)
      campaign = { ...campaign, version: stamp(++seq) }
      deliverable = { ...deliverable, status: 'delivered', delivered_by: actor, delivered_at: body.delivered_at, proof_reference: body.proof_reference, version: stamp(++seq), overdue: false, delivered_late: true }
      event('delivered'); data = { ...deliverable, campaign_version: campaign.version, agreement_version: agreement.version }
    }
    if (path === promisePath + '/transitions') {
      const body = request.postDataJSON(); expect(actor).toBe(44); expect(body.confirm_evidence).toBe(true); expect(body.target_status).toBe('validated'); expect(body.version).toBe(deliverable?.version); expect(body.campaign_version).toBe(campaign?.version)
      campaign = { ...campaign, version: stamp(++seq) }
      deliverable = { ...deliverable, status: 'validated', validated_by: actor, validated_at: '2026-10-16T00:00:00Z', version: stamp(++seq), validation_matches_agreement: true }
      event('validated'); data = { ...deliverable, campaign_version: campaign.version, agreement_version: agreement.version }
    }
    if (path === campaignPath + '/history') data = history
    if (path.endsWith('/sponsorship-dashboard')) {
      const status = deliverable?.status
      summary = { promised: status === 'promised' ? 1 : 0, overdue: status === 'promised' ? 1 : 0, delivered: status === 'delivered' ? 1 : 0, validated: status === 'validated' ? 1 : 0, cancelled: 0 }
      data = status === 'promised' ? [{ ...deliverable, sponsor_name: 'Sponsor campagne', agreement_reference: agreement.reference, campaign_title: campaign?.title }] : []
    }
    if (Array.isArray(data)) meta.total = data.length
    await route.fulfill({ status: method === 'POST' ? 201 : 200, headers, contentType: 'application/json', body: JSON.stringify({ data, meta, ...(summary ? { summary } : {}) }) })
  })
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/')
  const confirm = async () => { await page.getByRole('button', { name: 'Confirmer et continuer' }).click() }
  const openAgreement = async () => {
    await page.getByRole('button', { name: 'Ouvrir les prospects' }).click()
    await page.getByRole('button', { name: 'Ouvrir le dossier', exact: true }).click()
    await page.getByRole('button', { name: 'Accords', exact: true }).click()
    await page.getByRole('button', { name: 'Ouvrir l’accord' }).click()
    await page.getByRole('button', { name: 'Ouvrir les campagnes' }).click()
  }
  await openAgreement()
  await page.getByRole('button', { name: 'Nouvelle campagne' }).click()
  const campaignForm = page.getByRole('form', { name: 'Enregistrer la campagne' })
  await campaignForm.getByLabel('Nom de la campagne').fill('Campagne automne')
  await campaignForm.getByRole('button', { name: 'Enregistrer la campagne' }).click(); await confirm()
  await page.getByRole('button', { name: 'Nouveau livrable' }).click()
  const promiseForm = page.getByRole('form', { name: 'Enregistrer le livrable' })
  await promiseForm.getByLabel('Titre du livrable').fill(title)
  await promiseForm.getByLabel('Type de livrable').selectOption('video')
  await promiseForm.getByLabel('Périmètre de l’accord').selectOption(scopeId)
  await promiseForm.getByLabel('Échéance', { exact: true }).fill('2026-10-15T12:00')
  await promiseForm.getByRole('button', { name: 'Enregistrer le livrable' }).click(); await confirm()
  await expect(page.getByText('En retard — livraison attendue')).toBeVisible()
  const dashboard = page.getByRole('region', { name: 'Suivi des engagements partenaires' })
  await expect(dashboard.getByText(title, { exact: true })).toBeVisible()
  await page.getByRole('button', { name: 'Déclarer une livraison' }).click()
  const deliveryForm = page.getByRole('form', { name: 'Enregistrer la livraison' })
  await deliveryForm.getByLabel('Date de livraison').fill('2026-10-15T16:00')
  await deliveryForm.getByLabel('Référence de la preuve').fill(proof)
  await deliveryForm.getByRole('button', { name: 'Enregistrer la livraison' }).click(); await confirm()
  await expect(page.getByText('Un autre membre doit valider cette livraison.')).toBeVisible()
  await expect(dashboard.getByText('Aucune livraison en retard.')).toBeVisible()
  actor = 44; confirmed = false
  await page.reload(); await openAgreement()
  await page.getByRole('button', { name: 'Ouvrir la campagne' }).click()
  const validationForm = page.getByRole('form', { name: 'Valider la preuve' })
  await validationForm.getByRole('checkbox').check()
  await validationForm.getByRole('button', { name: 'Valider la preuve' }).click(); await confirm()
  await expect(page.getByText(title + ' · Validé', { exact: true })).toBeVisible()
  await page.getByRole('button', { name: 'Historique de la campagne', exact: true }).click()
  await expect(page.getByText(/Preuve validée ·/)).toBeVisible()
  await page.getByRole('region', { name: 'Livrables de la campagne' }).screenshot({ path: 'reports/spon003-deliverable-mobile.png', style: 'header, [aria-label=Notifications] { visibility: hidden; }' })
  await dashboard.screenshot({ path: 'reports/spon003-dashboard-mobile.png', style: 'header, [aria-label=Notifications] { visibility: hidden; }' })
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
  expect(await page.evaluate(() => (window as unknown as { SPON003_PROOF?: unknown }).SPON003_PROOF)).toBeUndefined()
  expect(errors).toEqual([])
})
