import { expect, test } from '@playwright/test'

test('le MFA bloque les organisations puis accepte un code validé par l’API', async ({ page }) => {
  let verified = false
  const headers = {
    'Access-Control-Allow-Origin': 'http://localhost:5173',
    'Access-Control-Allow-Credentials': 'true',
    'Access-Control-Allow-Methods': 'GET, POST',
    'Access-Control-Allow-Headers': 'Content-Type, X-XSRF-TOKEN',
  }
  const meta = { request_id: 'mfa-browser', current_page: 1, last_page: 1, total: 0 }
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const path = new URL(route.request().url()).pathname.replace('/api/v1/', '')
    if (route.request().method() === 'OPTIONS') {
      await route.fulfill({ status: 204, headers })
      return
    }
    let data: unknown = []
    if (path === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (path === 'auth/me') data = {
      id: 42, name: 'MFA operator', email: 'operator@example.test', email_verified: true,
      mfa: { required: true, enabled: true, verified },
    }
    if (path === 'auth/mfa/challenge') {
      if (route.request().postDataJSON().code !== '012345') {
        await route.fulfill({
          status: 422, headers,
          json: { message: 'Code invalide.', code: 'VALIDATION_FAILED', errors: { code: ['Code invalide.'] }, meta },
        })
        return
      }
      verified = true
      data = { required: true, enabled: true, verified }
    }
    if (path === 'active-organization') data = { context: null, revision: null, confirmed: false }
    await route.fulfill({ headers, json: { data, meta } })
  })

  await page.goto('/')
  await expect(page.getByLabel('Code de l’application (6 chiffres)')).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Mes organisations' })).toHaveCount(0)
  await page.getByLabel('Code de l’application (6 chiffres)').fill('654321')
  await page.getByRole('button', { name: 'Valider le code' }).click()
  await expect(page.getByLabel('Code de l’application (6 chiffres)')).toBeFocused()
  await page.getByLabel('Code de l’application (6 chiffres)').fill('012345')
  await page.getByRole('button', { name: 'Valider le code' }).click()
  await expect(page.getByText('MFA activé et session vérifiée.')).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Mes organisations' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Désactiver le MFA' })).toHaveCount(0)
})