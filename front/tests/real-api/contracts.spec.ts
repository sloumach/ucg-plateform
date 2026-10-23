import { readFileSync } from 'node:fs'
import { expect, test, type BrowserContext, type Page } from '@playwright/test'
import type { ContractEvent, ContractRecord, ContractStatus } from '../../src/api/contractRecords'
import type { ContractFinancialTerms } from '../../src/api/contractGovernance'
import { api, constrainOrigins, frontend, login } from './support'

const fixture = JSON.parse(readFileSync(`${process.env.UCG_BROWSER_ROOT}/fixture.json`, 'utf8')) as {
  foreignPerson: string
  contract: { alpha: string; beta: string; responsible: string; today: string; betaContract: string; foreignContract: string }
}
const data = fixture.contract
const plainHeaders = { Accept: 'application/json', Origin: frontend }
test.use({ timezoneId: 'UTC' })

async function headers(context: BrowserContext, confirm = false) {
  const response = await context.request.get(`${api}/api/v1/active-organization`, { headers: plainHeaders })
  expect(response.ok()).toBe(true)
  const active = (await response.json()).data
  const csrf = (await context.cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN')
  expect(csrf).toBeDefined()
  const result = { ...plainHeaders, 'X-Tenant-Revision': active.revision as string, 'X-XSRF-TOKEN': decodeURIComponent(csrf!.value) }
  if (confirm) {
    const confirmation = await context.request.post(`${api}/api/v1/active-organization/confirmation`, {
      headers: result, data: { organization_id: active.context.organization_id, revision: active.revision, confirm: true },
    })
    expect(confirmation.ok()).toBe(true)
  }
  return result
}

async function approve<T>(page: Page, url: string, method: 'POST' | 'PATCH', status: number): Promise<T> {
  const confirmation = page.getByRole('region', { name: 'Confirmer l’action', exact: true })
  await expect(confirmation).toContainText('Contract Alpha')
  const pending = page.waitForResponse((response) => response.url() === url && response.request().method() === method)
  await confirmation.getByRole('button', { name: 'Confirmer et continuer', exact: true }).click()
  const response = await pending
  expect(response.status(), await response.text()).toBe(status)
  return (await response.json()).data as T
}

function panel(page: Page) { return page.getByRole('region', { name: 'Contrats de l’organisation', exact: true }) }
function detail(page: Page) { return panel(page).getByRole('region', { name: 'Consulter le dossier', exact: true }) }

async function openContract(page: Page, title: string) {
  await panel(page).getByRole('button', { name: 'Contrats de l’organisation', exact: true }).click()
  await panel(page).getByRole('heading', { name: title, exact: true }).locator('..').getByRole('button', { name: 'Consulter le dossier', exact: true }).click()
  await expect(detail(page).getByRole('heading', { name: title, exact: true })).toBeVisible()
}

async function transition(page: Page, url: string, status: ContractStatus) {
  const form = detail(page).getByRole('form', { name: 'Enregistrer le changement de statut', exact: true })
  await form.getByLabel('Prochain statut', { exact: true }).selectOption(status)
  if (status === 'signed') {
    await form.getByLabel('Date de la signature reçue', { exact: true }).fill(data.today)
    await form.getByLabel('Référence de l’original signé reçu', { exact: true }).fill('Original fictif BROWSER-CONTRACT-001')
    await form.getByLabel('Je confirme la réception d’une signature obtenue hors de la plateforme.', { exact: true }).check()
  }
  if (status === 'terminated') await form.getByLabel('Date de résiliation déclarée').fill(data.today)
  await form.getByRole('button', { name: 'Enregistrer le changement de statut', exact: true }).click()
  const record = await approve<ContractRecord>(page, `${url}/transitions`, 'POST', 200)
  expect(record.status).toBe(status)
  return record
}

test('contrat réel : préparation, confidentialité financière, approbation indépendante et clôture', async ({ page, context, browser }) => {
  test.setTimeout(180_000)
  const unexpected = await constrainOrigins(context)
  const errors: string[] = []
  page.on('pageerror', (error) => errors.push(error.message))
  await page.setViewportSize({ width: 375, height: 812 })
  await login(page, 'browser-contract@example.test')
  await page.getByRole('button', { name: /Ouvrir Contract Alpha/ }).click()
  await expect(page.getByText('Organisation active : Contract Alpha')).toBeVisible()
  const root = `${api}/api/v1/tenants/${data.alpha}/contracts`
  await panel(page).getByRole('button', { name: 'Contrats de l’organisation', exact: true }).click()
  await panel(page).getByRole('button', { name: 'Créer un dossier de contrat', exact: true }).click()
  const form = panel(page).getByRole('form', { name: 'Enregistrer le dossier de contrat', exact: true })
  await form.getByLabel('Référence stable', { exact: true }).fill('browser-contract-001')
  await form.getByLabel('Intitulé du dossier', { exact: true }).fill('Contrat navigateur réel')
  await form.getByLabel('Date de début', { exact: true }).fill(data.today)
  await form.getByLabel('Date de fin incluse', { exact: true }).fill('2099-12-31')
  await form.getByLabel('Responsable du suivi', { exact: true }).selectOption(data.responsible)
  await form.getByLabel('Origine de la partie', { exact: true }).selectOption('person')
  await form.getByLabel('Personne de l’organisation', { exact: true }).selectOption(data.responsible)
  await form.getByRole('button', { name: 'Ajouter une obligation', exact: true }).click()
  await form.getByLabel('Intitulé de l’obligation', { exact: true }).fill('Contrôle du rapport fictif')
  await form.getByLabel('Échéance de l’obligation', { exact: true }).fill(data.today)
  for (const width of [375, 320]) {
    await page.setViewportSize({ width, height: 812 })
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
  }
  await form.getByRole('button', { name: 'Enregistrer le dossier de contrat', exact: true }).click()
  const created = await approve<ContractRecord>(page, root, 'POST', 201)
  expect(created.status).toBe('draft')
  expect(created.parties?.[0].person_id).toBe(data.responsible)
  expect(created.pending_obligations_count).toBe(1)
  const url = `${root}/${created.id}`
  const alphaHeaders = await headers(context)
  for (const foreign of [data.betaContract, data.foreignContract]) {
    const hidden = await context.request.get(`${root}/${foreign}`, { headers: alphaHeaders })
    expect(hidden.status()).toBe(404)
    expect(await hidden.text()).not.toMatch(/Beta contrat|Foreign contrat/)
  }
  const invalidPerson = await context.request.post(root, {
    headers: alphaHeaders, data: { reference: 'foreign-contract', type: 'player', title: 'Must never persist', responsible_person_id: fixture.foreignPerson,
      parties: [{ kind: 'external', name: 'Partie fictive' }], obligations: [] },
  })
  expect(invalidPerson.status()).toBe(404)
  const unchanged = await context.request.get(root, { headers: alphaHeaders })
  expect(unchanged.ok()).toBe(true)
  expect((await unchanged.json()).data.map((record: ContractRecord) => record.id)).toEqual([created.id])

  await detail(page).getByRole('button', { name: 'Modifier le dossier', exact: true }).click()
  const edit = detail(page).getByRole('form', { name: 'Enregistrer le dossier de contrat', exact: true })
  await edit.getByLabel('Intitulé du dossier', { exact: true }).fill('Contrat navigateur vérifié')
  await edit.getByRole('button', { name: 'Enregistrer le dossier de contrat', exact: true }).click()
  const updated = await approve<ContractRecord>(page, url, 'PATCH', 200)
  expect(updated.title).toBe('Contrat navigateur vérifié')
  const stale = await context.request.patch(url, { headers: alphaHeaders, data: { version: created.version, title: 'Must never persist' } })
  expect(stale.status()).toBe(409)
  expect((await stale.json()).code).toBe('CONTRACT_VERSION_STALE')

  await detail(page).getByRole('button', { name: 'Conditions financières restreintes', exact: true }).click()
  const finance = detail(page).getByRole('form', { name: 'Enregistrer les conditions financières', exact: true })
  await finance.getByLabel('Rémunération', { exact: true }).fill('1234.56')
  await finance.getByLabel('Périodicité de la rémunération', { exact: true }).selectOption('monthly')
  await finance.getByLabel('Modalités de paiement sensibles', { exact: true }).fill('PRIVATE_BROWSER_PAYMENT_TERMS')
  await finance.getByRole('button', { name: 'Enregistrer les conditions financières', exact: true }).click()
  const terms = await approve<ContractFinancialTerms>(page, `${url}/financial-terms`, 'PATCH', 200)
  expect(terms.terms.remuneration).toBe('1234.56')
  const staleFinance = await context.request.patch(`${url}/financial-terms`, { headers: alphaHeaders,
    data: { contract_version: updated.version, expected_version: terms.version, terms: terms.terms } })
  expect(staleFinance.status()).toBe(409)
  expect((await staleFinance.json()).code).toBe('CONTRACT_VERSION_STALE')
  const reviewed = await transition(page, url, 'in_review')
  const staleTerms = await context.request.patch(`${url}/financial-terms`, { headers: alphaHeaders,
    data: { contract_version: reviewed.version, expected_version: null, terms: terms.terms } })
  expect(staleTerms.status()).toBe(409)
  expect((await staleTerms.json()).code).toBe('CONTRACT_FINANCIAL_VERSION_STALE')
  const selfApproval = await context.request.post(`${url}/transitions`, { headers: alphaHeaders, data: { version: reviewed.version, status: 'approved' } })
  expect(selfApproval.status()).toBe(409)
  expect((await selfApproval.json()).code).toBe('CONTRACT_SELF_APPROVAL_FORBIDDEN')
  await expect(detail(page).getByLabel('Prochain statut').locator('option[value="approved"]')).toHaveCount(0)

  const administratorContext = await browser.newContext({ viewport: { width: 320, height: 812 }, timezoneId: 'UTC', serviceWorkers: 'block' })
  const administratorUnexpected = await constrainOrigins(administratorContext)
  try {
    const administrator = await administratorContext.newPage()
    administrator.on('pageerror', (error) => errors.push(error.message))
    await login(administrator, 'browser-contract-admin@example.test')
    await administrator.getByRole('button', { name: /Ouvrir Contract Alpha/ }).click()
    await openContract(administrator, updated.title)
    await expect(detail(administrator).getByRole('button', { name: 'Conditions financières restreintes', exact: true })).toHaveCount(0)
    await expect(administrator.locator('body')).not.toContainText('PRIVATE_BROWSER_PAYMENT_TERMS')
    await expect(administrator.locator('body')).not.toContainText('1234.56')
    const restrictedHeaders = await headers(administratorContext, true)
    for (const path of [root, url, `${url}/transitions`]) {
      const ordinary = await administratorContext.request.get(path, { headers: restrictedHeaders })
      expect(ordinary.ok()).toBe(true)
      expect(await ordinary.text()).not.toMatch(/1234\.56|PRIVATE_BROWSER_PAYMENT_TERMS/)
    }
    for (const method of ['get', 'patch'] as const) {
      const denied = await administratorContext.request[method](`${url}/financial-terms`, { headers: restrictedHeaders,
        ...(method === 'patch' ? { data: { contract_version: reviewed.version, expected_version: terms.version, terms: terms.terms } } : {}) })
      expect(denied.status()).toBe(403)
    }
    const deniedApproval = await administratorContext.request.post(`${url}/transitions`, { headers: restrictedHeaders, data: { version: reviewed.version, status: 'approved' } })
    expect(deniedApproval.status()).toBe(403)
    expect(administratorUnexpected).toEqual([])
  } finally {
    await administratorContext.close()
  }

  const reviewerContext = await browser.newContext({ viewport: { width: 320, height: 812 }, timezoneId: 'UTC', serviceWorkers: 'block' })
  const reviewerUnexpected = await constrainOrigins(reviewerContext)
  try {
    const reviewer = await reviewerContext.newPage()
    reviewer.on('pageerror', (error) => errors.push(error.message))
    await login(reviewer, 'browser-contract-reviewer@example.test')
    await reviewer.getByRole('button', { name: /Ouvrir Contract Alpha/ }).click()
    await openContract(reviewer, updated.title)
    await detail(reviewer).getByRole('button', { name: 'Conditions financières restreintes', exact: true }).click()
    const restricted = detail(reviewer).getByRole('region', { name: 'Conditions financières restreintes', exact: true })
    await expect(restricted).toContainText('1234.56')
    await expect(restricted).toContainText('PRIVATE_BROWSER_PAYMENT_TERMS')
    await expect(restricted.getByRole('form', { name: 'Enregistrer les conditions financières', exact: true })).toHaveCount(0)
    const approved = await transition(reviewer, url, 'approved')
    expect(approved.approved_by).not.toBe(created.prepared_by)
    expect(approved.approved_by).not.toBeNull()
    const frozen = await context.request.patch(`${url}/financial-terms`, { headers: alphaHeaders,
      data: { contract_version: approved.version, expected_version: terms.version, terms: terms.terms } })
    expect(frozen.status()).toBe(409)
    expect((await frozen.json()).code).toBe('CONTRACT_TERMS_FROZEN')
    await transition(reviewer, url, 'sent')
    const signed = await transition(reviewer, url, 'signed')
    expect(signed.signature_evidence_status).toBe('declared_external')
    expect(signed.in_force).toBe(false)
    const active = await transition(reviewer, url, 'active')
    expect(active.in_force).toBe(true)
    expect(active.sporting_eligibility).toBe('not_assessed')
    await transition(reviewer, url, 'terminated')
    expect(await reviewer.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
    expect(reviewerUnexpected).toEqual([])
  } finally {
    await reviewerContext.close()
  }

  await page.reload()
  await openContract(page, updated.title)
  const persistedResponse = await context.request.get(url, { headers: await headers(context) })
  expect(persistedResponse.ok()).toBe(true)
  const persisted = (await persistedResponse.json()).data as ContractRecord
  expect(persisted.status).toBe('terminated')
  expect(persisted.signature_reference).toBe('Original fictif BROWSER-CONTRACT-001')
  const pendingArchive = await context.request.post(`${url}/transitions`, { headers: await headers(context), data: { version: persisted.version, status: 'archived' } })
  expect(pendingArchive.status()).toBe(409)
  expect((await pendingArchive.json()).code).toBe('CONTRACT_PENDING_OBLIGATIONS')
  const obligation = detail(page).getByRole('form', { name: 'Obligation Contrôle du rapport fictif', exact: true })
  await obligation.getByLabel('État de l’obligation').selectOption('completed')
  await obligation.getByRole('button', { name: 'Enregistrer le suivi de l’obligation', exact: true }).click()
  const completed = await approve<ContractRecord>(page, `${url}/obligations/${persisted.obligations![0].id}`, 'PATCH', 200)
  expect(completed.pending_obligations_count).toBe(0)
  const archived = await transition(page, url, 'archived')
  expect(archived.obligations?.[0].status).toBe('completed')
  const archivedEdit = await context.request.patch(url, { headers: await headers(context), data: { version: archived.version, title: 'Must never persist' } })
  expect(archivedEdit.status()).toBe(409)
  expect((await archivedEdit.json()).code).toBe('CONTRACT_ARCHIVED')
  await page.reload()
  await openContract(page, updated.title)
  await expect(detail(page).getByRole('button', { name: 'Modifier le dossier', exact: true })).toHaveCount(0)
  await expect(detail(page)).toContainText('Archivé')
  await detail(page).getByRole('button', { name: 'Conditions financières restreintes', exact: true }).click()
  const retainedFinance = detail(page).getByRole('region', { name: 'Conditions financières restreintes', exact: true })
  await expect(retainedFinance).toContainText('1234.56')
  await expect(retainedFinance).toContainText('PRIVATE_BROWSER_PAYMENT_TERMS')
  await expect(retainedFinance.getByRole('form', { name: 'Enregistrer les conditions financières', exact: true })).toHaveCount(0)
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
  const historyResponse = await context.request.get(`${url}/transitions`, { headers: await headers(context) })
  expect(historyResponse.ok()).toBe(true)
  const history = (await historyResponse.json()).data as ContractEvent[]
  expect(history.map((event) => event.to_status).sort()).toEqual(['draft', 'in_review', 'approved', 'sent', 'signed', 'active', 'terminated', 'archived'].sort())
  await page.getByRole('button', { name: /Ouvrir Contract Beta/ }).click()
  await expect(page.getByText('Organisation active : Contract Beta')).toBeVisible()
  await openContract(page, 'Beta contrat privé')
  await expect(panel(page).getByRole('heading', { name: updated.title, exact: true })).toHaveCount(0)
  await expect(panel(page).getByRole('button', { name: 'Créer un dossier de contrat', exact: true })).toHaveCount(0)
  const denied = await context.request.post(`${api}/api/v1/tenants/${data.beta}/contracts`, { headers: await headers(context, true), data: {} })
  expect(denied.status()).toBe(403)
  const betaList = await context.request.get(`${api}/api/v1/tenants/${data.beta}/contracts`, { headers: await headers(context) })
  expect(betaList.ok()).toBe(true)
  expect((await betaList.json()).data.map((record: ContractRecord) => record.id)).toEqual([data.betaContract])
  expect(unexpected).toEqual([])
  expect(errors).toEqual([])
})
