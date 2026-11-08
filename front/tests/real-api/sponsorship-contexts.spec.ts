import { readFileSync } from 'node:fs'
import { expect, test, type BrowserContext, type Page } from '@playwright/test'
import { api, constrainOrigins, frontend, login } from './support'

const data = (JSON.parse(readFileSync(process.env.UCG_BROWSER_ROOT + '/fixture.json', 'utf8')) as {
  sponsorship: { alpha: string; beta: string; sponsor: string; agreement: string; activity: string; media: string; author: number; start: string; end: string }
}).sponsorship
const root = '/api/v1/tenants/' + data.alpha + '/sponsors/' + data.sponsor + '/agreements/' + data.agreement

async function openAgreement(page: Page) {
  await page.getByRole('button', { name: /Ouvrir Sponsor Alpha/ }).click()
  const partners = page.getByRole('region', { name: 'Prospects et partenariats', exact: true })
  await partners.getByRole('button', { name: 'Ouvrir les prospects', exact: true }).click()
  await partners.getByRole('button', { name: 'Ouvrir le dossier', exact: true }).click()
  await partners.getByRole('button', { name: 'Accords', exact: true }).click()
  await partners.getByRole('button', { name: 'Ouvrir l’accord', exact: true }).click()
  return partners
}

async function headers(context: BrowserContext, organizationId = data.alpha) {
  const base = { Accept: 'application/json', Origin: frontend }
  const selected = await context.request.get(api + '/api/v1/active-organization', { headers: base })
  expect(selected.ok()).toBe(true)
  const active = (await selected.json()).data
  expect(active.context.organization_id).toBe(organizationId)
  const revision = active.revision as string
  const cookie = (await context.cookies(api)).find((item) => item.name === 'XSRF-TOKEN')!
  return { ...base, 'X-Tenant-Revision': revision, 'X-XSRF-TOKEN': decodeURIComponent(cookie.value) }
}

test('accord sponsor réel : événement et média, approbation indépendante, preuve et isolation', async ({ page, context, browser }) => {
  const external = await constrainOrigins(context), errors: string[] = []
  page.on('pageerror', (error) => errors.push(error.message))
  await page.setViewportSize({ width: 375, height: 844 })
  await login(page, 'browser-sponsor@example.test')
  // The initial auth/me probe is unauthenticated; observe console failures throughout the authenticated journey.
  page.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()) })
  const partners = await openAgreement(page)
  const scopes = partners.getByRole('region', { name: 'Périmètres d’exposition', exact: true })
  const ids: string[] = []
  for (const [type, id, label] of [['event', data.activity, 'Événement sponsor'], ['content', data.media, '<script>VOD sponsor</script>']]) {
    await scopes.getByRole('button', { name: 'Ajouter un périmètre', exact: true }).click()
    const form = scopes.getByRole('form', { name: 'Enregistrer le périmètre', exact: true })
    await form.getByLabel('Périmètre concerné', { exact: true }).selectOption(type)
    await expect(form.getByRole('option', { name: label, exact: true })).toHaveCount(1)
    await form.getByLabel('Événement ou média autorisé', { exact: true }).selectOption(id)
    await form.getByLabel('Référence de l’autorisation vérifiée', { exact: true }).fill('Autorisation du partenaire vérifiée manuellement')
    await form.getByRole('button', { name: 'Enregistrer le périmètre', exact: true }).click()
    const created = page.waitForResponse((response) => response.url().endsWith('/exposures') && response.request().method() === 'POST')
    await scopes.getByRole('button', { name: 'Confirmer et continuer', exact: true }).click()
    const response = await created
    expect(response.status(), await response.text()).toBe(201)
    const saved = (await response.json()).data
    expect(saved.target_type).toBe(type); expect(saved.target_id).toBe(id); expect(saved.target_current).toBe(true)
    ids.push(saved.id)
    await expect(form).toHaveCount(0)
    await expect(scopes.getByText(label + ' · Site web', { exact: true })).toBeVisible()
  }
  const reviewer = await browser.newContext({ viewport: { width: 375, height: 844 } })
  try {
    const reviewerExternal = await constrainOrigins(reviewer), review = await reviewer.newPage()
    review.on('pageerror', (error) => errors.push(error.message))
    await login(review, 'browser-sponsor-reviewer@example.test')
    review.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()) })
    const reviewPartners = await openAgreement(review)
    const approval = reviewPartners.getByRole('form', { name: 'Approuver l’accord et ses expositions', exact: true })
    await approval.getByLabel('Je confirme avoir vérifié les autorisations de tous les périmètres et les conditions financières.', { exact: true }).check()
    await approval.getByRole('button', { name: 'Approuver l’accord et ses expositions', exact: true }).click()
    await reviewPartners.getByRole('button', { name: 'Confirmer et continuer', exact: true }).click()
    await expect(reviewPartners.getByRole('heading', { name: /accord-sponsor.*Approuvé/ })).toBeVisible()
    const authorHeaders = await headers(context), reviewerHeaders = await headers(reviewer)
    const approved = (await (await context.request.get(api + root, { headers: authorHeaders })).json()).data
    const campaignResponse = await context.request.post(api + root + '/campaigns', { headers: authorHeaders, data: { agreement_version: approved.version,
      title: 'Campagne navigateur', starts_on: data.start, ends_on: data.end, responsible_user_id: data.author } })
    expect(campaignResponse.status(), await campaignResponse.text()).toBe(201)
    const campaign = (await campaignResponse.json()).data
    const path = root + '/campaigns/' + campaign.id + '/deliverables'
    const promiseResponse = await context.request.post(api + path, { headers: authorHeaders, data: { agreement_version: approved.version, campaign_version: campaign.version,
      title: 'Vidéo déclarée', kind: 'video', exposure_id: ids[1], responsible_user_id: data.author, due_at: data.end + 'T12:00:00Z' } })
    expect(promiseResponse.status(), await promiseResponse.text()).toBe(201)
    const promise = (await promiseResponse.json()).data
    const deliveredResponse = await context.request.post(api + path + '/' + promise.id + '/deliveries', { headers: authorHeaders,
      data: { agreement_version: approved.version, campaign_version: promise.campaign_version, version: promise.version, delivered_at: new Date(Date.now() - 1000).toISOString(), proof_reference: 'Preuve contrôlée manuellement' } })
    expect(deliveredResponse.status(), await deliveredResponse.text()).toBe(200)
    const delivered = (await deliveredResponse.json()).data
    const validated = await reviewer.request.post(api + path + '/' + promise.id + '/transitions', { headers: reviewerHeaders,
      data: { agreement_version: approved.version, campaign_version: delivered.campaign_version, version: delivered.version, target_status: 'validated', confirm_evidence: true } })
    expect(validated.status(), await validated.text()).toBe(200)
    expect((await validated.json()).data.validation_matches_agreement).toBe(true)
    expect(reviewerExternal).toEqual([])
  } finally { await reviewer.close() }
  expect(await partners.locator('script,img,iframe').count()).toBe(0)
  await page.setViewportSize({ width: 320, height: 844 })
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
  await page.getByRole('button', { name: /Ouvrir Sponsor Beta/ }).click()
  await expect(page.getByText('Organisation active : Sponsor Beta')).toBeVisible()
  const hidden = await context.request.get(api + root.replace(data.alpha, data.beta) + '/exposures', { headers: await headers(context, data.beta) })
  expect(hidden.status()).toBe(404)
  expect(external).toEqual([]); expect(errors).toEqual([])
})
