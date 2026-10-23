import { readFileSync } from 'node:fs'
import { expect, test, type BrowserContext, type Page } from '@playwright/test'
import type { ReportExport, ReportExportPage } from '../../src/api/reportExports'
import { api, constrainOrigins, frontend, login } from './support'

const fixture = JSON.parse(readFileSync(`${process.env.UCG_BROWSER_ROOT}/fixture.json`, 'utf8')) as {
  export: { alpha: string; beta: string; from: string; to: string; person: string; personVersion: string; formulaPerson: string; betaPerson: string; expired: string }
}
const data = fixture.export
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
    const confirmed = await context.request.post(`${api}/api/v1/active-organization/confirmation`, {
      headers: result, data: { organization_id: active.context.organization_id, revision: active.revision, confirm: true },
    })
    expect(confirmed.ok()).toBe(true)
  }
  return result
}

const exportsPanel = (page: Page) => page.getByRole('region', { name: 'Exports privés de pilotage', exact: true })
async function openDashboard(page: Page) {
  const dashboard = page.getByRole('region', { name: 'Tableaux de bord par rôle', exact: true })
  await dashboard.getByRole('button', { name: 'Ouvrir les tableaux de bord', exact: true }).click()
  const filters = dashboard.getByRole('form', { name: 'Filtres du tableau de bord', exact: true })
  await filters.getByLabel('Début de la fenêtre').fill(data.from)
  await filters.getByLabel('Fin de la fenêtre').fill(data.to)
  await filters.getByRole('button', { name: 'Consulter le tableau de bord', exact: true }).click()
}

