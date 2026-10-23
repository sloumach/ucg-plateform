import { createHmac } from 'node:crypto'
import { expect, type BrowserContext, type Page } from '@playwright/test'

export const api = process.env.UCG_BROWSER_API_URL!
export const frontend = process.env.UCG_BROWSER_FRONT_URL!

export function fixtureCode(): string {
  // Independent RFC 6238 client, using the existing UserFactory test secret.
  const counter = Buffer.alloc(8)
  counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30_000)))
  const digest = createHmac('sha1', '12345678901234567890').update(counter).digest()
  const offset = digest[digest.length - 1] & 15
  return String((digest.readUInt32BE(offset) & 0x7fffffff) % 1_000_000).padStart(6, '0')
}

export async function constrainOrigins(context: BrowserContext): Promise<string[]> {
  const unexpected: string[] = []
  await context.route('**/*', async (route) => {
    const origin = new URL(route.request().url()).origin
    if (![api, frontend].includes(origin)) {
      unexpected.push(origin)
      await route.abort()
    } else {
      await route.continue()
    }
  })
  return unexpected
}

export async function login(page: Page, email: string): Promise<void> {
  await page.goto('/')
  await page.getByLabel('Adresse e-mail').fill(email)
  await page.getByLabel('Mot de passe', { exact: true }).fill('Browser-fixture-only-2026!')
  await page.getByRole('button', { name: 'Se connecter', exact: true }).click()
  await expect(page.getByLabel('Code de l’application (6 chiffres)')).toBeVisible()
  await page.getByLabel('Code de l’application (6 chiffres)').fill(fixtureCode())
  await page.getByRole('button', { name: 'Valider le code' }).click()
  await expect(page.getByRole('heading', { name: 'Mes organisations' })).toBeVisible()
}
