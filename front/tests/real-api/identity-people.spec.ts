import { readFileSync } from 'node:fs'
import { expect, test } from '@playwright/test'
import { api, constrainOrigins, fixtureCode, frontend } from './support'

const fixture = JSON.parse(readFileSync(`${process.env.UCG_BROWSER_ROOT}/fixture.json`, 'utf8')) as {
  alpha: string; beta: string; foreign: string; betaPerson: string; foreignPerson: string
}

test('connexion réelle, MFA, création persistée et isolation lors du changement de tenant', async ({ page, context }) => {
  const unexpectedOrigins = await constrainOrigins(context)
  const headers = { Accept: 'application/json', Origin: frontend }
  const guest = await context.request.get(`${api}/api/v1/tenants/${fixture.alpha}/people`, { headers })
  expect(guest.status()).toBe(401)
  await page.goto('/')
  await page.getByLabel('Adresse e-mail').fill('browser-staff@example.test')
  await page.getByLabel('Mot de passe', { exact: true }).fill('wrong-fixture-password')
  const rejectedLogin = page.waitForResponse((response) => response.url().endsWith('/auth/login') && response.request().method() === 'POST')
  await page.getByRole('button', { name: 'Se connecter', exact: true }).click()
  expect((await rejectedLogin).status()).toBe(422)
  await expect(page.getByRole('heading', { name: 'Mes organisations' })).toHaveCount(0)

  await page.getByLabel('Mot de passe', { exact: true }).fill('Browser-fixture-only-2026!')
  await page.getByRole('button', { name: 'Se connecter', exact: true }).click()
  await expect(page.getByLabel('Code de l’application (6 chiffres)')).toBeVisible()
  const beforeMfa = await context.request.get(`${api}/api/v1/accessible-organizations`, { headers })
  expect(beforeMfa.status()).toBe(403)
  expect((await beforeMfa.json()).code).toBe('MFA_CHALLENGE_REQUIRED')
  await page.getByLabel('Code de l’application (6 chiffres)').fill(fixtureCode())
  await page.getByRole('button', { name: 'Valider le code' }).click()
  await page.getByRole('button', { name: /Ouvrir Browser Alpha/ }).click()
  await expect(page.getByText('Organisation active : Browser Alpha')).toBeVisible()
  await expect(page.getByRole('button', { name: /Ouvrir Browser Foreign/ })).toHaveCount(0)
  const panel = page.getByRole('region', { name: 'Personnes', exact: true })
  await panel.getByRole('button', { name: 'Ajouter une personne' }).click()
  await panel.getByLabel('Pseudonyme', { exact: true }).fill('Alpha browser player')
  await panel.getByRole('button', { name: 'Enregistrer la fiche', exact: true }).click()
  const confirmation = panel.getByRole('region', { name: 'Confirmer l’action' })
  await expect(confirmation).toContainText('Browser Alpha')
  const createdResponse = page.waitForResponse((response) => response.url().endsWith(`/tenants/${fixture.alpha}/people`) && response.request().method() === 'POST')
  await confirmation.getByRole('button', { name: 'Confirmer et continuer' }).click()
  const created = await createdResponse
  expect(created.status()).toBe(201)
  const createdPerson = (await created.json()).data
  await page.reload()
  await expect(panel.getByText('Alpha browser player', { exact: true })).toBeVisible()
  expect((await context.cookies()).some((cookie) => cookie.name === 'ucg_browser_session' && cookie.httpOnly)).toBe(true)

  const csrf = (await context.cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN')
  expect(csrf).toBeDefined()
  const mutationHeaders = { ...headers, 'X-XSRF-TOKEN': decodeURIComponent(csrf!.value) }
  const current = await (await context.request.get(`${api}/api/v1/active-organization`, { headers })).json()
  const revision = current.data.revision as string
  const tenantHeaders = { ...mutationHeaders, 'X-Tenant-Revision': revision }
  const payload = { pseudonym: 'Must never persist', language: 'fr', timezone: 'UTC', status: 'candidate' }
  const foreignTenant = await context.request.get(`${api}/api/v1/tenants/${fixture.foreign}/people`, { headers: tenantHeaders })
  expect(foreignTenant.status()).toBe(404)
  const noCsrf = await context.request.post(`${api}/api/v1/tenants/${fixture.alpha}/people`, { headers: { ...headers, 'X-Tenant-Revision': revision }, data: payload })
  expect(noCsrf.status()).toBe(419)
  for (const personId of [fixture.betaPerson, fixture.foreignPerson, '99999999-9999-4999-8999-999999999999']) {
    const hidden = await context.request.get(`${api}/api/v1/tenants/${fixture.alpha}/people/${personId}`, { headers: tenantHeaders })
    expect(hidden.status()).toBe(404)
    expect(await hidden.text()).not.toMatch(/Beta private|Foreign private/)
  }

  await page.getByRole('button', { name: /Ouvrir Browser Beta/ }).click()
  await expect(page.getByText('Organisation active : Browser Beta')).toBeVisible()
  await expect(panel.getByText('Beta private player', { exact: true })).toBeVisible()
  await expect(panel.getByText('Alpha browser player', { exact: true })).toHaveCount(0)
  await expect(panel.getByRole('button', { name: 'Ajouter une personne' })).toHaveCount(0)
  const stale = await context.request.post(`${api}/api/v1/tenants/${fixture.alpha}/people`, { headers: tenantHeaders, data: payload })
  expect(stale.status()).toBe(409)
  const beta = (await (await context.request.get(`${api}/api/v1/active-organization`, { headers })).json()).data
  const confirmed = await context.request.post(`${api}/api/v1/active-organization/confirmation`, {
    headers: mutationHeaders, data: { organization_id: fixture.beta, revision: beta.revision, confirm: true },
  })
  expect(confirmed.ok()).toBe(true)
  const forbidden = await context.request.post(`${api}/api/v1/tenants/${fixture.beta}/people`, {
    headers: { ...mutationHeaders, 'X-Tenant-Revision': beta.revision }, data: payload,
  })
  expect(forbidden.status()).toBe(403)
  const betaList = await context.request.get(`${api}/api/v1/tenants/${fixture.beta}/people`, { headers: { ...headers, 'X-Tenant-Revision': beta.revision } })
  expect(betaList.ok()).toBe(true)
  expect((await betaList.json()).data.map((person: { pseudonym: string }) => person.pseudonym)).toEqual(['Beta private player'])
  for (const width of [375, 320]) {
    await page.setViewportSize({ width, height: 812 })
    await expect(panel.getByText('Beta private player', { exact: true })).toBeVisible()
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
  }
  await page.getByRole('button', { name: /Ouvrir Browser Alpha/ }).click()
  await panel.getByRole('button', { name: 'Ouvrir la fiche Alpha browser player', exact: true }).click()
  const returned = (await (await context.request.get(`${api}/api/v1/active-organization`, { headers })).json()).data
  const readHeaders = { ...headers, 'X-Tenant-Revision': returned.revision }
  const persisted = await context.request.get(`${api}/api/v1/tenants/${fixture.alpha}/people/${createdPerson.id}`, { headers: readHeaders })
  expect(persisted.ok()).toBe(true)
  expect((await persisted.json()).data.pseudonym).toBe('Alpha browser player')
  const alphaList = await context.request.get(`${api}/api/v1/tenants/${fixture.alpha}/people`, { headers: readHeaders })
  expect(alphaList.ok()).toBe(true)
  expect((await alphaList.json()).data).toHaveLength(1)
  await page.getByRole('button', { name: 'Se déconnecter', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Connexion', exact: true })).toBeVisible()
  const endedSession = await context.request.get(`${api}/api/v1/auth/me`, { headers })
  expect(endedSession.status()).toBe(401)
  expect(unexpectedOrigins).toEqual([])
})
