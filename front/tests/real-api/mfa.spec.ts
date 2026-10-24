import { createHmac } from 'node:crypto'
import { readFileSync } from 'node:fs'
import { expect, test, type BrowserContext, type Locator, type Page } from '@playwright/test'
import { api, constrainOrigins, frontend } from './support'

const fixture = JSON.parse(readFileSync(`${process.env.UCG_BROWSER_ROOT}/fixture.json`, 'utf8')) as { mfa: { organization: string } }
const password = 'Browser-fixture-only-2026!'
const plainHeaders = { Accept: 'application/json', Origin: frontend }
test.use({ timezoneId: 'UTC', actionTimeout: 15_000 })
let throttledRequests = 0

// Independent RFC 6238 client: the enrollment secret comes from the real UI.
function totp(secret: string, step = Math.floor(Date.now() / 30_000)): string {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'
  const bits = [...secret].map((character) => alphabet.indexOf(character).toString(2).padStart(5, '0')).join('')
  const key = Buffer.from(bits.match(/.{8}/g)!.map((byte) => parseInt(byte, 2)))
  const counter = Buffer.alloc(8)
  counter.writeBigUInt64BE(BigInt(step))
  const digest = createHmac('sha1', key).update(counter).digest()
  const offset = digest[digest.length - 1] & 15
  return String((digest.readUInt32BE(offset) & 0x7fffffff) % 1_000_000).padStart(6, '0')
}

