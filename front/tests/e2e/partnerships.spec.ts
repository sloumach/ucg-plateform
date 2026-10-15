import { expect, test } from '@playwright/test'

test('prospects, contacts, négociation et échange conservé sur mobile', async ({ page }) => {
  const org = '11111111-1111-4111-8111-111111111111', revision = '22222222-2222-4222-8222-222222222222', id = '33333333-3333-4333-8333-333333333333'
  const name = 'Prospect ' + 'NomCommercialTrèsLong'.repeat(5)
  let confirmed = false, writes = 0
  let sponsor: Record<string, unknown> | null = null, contact: Record<string, unknown> | null = null, opportunity: Record<string, unknown> | null = null
  const interactions: Record<string, unknown>[] = [], errors: string[] = []
  page.on('pageerror', (error) => errors.push(error.message))
  page.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()) })
  const headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request(), url = new URL(request.url()), path = url.pathname.replace('/api/v1/', ''), method = request.method()
    if (method === 'OPTIONS') { await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET, POST, PATCH', 'Access-Control-Allow-Headers': 'Content-Type, X-Tenant-Revision, X-XSRF-TOKEN' } }); return }
    let data: unknown = []
    const meta = { request_id: 'spon001-browser', current_page: 1, last_page: 1, total: 1 }
    if (path === 'auth/me') data = { id: 42, name: 'Partenariats', email: 'partenariats@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (path === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (path === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: id }]
    if (path === 'active-organization/confirmation') confirmed = true
    if (path === 'active-organization' || path === 'active-organization/confirmation') data = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'Europe/Paris', language: 'fr', roles: ['member'], permissions: ['organization.view', 'partnerships.read', 'partnerships.write'], is_owner: false, actor_user_id: 42, membership_id: id }, revision, confirmed }
    const root = 'tenants/' + org + '/sponsors', details = root + '/' + id
    if (path === root && method === 'POST') {
      expect(confirmed).toBe(true); expect(request.headers()['x-tenant-revision']).toBe(revision)
      expect(request.postDataJSON()).toEqual({ name, industry: 'Matériel', status: 'prospect' })
      sponsor = { ...request.postDataJSON(), id, version: revision, publication_status: 'private' }; data = sponsor; writes++
    } else if (path === root) data = sponsor ? [sponsor] : []
    if (path === details && method === 'PATCH') { sponsor = { ...sponsor, ...request.postDataJSON(), version: id }; data = sponsor; writes++ }
    if (path === details + '/contacts' && method === 'POST') {
      expect(request.postDataJSON().user_id).toBeUndefined(); expect(request.postDataJSON().person_id).toBeUndefined()
      contact = { ...request.postDataJSON(), id, sponsor_id: id, version: revision, publication_status: 'private' }; data = contact; writes++
    } else if (path === details + '/contacts') data = contact ? [contact] : []
    if (path === details + '/opportunities' && method === 'POST') { opportunity = { ...request.postDataJSON(), id, sponsor_id: id, version: revision }; data = opportunity; writes++ }
    else if (path === details + '/opportunities') data = opportunity ? [opportunity] : []
    if (path === details + '/opportunities/' + id && method === 'PATCH') {
      expect(request.postDataJSON()).toEqual({ status: 'negotiating', version: revision })
      opportunity = { ...opportunity, ...request.postDataJSON(), version: id }; data = opportunity; writes++
    }
    if (path === details + '/interactions' && method === 'POST') {
      expect(request.postDataJSON().contact_id).toBe(id)
      interactions.push({ ...request.postDataJSON(), id, sponsor_id: id, opportunity_id: null, created_by: 42, created_at: '2026-10-15T12:00:00+00:00', publication_status: 'private' })
      data = interactions[interactions.length - 1]; writes++
    } else if (path === details + '/interactions') data = interactions
    await route.fulfill({ headers, json: { data, meta, ...(method !== 'GET' ? { notification: { type: 'success', message: 'Enregistré.' } } : {}) } })
  })
  await page.setViewportSize({ width: 390, height: 844 }); await page.goto('/')
  const panel = page.getByRole('region', { name: 'Prospects et partenariats', exact: true })
  const approve = async () => { await panel.getByRole('button', { name: 'Confirmer et continuer' }).click(); await expect(panel.getByRole('region', { name: 'Confirmer l’action' })).toHaveCount(0) }
  const noOverflow = async () => { expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(1) }
  await panel.getByRole('button', { name: 'Ouvrir les prospects' }).click()
  await expect(panel.getByText('Aucun résultat pour cette recherche.')).toBeVisible()
  await panel.getByRole('button', { name: 'Nouveau prospect' }).click()
  const sponsorForm = panel.getByRole('form', { name: 'Enregistrer la fiche partenaire' })
  await sponsorForm.getByLabel('Nom de la société').fill(name); await sponsorForm.getByLabel('Secteur d’activité').fill('Matériel')
  await sponsorForm.getByRole('button', { name: 'Enregistrer la fiche partenaire' }).click(); expect(writes).toBe(0); await approve()
  await expect(panel.getByRole('heading', { name, exact: true })).toBeVisible()
  await panel.getByRole('button', { name: 'Ouvrir le dossier' }).click()
  const dossier = panel.getByRole('region', { name, exact: true })
  await expect(dossier.getByText('Aucun résultat pour cette recherche.')).toBeVisible()
  await dossier.getByRole('button', { name: 'Nouveau contact', exact: true }).click()
  const contactForm = dossier.getByRole('form', { name: 'Enregistrer le contact' })
  await contactForm.getByLabel('Nom du contact').fill('Camille partenaire')
  await contactForm.getByLabel('E-mail professionnel').fill('contact.' + 'long'.repeat(20) + '@example.test')
  await contactForm.getByRole('button', { name: 'Enregistrer le contact' }).click(); await approve()
  await expect(dossier.getByText(/Camille partenaire/)).toBeVisible()
  await dossier.getByRole('button', { name: 'Consigner un échange lié' }).click()
  await expect(dossier.getByText('Échange concernant : Camille partenaire')).toBeVisible()
  const exchangeForm = dossier.getByRole('form', { name: 'Ajouter à l’historique' })
  const summary = '<script>SPON_BAD</script> ' + 'CompteRenduSansEspaces'.repeat(12)
  await exchangeForm.getByLabel('Compte rendu de l’échange').fill(summary)
  await exchangeForm.getByLabel('Date et heure de l’échange').fill('2026-01-01T12:00')
  await exchangeForm.getByRole('button', { name: 'Ajouter à l’historique' }).click(); await approve()
  await expect(dossier.getByText(summary, { exact: true })).toBeVisible()
  await expect(page.locator('script').filter({ hasText: 'SPON_BAD' })).toHaveCount(0)
  await noOverflow()
  await dossier.getByRole('button', { name: 'Opportunités', exact: true }).click()
  await dossier.getByRole('button', { name: 'Nouvelle opportunité' }).click()
  const offerForm = dossier.getByRole('form', { name: 'Enregistrer l’opportunité' })
  await offerForm.getByLabel('Titre de l’opportunité').fill('Campagne été')
  await offerForm.getByRole('button', { name: 'Enregistrer l’opportunité' }).click(); await approve()
  await expect(dossier.getByText('Campagne été · À qualifier')).toBeVisible()
  await dossier.getByRole('button', { name: 'Modifier', exact: true }).click()
  await offerForm.getByLabel('Statut').selectOption('negotiating')
  await offerForm.getByRole('button', { name: 'Enregistrer l’opportunité' }).click(); await approve()
  await expect(dossier.getByText('Campagne été · En négociation')).toBeVisible()
  await dossier.getByRole('button', { name: 'Fermer le dossier' }).click()
  await panel.getByRole('button', { name: 'Modifier', exact: true }).click()
  await sponsorForm.getByLabel('Statut').selectOption('archived')
  await sponsorForm.getByRole('button', { name: 'Enregistrer la fiche partenaire' }).click(); await approve()
  await panel.getByRole('button', { name: 'Ouvrir le dossier' }).click()
  await dossier.getByRole('button', { name: 'Historique des échanges', exact: true }).click()
  await expect(dossier.getByText(summary, { exact: true })).toBeVisible()
  await expect(dossier.getByRole('button', { name: 'Consigner un échange', exact: true })).toHaveCount(0)
  await expect(page.getByRole('heading', { name: 'Personnes et profils' })).toHaveCount(0)
  for (const button of await page.getByRole('button', { name: 'Fermer la notification', exact: true }).all()) await button.click()
  await noOverflow()
  await dossier.screenshot({ path: 'reports/spon001-history-mobile.png', style: 'header { visibility: hidden }' })
  expect(writes).toBe(6); expect(errors).toEqual([])
})