test('export réel : génération en file, CSV privé, idempotence, expiration et source modifiée', async ({ page, context, browser }) => {
  test.setTimeout(180_000)
  const unexpected = await constrainOrigins(context)
  const errors: string[] = []
  page.on('pageerror', (error) => errors.push(error.message))
  await page.setViewportSize({ width: 375, height: 812 })
  await login(page, 'browser-export@example.test')
  await page.getByRole('button', { name: /Ouvrir Export Alpha/ }).click()
  await expect(page.getByText('Organisation active : Export Alpha')).toBeVisible()
  await openDashboard(page)
  const panel = exportsPanel(page)
  await panel.getByRole('button', { name: 'Ouvrir mes exports', exact: true }).click()
  await expect(panel.getByText('Effectif actif · Direction · Expiré', { exact: true })).toBeVisible()
  await expect(panel.getByRole('button', { name: 'Télécharger le CSV', exact: true })).toHaveCount(0)
  const root = `${api}/api/v1/tenants/${data.alpha}/exports`
  const expired = await context.request.get(`${root}/${data.expired}/download`, { headers: await headers(context) })
  expect(expired.status()).toBe(409)
  expect((await expired.json()).code).toBe('REPORT_EXPORT_EXPIRED')

  async function requestExport(organization: string, organizationId: string) {
    const form = panel.getByRole('form', { name: 'Demande d’export', exact: true })
    await form.getByLabel('Rapport à exporter', { exact: true }).selectOption('active_people')
    await form.getByRole('checkbox').check()
    await form.getByRole('button', { name: 'Demander l’export CSV', exact: true }).click()
    const confirmation = panel.getByRole('region', { name: 'Confirmer l’action', exact: true })
    await expect(confirmation).toContainText(organization)
    const responseEvent = page.waitForResponse((response) => response.url() === `${api}/api/v1/tenants/${organizationId}/exports` && response.request().method() === 'POST')
    await confirmation.getByRole('button', { name: 'Confirmer et continuer', exact: true }).click()
    const response = await responseEvent
    expect(response.status(), await response.text()).toBe(200)
    const row = (await response.json()).data as ReportExport
    expect(row.state).toBe('queued')
    const requestHeaders = await headers(context)
    await expect.poll(async () => {
      const state = await context.request.get(`${api}/api/v1/tenants/${organizationId}/exports/${row.id}`, { headers: requestHeaders })
      expect(state.ok()).toBe(true)
      return (await state.json()).data.state
    }, { timeout: 30_000, intervals: [250, 500, 1000] }).toBe('completed')
    await panel.getByRole('button', { name: 'Actualiser mes exports', exact: true }).click()
    await expect(panel.getByText('Effectif actif · Direction · Prêt', { exact: true })).toBeVisible()
    return { row, body: response.request().postDataJSON() as Record<string, unknown> }
  }
  async function download(id: string) {
    const event = page.waitForEvent('download')
    await panel.getByRole('button', { name: 'Télécharger le CSV', exact: true }).click()
    const file = await event
    expect(file.suggestedFilename()).toBe(`report-${id}.csv`)
    expect(await file.failure()).toBeNull()
    const path = await file.path()
    expect(path).not.toBeNull()
    return readFileSync(path!, 'utf8')
  }
  for (const width of [375, 320]) {
    await page.setViewportSize({ width, height: 812 })
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
  }
  const first = await requestExport('Export Alpha', data.alpha)
  await expect(panel.getByText('Lignes : 2', { exact: true })).toBeVisible()
  const alphaHeaders = await headers(context)
  const repeated = await context.request.post(root, { headers: alphaHeaders, data: first.body })
  expect(repeated.ok()).toBe(true)
  expect((await repeated.json()).data.id).toBe(first.row.id)
  const listing = await context.request.get(root, { headers: alphaHeaders })
  expect(listing.ok()).toBe(true)
  const listed = (await listing.json()).data as ReportExportPage
  expect(listed.items.map((row) => row.id).sort()).toEqual([data.expired, first.row.id].sort())
  expect(JSON.stringify(listed)).not.toMatch(/artifact_id|digest|request_key|source_version/)
  const deniedReport = await context.request.post(root, { headers: alphaHeaders, data: { ...first.body, card: 'expiring_contracts', request_key: crypto.randomUUID() } })
  expect(deniedReport.status()).toBe(403)
  await page.reload()
  await openDashboard(page)
  await panel.getByRole('button', { name: 'Ouvrir mes exports', exact: true }).click()
  await expect(panel.getByText('Lignes : 2', { exact: true })).toBeVisible()
  const csv = await download(first.row.id)
  expect(csv.split('\r\n').filter(Boolean)).toHaveLength(3)
  expect(csv).toContain('organization_id;report;view;period_from;period_to;timezone;generated_at;id;label;state;at;value')
  expect(csv).toContain(data.alpha)
  expect(csv).toContain(data.person)
  expect(csv).toContain(data.formulaPerson)
  expect(csv).toContain('Export Alpha player')
  expect(csv).toContain(";'=1+1;")
  expect(csv).not.toMatch(/PRIVATE_EXPORT_IDENTITY|camille@example|Camille|Export Beta player/)

  for (const [email, permitted] of [['browser-export-colleague@example.test', true], ['browser-export-reader@example.test', false]] as const) {
    const otherContext = await browser.newContext({ viewport: { width: 320, height: 812 }, timezoneId: 'UTC', serviceWorkers: 'block' })
    const otherUnexpected = await constrainOrigins(otherContext)
    try {
      const other = await otherContext.newPage()
      other.on('pageerror', (error) => errors.push(error.message))
      await login(other, email)
      await other.getByRole('button', { name: /Ouvrir Export Alpha/ }).click()
      await openDashboard(other)
      const otherHeaders = await headers(otherContext, true)
      if (permitted) {
        await exportsPanel(other).getByRole('button', { name: 'Ouvrir mes exports', exact: true }).click()
        await expect(exportsPanel(other).getByText('Aucun export accessible.', { exact: true })).toBeVisible()
      } else {
        await expect(exportsPanel(other)).toHaveCount(0)
        const denied = await otherContext.request.post(root, { headers: otherHeaders, data: { ...first.body, request_key: crypto.randomUUID() } })
        expect(denied.status()).toBe(403)
      }
      for (const path of [`${root}/${first.row.id}`, `${root}/${first.row.id}/download`]) {
        const hidden = await otherContext.request.get(path, { headers: otherHeaders })
        expect(hidden.status()).toBe(permitted ? 404 : 403)
        expect(await hidden.text()).not.toContain('Export Alpha player')
      }
      expect(otherUnexpected).toEqual([])
    } finally {
      await otherContext.close()
    }
  }
  const changed = await context.request.patch(`${api}/api/v1/tenants/${data.alpha}/people/${data.person}`, {
    headers: await headers(context), data: { version: data.personVersion, pseudonym: 'Export Alpha updated' },
  })
  expect(changed.ok(), await changed.text()).toBe(true)
  const stale = await context.request.get(`${root}/${first.row.id}/download`, { headers: await headers(context) })
  expect(stale.status()).toBe(409)
  expect((await stale.json()).code).toBe('REPORT_EXPORT_STALE')
  await page.getByRole('button', { name: /Ouvrir Export Beta/ }).click()
  await expect(page.getByText('Organisation active : Export Beta')).toBeVisible()
  await openDashboard(page)
  await panel.getByRole('button', { name: 'Ouvrir mes exports', exact: true }).click()
  await expect(panel.getByText('Aucun export accessible.', { exact: true })).toBeVisible()
  const foreignDownload = await context.request.get(`${api}/api/v1/tenants/${data.beta}/exports/${first.row.id}/download`, { headers: await headers(context) })
  expect(foreignDownload.status()).toBe(404)
  const second = await requestExport('Export Beta', data.beta)
  expect(second.row.id).not.toBe(first.row.id)
  const betaCsv = await download(second.row.id)
  expect(betaCsv.split('\r\n').filter(Boolean)).toHaveLength(2)
  expect(betaCsv).toContain(data.betaPerson)
  expect(betaCsv).toContain('Export Beta player')
  expect(betaCsv).not.toContain(data.alpha)
  expect(betaCsv).not.toContain('Export Alpha')
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
  expect(unexpected).toEqual([])
  expect(errors).toEqual([])
})