async function headers(context: BrowserContext) {
  const csrf = (await context.cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN')
  expect(csrf).toBeDefined()
  return { ...plainHeaders, 'X-XSRF-TOKEN': decodeURIComponent(csrf!.value) }
}
async function passwordLogin(page: Page) {
  await page.goto('/')
  await page.getByLabel('Adresse e-mail', { exact: true }).fill('browser-mfa@example.test')
  await page.getByLabel('Mot de passe', { exact: true }).fill(password)
  await page.getByRole('button', { name: 'Se connecter', exact: true }).click()
  await expect(page.getByRole('region', { name: 'Authentification à deux facteurs', exact: true })).toBeVisible()
}
async function mutation(page: Page, action: string, button: Locator, status: number, onRetry?: () => Promise<void>) {
  async function send() {
    const pending = page.waitForResponse((response) => response.url() === `${api}/api/v1/auth/mfa/${action}` && response.request().method() === 'POST')
    await button.click()
    return pending
  }
  let response = await send()
  if (response.status() === 429) {
    throttledRequests++
    const seconds = Number(response.headers()['retry-after'])
    expect(Number.isInteger(seconds)).toBe(true)
    expect(seconds).toBeGreaterThanOrEqual(1)
    expect(seconds).toBeLessThanOrEqual(60)
    const retryAt = Date.now() + seconds * 1000
    await expect.poll(() => Date.now(), { timeout: seconds * 1000 + 3000, intervals: [1000] }).toBeGreaterThanOrEqual(retryAt)
    await onRetry?.()
    response = await send()
  }
  expect(response.status()).toBe(status)
  return response
}

test('MFA réel : activation, TOTP, récupération à usage unique et révocation des sessions', async ({ page, context, browser }) => {
  test.setTimeout(360_000)
  const unexpected = await constrainOrigins(context)
  const errors: string[] = []
  page.on('pageerror', (error) => errors.push(error.message))
  await page.setViewportSize({ width: 375, height: 812 })
  await passwordLogin(page)
  const panel = page.getByRole('region', { name: 'Authentification à deux facteurs', exact: true })
  const workspace = page.getByRole('heading', { name: 'Mes organisations', exact: true })
  await expect(workspace).toHaveCount(0)
  const denied = await context.request.get(`${api}/api/v1/accessible-organizations`, { headers: plainHeaders })
  expect(denied.status()).toBe(403)
  expect((await denied.json()).code).toBe('MFA_ENROLLMENT_REQUIRED')
  await panel.getByLabel('Mot de passe', { exact: true }).fill('wrong-browser-password')
  await mutation(page, 'enrollment', panel.getByRole('button', { name: 'Configurer le MFA', exact: true }), 422)
  await panel.getByLabel('Mot de passe', { exact: true }).fill(password)
  const enrollmentResponse = await mutation(page, 'enrollment', panel.getByRole('button', { name: 'Configurer le MFA', exact: true }), 200)
  expect(enrollmentResponse.headers()['cache-control']).toContain('no-store')
  const secret = (await panel.getByLabel('Clé de configuration MFA', { exact: true }).innerText()).trim()
  expect(/^[A-Z2-7]+$/.test(secret)).toBe(true)
  expect(secret.length).toBeGreaterThanOrEqual(16)
  for (const width of [375, 320]) {
    await page.setViewportSize({ width, height: 812 })
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
  }
  const otherContext = await browser.newContext({ viewport: { width: 320, height: 812 }, timezoneId: 'UTC', serviceWorkers: 'block' })
  const otherUnexpected = await constrainOrigins(otherContext)
  try {
    const other = await otherContext.newPage()
    other.on('pageerror', (error) => errors.push(error.message))
    await passwordLogin(other)
    const wrongSession = await otherContext.request.post(`${api}/api/v1/auth/mfa/confirmation`, { headers: await headers(otherContext), data: { code: totp(secret) } })
    expect(wrongSession.status()).toBe(409)
    expect((await wrongSession.json()).code).toBe('MFA_ENROLLMENT_EXPIRED')
    const currentStep = Math.floor(Date.now() / 30_000)
    const validWindow = new Set([-1, 0, 1].map((offset) => totp(secret, currentStep + offset)))
    const invalidCode = Array.from({ length: 10 }, (_, index) => String(index).padStart(6, '0')).find((code) => !validWindow.has(code))!
    await panel.getByLabel('Code de l’application (6 chiffres)', { exact: true }).fill(invalidCode)
    await mutation(page, 'confirmation', panel.getByRole('button', { name: 'Confirmer l’activation', exact: true }), 422)
    const confirmedStep = Math.floor(Date.now() / 30_000)
    const activationCode = totp(secret, confirmedStep)
    await panel.getByLabel('Code de l’application (6 chiffres)', { exact: true }).fill(activationCode)
    const activation = await mutation(page, 'confirmation', panel.getByRole('button', { name: 'Confirmer l’activation', exact: true }), 200)
    expect(activation.headers()['cache-control']).toContain('no-store')
    const recoveryRegion = panel.getByRole('region', { name: 'Codes de récupération', exact: true })
    await expect(recoveryRegion.getByRole('listitem')).toHaveCount(10)
    const codes = await recoveryRegion.getByRole('listitem').allTextContents()
    expect(new Set(codes).size).toBe(10)
    await recoveryRegion.getByRole('button', { name: 'J’ai conservé mes codes', exact: true }).click()
    await expect(workspace).toBeVisible()
    await page.reload()
    await expect(panel.getByText('MFA activé et session vérifiée.', { exact: true })).toBeVisible()
    await expect(recoveryRegion).toHaveCount(0)
    const revokedEnrollmentSession = await otherContext.request.get(`${api}/api/v1/auth/me`, { headers: plainHeaders })
    expect(revokedEnrollmentSession.status()).toBe(401)
    await passwordLogin(other)
    const otherPanel = other.getByRole('region', { name: 'Authentification à deux facteurs', exact: true })
    await otherPanel.getByLabel('Code de l’application (6 chiffres)', { exact: true }).fill(activationCode)
    await mutation(other, 'challenge', otherPanel.getByRole('button', { name: 'Valider le code', exact: true }), 422)
    const challengeRequired = await otherContext.request.get(`${api}/api/v1/accessible-organizations`, { headers: plainHeaders })
    expect(challengeRequired.status()).toBe(403)
    expect((await challengeRequired.json()).code).toBe('MFA_CHALLENGE_REQUIRED')
    await otherPanel.getByRole('button', { name: 'Utiliser un code de récupération', exact: true }).click()
    await otherPanel.getByLabel('Code de récupération', { exact: true }).fill(codes[0])
    await mutation(other, 'challenge', otherPanel.getByRole('button', { name: 'Valider le code', exact: true }), 200)
    await expect(other.getByRole('heading', { name: 'Mes organisations', exact: true })).toBeVisible()
    expect((await context.request.get(`${api}/api/v1/auth/me`, { headers: plainHeaders })).status()).toBe(401)
    await other.reload()
    await expect(otherPanel.getByText('MFA activé et session vérifiée.', { exact: true })).toBeVisible()

    await passwordLogin(page)
    await panel.getByRole('button', { name: 'Utiliser un code de récupération', exact: true }).click()
    await panel.getByLabel('Code de récupération', { exact: true }).fill(codes[0])
    await mutation(page, 'challenge', panel.getByRole('button', { name: 'Valider le code', exact: true }), 422)
    await panel.getByRole('button', { name: 'Utiliser l’application d’authentification', exact: true }).click()
    await expect.poll(() => Math.floor(Date.now() / 30_000), { timeout: 35_000, intervals: [250, 500] }).toBeGreaterThan(confirmedStep)
    await panel.getByLabel('Code de l’application (6 chiffres)', { exact: true }).fill(totp(secret))
    await mutation(page, 'challenge', panel.getByRole('button', { name: 'Valider le code', exact: true }), 200,
      () => panel.getByLabel('Code de l’application (6 chiffres)', { exact: true }).fill(totp(secret)))
    await expect(workspace).toBeVisible()
    await expect(panel.getByRole('button', { name: 'Désactiver le MFA', exact: true })).toHaveCount(0)
    const noDisable = await context.request.delete(`${api}/api/v1/auth/mfa`, { headers: await headers(context), data: { password } })
    expect(noDisable.status()).toBe(409)
    expect((await noDisable.json()).code).toBe('MFA_REQUIRED_FOR_PRIVILEGED_ACCOUNT')
    await panel.getByRole('button', { name: 'Renouveler les codes de récupération', exact: true }).click()
    await panel.getByLabel('Mot de passe', { exact: true }).fill(password)
    await mutation(page, 'recovery-codes', panel.getByRole('button', { name: 'Renouveler les codes de récupération', exact: true }), 200)
    await expect(recoveryRegion.getByRole('listitem')).toHaveCount(10)
    const renewed = await recoveryRegion.getByRole('listitem').allTextContents()
    expect(renewed.some((code) => codes.includes(code))).toBe(false)
    await recoveryRegion.getByRole('button', { name: 'J’ai conservé mes codes', exact: true }).click()
    expect((await otherContext.request.get(`${api}/api/v1/auth/me`, { headers: plainHeaders })).status()).toBe(401)
    await passwordLogin(other)
    await otherPanel.getByRole('button', { name: 'Utiliser un code de récupération', exact: true }).click()
    await otherPanel.getByLabel('Code de récupération', { exact: true }).fill(codes[1])
    await mutation(other, 'challenge', otherPanel.getByRole('button', { name: 'Valider le code', exact: true }), 422)
    await otherPanel.getByLabel('Code de récupération', { exact: true }).fill(renewed[0])
    await mutation(other, 'challenge', otherPanel.getByRole('button', { name: 'Valider le code', exact: true }), 200)
    await other.reload()
    await other.getByRole('button', { name: /Ouvrir MFA Alpha/ }).click()
    await expect(other.getByText('Organisation active : MFA Alpha')).toBeVisible()
    const activeResponse = await otherContext.request.get(`${api}/api/v1/active-organization`, { headers: plainHeaders })
    expect(activeResponse.ok()).toBe(true)
    expect((await activeResponse.json()).data.context.organization_id).toBe(fixture.mfa.organization)
    expect((await context.request.get(`${api}/api/v1/auth/me`, { headers: plainHeaders })).status()).toBe(401)
    const me = await otherContext.request.get(`${api}/api/v1/auth/me`, { headers: plainHeaders })
    expect(me.ok()).toBe(true)
    const account = (await me.json()).data
    expect(account.mfa).toEqual({ required: true, enabled: true, verified: true })
    expect(JSON.stringify(account)).not.toContain(secret)
    expect(JSON.stringify(account)).not.toContain(renewed[0])
    expect(await other.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
    await expect(otherPanel.getByRole('region', { name: 'Codes de récupération', exact: true })).toHaveCount(0)
    expect(otherUnexpected).toEqual([])
    expect(unexpected).toEqual([])
    expect(errors).toEqual([])
    expect(throttledRequests).toBeGreaterThan(0)
  } finally {
    await otherContext.close()
  }
})
