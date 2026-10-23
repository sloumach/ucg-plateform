import { readFileSync } from 'node:fs'
import { expect, test, type Locator } from '@playwright/test'
import type { TeamMembership } from '../../src/api/teamMemberships'
import { api, constrainOrigins, frontend, login } from './support'

const fixture = JSON.parse(readFileSync(`${process.env.UCG_BROWSER_ROOT}/fixture.json`, 'utf8')) as {
  foreignPerson: string
  roster: { alpha: string; beta: string; betaPerson: string; betaTeam: string; betaMembership: string; foreignTeam: string; game: string }
}
const data = fixture.roster
test.use({ timezoneId: 'UTC' })

test('personne et équipes réelles, transfert de roster, clôture et accès tenant limités', async ({ page, context }) => {
  const unexpected = await constrainOrigins(context)
  const pageErrors: string[] = []
  page.on('pageerror', (error) => pageErrors.push(error.message))
  const base = `${api}/api/v1/tenants/${data.alpha}`
  const plainHeaders = { Accept: 'application/json', Origin: frontend }

  async function headers() {
    const response = await context.request.get(`${api}/api/v1/active-organization`, { headers: plainHeaders })
    expect(response.ok()).toBe(true)
    const active = (await response.json()).data
    const csrf = (await context.cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN')
    expect(csrf).toBeDefined()
    return { ...plainHeaders, 'X-Tenant-Revision': active.revision as string, 'X-XSRF-TOKEN': decodeURIComponent(csrf!.value) }
  }

  async function approve<T extends { id: string; version: string } = { id: string; version: string }>(region: Locator, url: string, method: 'POST' | 'PATCH', status: number): Promise<T> {
    const confirmation = region.getByRole('region', { name: 'Confirmer l’action' })
    await expect(confirmation).toContainText('Roster Alpha')
    const pending = page.waitForResponse((response) => response.url() === url && response.request().method() === method)
    await confirmation.getByRole('button', { name: 'Confirmer et continuer' }).click()
    const response = await pending
    expect(response.status()).toBe(status)
    return (await response.json()).data as T
  }

  await login(page, 'browser-roster@example.test')
  await page.getByRole('button', { name: /Ouvrir Roster Alpha/ }).click()
  await expect(page.getByText('Organisation active : Roster Alpha')).toBeVisible()
  const people = page.getByRole('region', { name: 'Personnes', exact: true })
  await people.getByRole('button', { name: 'Ajouter une personne' }).click()
  await people.getByLabel('Pseudonyme', { exact: true }).fill('Roster browser player')
  await people.getByRole('form', { name: 'Enregistrer la fiche' }).getByLabel('Statut de la personne').selectOption('active')
  await people.getByRole('button', { name: 'Enregistrer la fiche', exact: true }).click()
  const person = await approve(people, `${base}/people`, 'POST', 201)
  await expect(people.getByText('Roster browser player', { exact: true })).toBeVisible()

  await page.getByRole('button', { name: 'Équipes de l’organisation', exact: true }).click()
  const teams = page.getByRole('region', { name: 'Équipes de l’organisation', exact: true })
  const savedTeams: { id: string; version: string; name: string }[] = []
  for (const [slug, name] of [['roster-source', 'Roster source'], ['roster-destination', 'Roster destination']]) {
    await teams.getByRole('button', { name: 'Créer une équipe', exact: true }).click()
    const form = teams.getByRole('form', { name: 'Enregistrer l’équipe' })
    await form.getByLabel('Jeu activé').selectOption(data.game)
    await form.getByLabel('Slug stable').fill(slug)
    await form.getByLabel('Saison', { exact: true }).fill('browser-2026')
    await form.getByLabel('Nom de l’équipe').fill(name)
    await form.getByLabel('Statut', { exact: true }).selectOption('active')
    if (slug === 'roster-source') {
      for (const width of [375, 320]) {
        await page.setViewportSize({ width, height: 812 })
        await expect(form.getByRole('button', { name: 'Enregistrer l’équipe', exact: true })).toBeVisible()
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
      }
    }
    await form.getByRole('button', { name: 'Enregistrer l’équipe', exact: true }).click()
    const team = await approve(teams, `${base}/teams`, 'POST', 201)
    savedTeams.push({ ...team, name })
    await expect(teams.getByRole('heading', { name, exact: true })).toBeVisible()
  }
  const [source, destination] = savedTeams
  const sourcePath = `${base}/teams/${source.id}`
  const destinationPath = `${base}/teams/${destination.id}`
  const sourceCard = teams.getByRole('heading', { name: source.name, exact: true }).locator('..')
  await sourceCard.getByRole('button', { name: 'Effectif et historique', exact: true }).click()
  const sourceRoster = page.getByRole('region', { name: `Effectif et historique ${source.name}`, exact: true })
  await sourceRoster.getByRole('button', { name: 'Ajouter une appartenance', exact: true }).click()
  const membershipForm = sourceRoster.getByRole('form', { name: 'Enregistrer l’appartenance' })
  await expect(membershipForm.getByRole('option', { name: 'Beta roster player', exact: true })).toHaveCount(0)
  await expect(membershipForm.getByRole('option', { name: 'Foreign private player', exact: true })).toHaveCount(0)
  await membershipForm.getByLabel('Personne de l’organisation').selectOption(person.id)
  const start = new Date(Date.now() - 86_400_000).toISOString().slice(0, 19)
  await membershipForm.getByLabel('Début de la période').fill(start)
  await membershipForm.getByRole('button', { name: 'Enregistrer l’appartenance', exact: true }).click()
  const membership = await approve(sourceRoster, `${sourcePath}/memberships`, 'POST', 201)
  await expect(sourceRoster.getByRole('heading', { name: 'Roster browser player · Titulaire', exact: true })).toBeVisible()

  const alphaHeaders = await headers()
  const payload = { person_id: person.id, role_key: 'player', starts_at: `${start}+00:00`, reason_key: 'initial' }
  const overlap = await context.request.post(`${sourcePath}/memberships`, { headers: alphaHeaders, data: payload })
  expect(overlap.status()).toBe(409)
  expect((await overlap.json()).code).toBe('TEAM_MEMBERSHIP_OVERLAP')
  const foreignPerson = await context.request.post(`${sourcePath}/memberships`, { headers: alphaHeaders, data: { ...payload, person_id: fixture.foreignPerson } })
  expect(foreignPerson.status()).toBe(404)
  const foreignTeam = await context.request.get(`${base}/teams/${data.foreignTeam}/memberships`, { headers: alphaHeaders })
  expect(foreignTeam.status()).toBe(404)
  const foreignMembership = await context.request.patch(`${sourcePath}/memberships/${data.betaMembership}`, {
    headers: alphaHeaders, data: { version: membership.version, action: 'close', reason_key: 'end_of_period', effective_at: new Date().toISOString().replace(/\.\d{3}Z$/, '+00:00') },
  })
  expect(foreignMembership.status()).toBe(404)

  await sourceRoster.getByRole('button', { name: 'Changer d’équipe', exact: true }).click()
  await membershipForm.getByLabel('Équipe de destination').selectOption(destination.id)
  await membershipForm.getByLabel('Fonction sportive', { exact: true }).selectOption('substitute')
  await membershipForm.getByRole('button', { name: 'Enregistrer l’appartenance', exact: true }).click()
  const transferred = await approve<TeamMembership>(sourceRoster, `${sourcePath}/memberships/${membership.id}`, 'PATCH', 201)
  await expect(sourceRoster.getByText('Clôturée · Non effective à la date consultée', { exact: true })).toBeVisible()
  const stale = await context.request.patch(`${sourcePath}/memberships/${membership.id}`, {
    headers: alphaHeaders, data: { version: membership.version, action: 'close', reason_key: 'end_of_period', effective_at: new Date().toISOString().replace(/\.\d{3}Z$/, '+00:00') },
  })
  expect(stale.status()).toBe(409)
  expect((await stale.json()).code).toBe('TEAM_MEMBERSHIP_VERSION_STALE')

  await teams.getByRole('heading', { name: destination.name, exact: true }).locator('..').getByRole('button', { name: 'Effectif et historique', exact: true }).click()
  const targetRoster = page.getByRole('region', { name: `Effectif et historique ${destination.name}`, exact: true })
  await expect(targetRoster.getByRole('heading', { name: 'Roster browser player · Remplaçant', exact: true })).toBeVisible()
  await expect.poll(() => Date.now()).toBeGreaterThan(new Date(transferred.starts_at).getTime() + 1000)
  await targetRoster.getByRole('button', { name: 'Clôturer la période', exact: true }).click()
  const closeForm = targetRoster.getByRole('form', { name: 'Enregistrer l’appartenance' })
  await closeForm.getByLabel('Date d’effet').fill(new Date().toISOString().slice(0, 19))
  await closeForm.getByRole('button', { name: 'Enregistrer l’appartenance', exact: true }).click()
  const closed = await approve<TeamMembership>(targetRoster, `${destinationPath}/memberships/${transferred.id}`, 'PATCH', 200)
  expect(closed.status).toBe('closed')
  await expect(targetRoster.getByRole('button', { name: 'Clôturer la période', exact: true })).toHaveCount(0)
  const terminal = await context.request.patch(`${destinationPath}/memberships/${closed.id}`, {
    headers: alphaHeaders, data: { version: closed.version, action: 'close', reason_key: 'end_of_period', effective_at: new Date().toISOString().replace(/\.\d{3}Z$/, '+00:00') },
  })
  expect(terminal.status()).toBe(409)
  expect((await terminal.json()).code).toBe('TEAM_MEMBERSHIP_IMMUTABLE')

  await page.reload()
  await page.getByRole('button', { name: 'Équipes de l’organisation', exact: true }).click()
  await teams.getByRole('heading', { name: destination.name, exact: true }).locator('..').getByRole('button', { name: 'Effectif et historique', exact: true }).click()
  await expect(targetRoster.getByRole('heading', { name: 'Roster browser player · Remplaçant', exact: true })).toBeVisible()
  await expect(targetRoster.getByText('Clôturée · Non effective à la date consultée', { exact: true })).toBeVisible()
  const persistedHeaders = await headers()
  for (const [path, actions] of [[sourcePath, ['created', 'transfer']], [destinationPath, ['close', 'transfer']]] as const) {
    const members = await context.request.get(`${path}/memberships`, { headers: persistedHeaders })
    expect(members.ok()).toBe(true)
    const periods = (await members.json()).data
    expect(periods).toHaveLength(1)
    expect(periods[0].status).toBe('closed')
    expect(periods[0].effective).toBe(false)
    expect(periods[0].role_key).toBe(path === sourcePath ? 'player' : 'substitute')
    if (path === sourcePath) expect(periods[0].ends_at).toBe(transferred.starts_at)
    else expect(periods[0].version).toBe(closed.version)
    const history = await context.request.get(`${path}/movements`, { headers: persistedHeaders })
    expect(history.ok()).toBe(true)
    expect((await history.json()).data.map((event: { action: string }) => event.action).sort()).toEqual([...actions].sort())
  }
  for (const width of [375, 320]) {
    await page.setViewportSize({ width, height: 812 })
    await expect(targetRoster.getByRole('heading', { name: 'Roster browser player · Remplaçant', exact: true })).toBeVisible()
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
  }

  await page.getByRole('button', { name: /Ouvrir Roster Beta/ }).click()
  await expect(page.getByText('Organisation active : Roster Beta')).toBeVisible()
  await expect(page.getByText(/Roster browser player/)).toHaveCount(0)
  await page.getByRole('button', { name: 'Équipes de l’organisation', exact: true }).click()
  await expect(teams.getByRole('button', { name: 'Créer une équipe', exact: true })).toHaveCount(0)
  await teams.getByRole('heading', { name: 'Beta roster team', exact: true }).locator('..').getByRole('button', { name: 'Effectif et historique', exact: true }).click()
  const betaRoster = page.getByRole('region', { name: 'Effectif et historique Beta roster team', exact: true })
  await expect(betaRoster.getByRole('heading', { name: 'Beta roster player · Titulaire', exact: true })).toBeVisible()
  await expect(betaRoster.getByRole('button', { name: 'Ajouter une appartenance', exact: true })).toHaveCount(0)
  const betaHeaders = await headers()
  const confirm = await context.request.post(`${api}/api/v1/active-organization/confirmation`, {
    headers: betaHeaders, data: { organization_id: data.beta, revision: betaHeaders['X-Tenant-Revision'], confirm: true },
  })
  expect(confirm.ok()).toBe(true)
  const denied = await context.request.post(`${api}/api/v1/tenants/${data.beta}/teams/${data.betaTeam}/memberships`, {
    headers: betaHeaders, data: { ...payload, person_id: data.betaPerson },
  })
  expect(denied.status()).toBe(403)
  const deniedTeam = await context.request.post(`${api}/api/v1/tenants/${data.beta}/teams`, {
    headers: betaHeaders, data: { game_id: data.game, slug: 'must-not-persist', season: 'browser-2026', name: 'Must not persist', kind: 'first', status: 'active' },
  })
  expect(deniedTeam.status()).toBe(403)
  const betaHistory = await context.request.get(`${api}/api/v1/tenants/${data.beta}/teams/${data.betaTeam}/movements`, { headers: betaHeaders })
  expect(betaHistory.ok()).toBe(true)
  expect((await betaHistory.json()).data).toEqual([])
  expect(unexpected).toEqual([])
  expect(pageErrors).toEqual([])
})
