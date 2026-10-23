import { readFileSync } from 'node:fs'
import { expect, test } from '@playwright/test'
import type { CalendarActivity } from '../../src/api/calendar'
import { api, constrainOrigins, frontend, login } from './support'

const fixture = JSON.parse(readFileSync(`${process.env.UCG_BROWSER_ROOT}/fixture.json`, 'utf8')) as {
  foreignPerson: string
  calendar: { alpha: string; beta: string; day: string; person: string; secondPerson: string; team: string; betaActivity: string; foreignActivity: string }
}
const data = fixture.calendar
test.use({ timezoneId: 'UTC' })

test('calendrier réel : indisponibilité, création, horaires et participants, annulation et isolation', async ({ page, context }) => {
  const unexpected = await constrainOrigins(context)
  const errors: string[] = []
  page.on('pageerror', (error) => errors.push(error.message))
  const root = `${api}/api/v1/tenants/${data.alpha}/calendar`
  const plainHeaders = { Accept: 'application/json', Origin: frontend }
  const from = data.day
  const to = new Date(new Date(`${from}T00:00:00Z`).getTime() + 86_400_000).toISOString().slice(0, 10)
  const dateWindow = `from_date=${from}&to_date=${to}`

  async function headers() {
    const response = await context.request.get(`${api}/api/v1/active-organization`, { headers: plainHeaders })
    expect(response.ok()).toBe(true)
    const active = (await response.json()).data
    const csrf = (await context.cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN')
    expect(csrf).toBeDefined()
    return { ...plainHeaders, 'X-Tenant-Revision': active.revision as string, 'X-XSRF-TOKEN': decodeURIComponent(csrf!.value) }
  }
  const panel = page.getByRole('region', { name: 'Calendrier des activités', exact: true })
  const filters = panel.getByRole('form', { name: 'Filtres du calendrier' })
  const form = panel.getByRole('form', { name: 'Enregistrer l’activité' })

  async function openCalendar() {
    await panel.getByRole('button', { name: 'Ouvrir le calendrier', exact: true }).click()
    await filters.getByLabel('Du (inclus)').fill(from)
    await filters.getByLabel('Au (exclu)').fill(to)
    await filters.getByLabel('Fuseau d’affichage (facultatif)').fill('Asia/Tokyo')
    await filters.getByRole('button', { name: 'Afficher la période', exact: true }).click()
    await expect(panel.getByText('Horaires affichés dans le fuseau : Asia/Tokyo', { exact: true })).toBeVisible()
  }
  async function approve(url: string, method: 'POST' | 'PATCH', status: number): Promise<CalendarActivity> {
    const confirmation = panel.getByRole('region', { name: 'Confirmer l’action', exact: true })
    await expect(confirmation).toContainText('Calendar Alpha')
    const pending = page.waitForResponse((response) => response.url() === url && response.request().method() === method)
    await confirmation.getByRole('button', { name: 'Confirmer et continuer', exact: true }).click()
    const response = await pending
    expect(response.status()).toBe(status)
    return (await response.json()).data as CalendarActivity
  }
  async function inspect() {
    const pending = page.waitForResponse((response) => response.url() === `${root}/activity-conflicts` && response.request().method() === 'POST')
    await form.getByRole('button', { name: 'Vérifier les conflits', exact: true }).click()
    const response = await pending
    expect(response.ok()).toBe(true)
    expect(await response.text()).not.toContain('PRIVATE_CALENDAR_AVAILABILITY_NOTE')
    return { report: (await response.json()).data, candidate: response.request().postDataJSON() as Record<string, unknown> }
  }

  await login(page, 'browser-calendar@example.test')
  await page.getByRole('button', { name: /Ouvrir Calendar Alpha/ }).click()
  await expect(page.getByText('Organisation active : Calendar Alpha')).toBeVisible()
  await openCalendar()
  await expect(panel.getByText('Aucune activité accessible dans cette période.', { exact: true })).toBeVisible()
  await panel.getByRole('button', { name: 'Nouvelle activité', exact: true }).click()
  await form.getByLabel('Titre de l’activité').fill('Activité calendrier réelle')
  await form.getByLabel('Fuseau IANA d’origine').fill('UTC')
  await form.getByLabel('Début dans le fuseau d’origine').fill(`${from}T10:00`)
  await form.getByLabel('Fin dans le fuseau d’origine').fill(`${from}T11:00`)
  await form.getByLabel('Équipe associée').selectOption(data.team)
  await form.getByLabel('Calendar player A', { exact: true }).check()
  await expect(form.getByLabel('Foreign private player', { exact: true })).toHaveCount(0)
  const conflict = await inspect()
  expect(conflict.report.availability_checked).toBe(true)
  expect(conflict.report.conflicts).toHaveLength(1)
  expect(conflict.report.can_override).toBe(false)
  await expect(form.getByText('Indisponibilité déclarée', { exact: true })).toBeVisible()
  await expect(form.getByRole('button', { name: 'Enregistrer l’activité', exact: true })).toBeDisabled()
  const alphaHeaders = await headers()
  const unreviewed = await context.request.post(`${root}/activities`, { headers: alphaHeaders, data: conflict.candidate })
  expect(unreviewed.status()).toBe(409)
  expect((await unreviewed.json()).code).toBe('TENANT_CONFIRMATION_REQUIRED')
  const confirmAlpha = await context.request.post(`${api}/api/v1/active-organization/confirmation`, {
    headers: alphaHeaders, data: { organization_id: data.alpha, revision: alphaHeaders['X-Tenant-Revision'], confirm: true },
  })
  expect(confirmAlpha.ok()).toBe(true)
  const noReview = await context.request.post(`${root}/activities`, { headers: alphaHeaders, data: conflict.candidate })
  expect(noReview.status()).toBe(409)
  expect((await noReview.json()).code).toBe('CALENDAR_CONFLICT_REVIEW_REQUIRED')
  const override = await context.request.post(`${root}/activities`, {
    headers: alphaHeaders, data: { ...conflict.candidate, conflict_review: conflict.report.review_token, conflict_reason: 'Tentative sans permission dédiée.', confirm_conflicts: true },
  })
  expect(override.status()).toBe(403)
  const empty = await context.request.get(`${root}/activities?${dateWindow}`, { headers: alphaHeaders })
  expect(empty.ok()).toBe(true)
  expect((await empty.json()).data).toEqual([])

  await form.getByLabel('Début dans le fuseau d’origine').fill(`${from}T12:00`)
  await form.getByLabel('Fin dans le fuseau d’origine').fill(`${from}T13:00`)
  const clear = await inspect()
  expect(clear.report.conflicts).toEqual([])
  for (const width of [375, 320]) {
    await page.setViewportSize({ width, height: 812 })
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
    await expect(form.getByRole('button', { name: 'Enregistrer l’activité', exact: true })).toBeEnabled()
  }
  await form.getByRole('button', { name: 'Enregistrer l’activité', exact: true }).click()
  const created = await approve(`${root}/activities`, 'POST', 201)
  expect(created.starts_at).toBe(`${from}T12:00:00+00:00`)
  expect(created.participants.map((person) => person.id)).toEqual([data.person])
  const card = panel.getByRole('heading', { name: 'Activité calendrier réelle', exact: true }).locator('..')
  await expect(card).toContainText('21:00')
  await card.getByRole('button', { name: 'Modifier l’activité', exact: true }).click()
  await form.getByLabel('Début dans le fuseau d’origine').fill(`${from}T14:00`)
  await form.getByLabel('Fin dans le fuseau d’origine').fill(`${from}T15:00`)
  await form.getByLabel('Motif opérationnel du changement').fill('Décalage du créneau et ajout du second participant.')
  await form.getByLabel('Calendar player B', { exact: true }).check()
  expect((await inspect()).report.conflicts).toEqual([])
  await form.getByRole('button', { name: 'Enregistrer l’activité', exact: true }).click()
  const updated = await approve(`${root}/activities/${created.id}`, 'PATCH', 200)
  expect(updated.starts_at).toBe(`${from}T14:00:00+00:00`)
  expect(updated.participants.map((person) => person.id).sort()).toEqual([data.person, data.secondPerson].sort())
  const stale = await context.request.patch(`${root}/activities/${created.id}`, { headers: alphaHeaders, data: { version: created.version, title: 'Must never persist' } })
  expect(stale.status()).toBe(409)
  expect((await stale.json()).code).toBe('CALENDAR_VERSION_STALE')
  for (const foreign of [data.betaActivity, data.foreignActivity]) {
    const hidden = await context.request.get(`${root}/activities/${foreign}`, { headers: alphaHeaders })
    expect(hidden.status()).toBe(404)
    expect(await hidden.text()).not.toMatch(/Beta calendrier|Foreign calendrier/)
  }
  const foreignParticipant = await context.request.post(`${root}/activity-conflicts`, {
    headers: alphaHeaders, data: { ...clear.candidate, participant_ids: [fixture.foreignPerson] },
  })
  expect(foreignParticipant.status()).toBe(404)

  await page.reload()
  await openCalendar()
  await expect(card).toContainText('23:00')
  await expect(card).toContainText('Calendar player A')
  await expect(card).toContainText('Calendar player B')
  const persistedHeaders = await headers()
  const persisted = await context.request.get(`${root}/activities/${created.id}`, { headers: persistedHeaders })
  expect(persisted.ok()).toBe(true)
  expect((await persisted.json()).data.version).toBe(updated.version)
  await card.getByRole('button', { name: 'Annuler l’activité', exact: true }).click()
  const cancellation = panel.getByRole('form', { name: 'Annulation de l’activité' })
  await cancellation.getByLabel('Motif opérationnel du changement').fill('Annulation du créneau de test uniquement.')
  await cancellation.getByRole('button', { name: 'Annuler l’activité', exact: true }).click()
  const cancelled = await approve(`${root}/activities/${created.id}`, 'PATCH', 200)
  expect(cancelled.archived_at).not.toBeNull()
  const archivedEdit = await context.request.patch(`${root}/activities/${created.id}`, {
    headers: persistedHeaders, data: { version: cancelled.version, title: 'Must never persist' },
  })
  expect(archivedEdit.status()).toBe(409)
  expect((await archivedEdit.json()).code).toBe('CALENDAR_ARCHIVED')
  await expect(panel.getByText('Aucune activité accessible dans cette période.', { exact: true })).toBeVisible()
  await page.reload()
  await openCalendar()
  await expect(panel.getByText('Aucune activité accessible dans cette période.', { exact: true })).toBeVisible()
  await filters.getByLabel('Périmètre de consultation').selectOption('1')
  await filters.getByLabel('Afficher les archives en mode gestion').check()
  await filters.getByRole('button', { name: 'Afficher la période', exact: true }).click()
  await expect(card.getByText('Activité annulée', { exact: true })).toBeVisible()
  await expect(card.getByRole('button', { name: 'Modifier l’activité', exact: true })).toHaveCount(0)
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
  const history = await context.request.get(`${root}/activities/${created.id}/changes`, { headers: persistedHeaders })
  expect(history.ok()).toBe(true)
  expect((await history.json()).data.map((change: { kind: string }) => change.kind).sort()).toEqual(['cancelled', 'rescheduled'])

  await page.getByRole('button', { name: /Ouvrir Calendar Beta/ }).click()
  await expect(page.getByText('Organisation active : Calendar Beta')).toBeVisible()
  await openCalendar()
  await expect(panel.getByRole('heading', { name: 'Beta calendrier privé', exact: true })).toBeVisible()
  await expect(panel.getByText('Activité calendrier réelle', { exact: true })).toHaveCount(0)
  await expect(panel.getByRole('button', { name: 'Nouvelle activité', exact: true })).toHaveCount(0)
  const betaHeaders = await headers()
  const confirmed = await context.request.post(`${api}/api/v1/active-organization/confirmation`, {
    headers: betaHeaders, data: { organization_id: data.beta, revision: betaHeaders['X-Tenant-Revision'], confirm: true },
  })
  expect(confirmed.ok()).toBe(true)
  const denied = await context.request.post(`${api}/api/v1/tenants/${data.beta}/calendar/activities`, { headers: betaHeaders, data: { ...clear.candidate, team_id: null, participant_ids: [] } })
  expect(denied.status()).toBe(403)
  const betaList = await context.request.get(`${api}/api/v1/tenants/${data.beta}/calendar/activities?${dateWindow}`, { headers: betaHeaders })
  expect(betaList.ok()).toBe(true)
  expect((await betaList.json()).data.map((item: CalendarActivity) => item.id)).toEqual([data.betaActivity])
  await expect(page.locator('body')).not.toContainText('PRIVATE_CALENDAR_AVAILABILITY_NOTE')
  expect(unexpected).toEqual([])
  expect(errors).toEqual([])
})
