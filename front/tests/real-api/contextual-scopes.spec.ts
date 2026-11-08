import { readFileSync } from 'node:fs'
import { expect, test, type BrowserContext } from '@playwright/test'
import { api, constrainOrigins, frontend, login } from './support'

const data = (JSON.parse(readFileSync(process.env.UCG_BROWSER_ROOT + '/fixture.json', 'utf8')) as {
  contextualScopes: { org: string; subject: number; roles: Record<'game' | 'cohort' | 'event', string>; game: string; cohort: string; event: string; hiddenCohort: string; hiddenEvent: string }
}).contextualScopes

async function headers(context: BrowserContext) {
  const base = { Accept: 'application/json', Origin: frontend }
  const response = await context.request.get(api + '/api/v1/active-organization', { headers: base })
  expect(response.ok()).toBe(true)
  const active = (await response.json()).data
  expect(active.context.organization_id).toBe(data.org)
  const cookie = (await context.cookies(api)).find((item) => item.name === 'XSRF-TOKEN')!
  return { ...base, 'X-Tenant-Revision': active.revision as string, 'X-XSRF-TOKEN': decodeURIComponent(cookie.value) }
}

test('IAM réel : attribuer les trois périmètres, modifier une cohorte limitée et révoquer les accès', async ({ page, context, browser }) => {
  const external = await constrainOrigins(context), errors: string[] = []
  page.on('pageerror', (error) => errors.push(error.message))
  await page.setViewportSize({ width: 375, height: 844 })
  await login(page, 'browser-scope-manager@example.test')
  page.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()) })
  await page.getByRole('button', { name: /Ouvrir Scopes Alpha/ }).click()
  const grants: { id: string; role_id: string; scope_type: string; scope_id: string; starts_at: string; ends_at: null; version: string }[] = []
  for (const type of ['game', 'cohort', 'event'] as const) {
    const form = page.getByRole('form', { name: 'Affecter un rôle', exact: true })
    await form.getByRole('combobox', { name: 'Périmètre', exact: true }).selectOption(type)
    await expect(form.getByRole('option', { name: 'Responsable limité ' + type, exact: true })).toHaveCount(1)
    await form.getByRole('combobox', { name: 'Rôle à affecter', exact: true }).selectOption(data.roles[type])
    await form.getByLabel('Identifiant du compte membre', { exact: true }).fill(String(data.subject))
    await expect(form.getByRole('option', { name: type === 'game' ? 'VALORANT' : type === 'cohort' ? 'Cohorte limitée' : 'Événement limité', exact: true })).toHaveCount(1)
    await form.getByRole('combobox', { name: 'Objet du périmètre', exact: true }).selectOption(data[type])
    await form.getByRole('button', { name: 'Affecter un rôle', exact: true }).click()
    const saved = page.waitForResponse((response) => response.url().endsWith('/role-assignments') && response.request().method() === 'POST')
    await page.getByRole('region', { name: 'Confirmer l’action', exact: true }).getByRole('button', { name: 'Confirmer et continuer', exact: true }).click()
    const response = await saved
    expect(response.status(), await response.text()).toBe(201)
    grants.push((await response.json()).data)
    await expect(page.getByRole('region', { name: 'Confirmer l’action', exact: true })).toHaveCount(0)
  }

  const reader = await browser.newContext({ viewport: { width: 375, height: 844 } })
  try {
    const readerExternal = await constrainOrigins(reader), view = await reader.newPage()
    view.on('pageerror', (error) => errors.push(error.message))
    await login(view, 'browser-scope-subject@example.test')
    view.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()) })
    await view.getByRole('button', { name: /Ouvrir Scopes Alpha/ }).click()
    const limited = view.getByRole('region', { name: 'Mes accès limités', exact: true })
    await limited.getByRole('button', { name: 'Mes accès limités', exact: true }).click()
    const academy = limited.getByRole('region', { name: 'Académie : cohortes et cycles', exact: true })
    await academy.getByRole('button', { name: 'Académie : cohortes et cycles', exact: true }).click()
    await expect(academy.getByRole('heading', { name: 'Cohorte limitée', exact: true })).toBeVisible()
    await expect(academy.getByText('Cohorte cachée', { exact: true })).toHaveCount(0)
    await expect(academy.getByRole('button', { name: 'Créer une cohorte', exact: true })).toHaveCount(0)
    await academy.getByRole('button', { name: 'Modifier Cohorte limitée', exact: true }).click()
    const edit = academy.getByRole('form', { name: 'Enregistrer la cohorte', exact: true })
    await edit.getByLabel('Nom', { exact: true }).fill('Cohorte modifiée avec accès limité')
    await edit.getByRole('button', { name: 'Enregistrer la cohorte', exact: true }).click()
    const changed = view.waitForResponse((response) => response.url().endsWith('/academy/cohorts/' + data.cohort) && response.request().method() === 'PATCH')
    await academy.getByRole('button', { name: 'Confirmer et continuer', exact: true }).click()
    expect((await changed).status()).toBe(200)
    await expect(academy.getByRole('heading', { name: 'Cohorte modifiée avec accès limité', exact: true })).toBeVisible()

    const calendar = limited.getByRole('region', { name: 'Calendrier des activités', exact: true })
    await calendar.getByRole('button', { name: 'Ouvrir le calendrier', exact: true }).click()
    await expect(calendar.getByRole('heading', { name: 'Événement limité', exact: true })).toBeVisible()
    await expect(calendar.getByText('Événement caché', { exact: true })).toHaveCount(0)
    const games = limited.getByRole('region', { name: 'Jeux de l’organisation', exact: true })
    await games.getByRole('button', { name: 'Jeux de l’organisation', exact: true }).click()
    await expect(games.getByText('VALORANT', { exact: true })).toBeVisible()
    await expect(games.locator('li')).toHaveCount(1)

    const readerHeaders = await headers(reader)
    expect((await reader.request.get(api + '/api/v1/tenants/' + data.org + '/calendar/activities/' + data.hiddenEvent, { headers: readerHeaders })).status()).toBe(404)
    expect((await reader.request.get(api + '/api/v1/tenants/' + data.org + '/academy/cohorts/' + data.hiddenCohort + '/cycles', { headers: readerHeaders })).status()).toBe(403)
    for (const grant of grants) {
      const revoked = await context.request.put(api + '/api/v1/tenants/' + data.org + '/role-assignments/' + grant.id,
        { headers: await headers(context), data: { role_id: grant.role_id, user_id: data.subject, scope_type: grant.scope_type, scope_id: grant.scope_id,
          starts_at: grant.starts_at, ends_at: grant.ends_at, version: grant.version, status: 'revoked' } })
      expect(revoked.status(), await revoked.text()).toBe(200)
    }
    expect((await reader.request.get(api + '/api/v1/tenants/' + data.org + '/games/' + data.game, { headers: await headers(reader) })).status()).toBe(403)
    await limited.getByRole('button', { name: 'Mes accès limités', exact: true }).click()
    await limited.getByRole('button', { name: 'Mes accès limités', exact: true }).click()
    await expect(limited.getByText('Aucun droit limité supplémentaire actif.', { exact: true })).toBeVisible()
    expect(readerExternal).toEqual([])
  } finally { await reader.close() }
  expect(external).toEqual([])
  expect(errors).toEqual([])
})
