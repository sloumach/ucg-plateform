import { readFileSync } from 'node:fs'
import { expect, test } from '@playwright/test'
import { api, constrainOrigins, frontend, login } from './support'

const fixture = JSON.parse(readFileSync(process.env.UCG_BROWSER_ROOT + '/fixture.json', 'utf8')) as {
  communication: { alpha: string; beta: string; activity: string; betaActivity: string; day: string }
}
const data = fixture.communication

test('discussion réelle : calendrier, confirmation, commentaire persisté, annonce ciblée et isolation', async ({ page, context }) => {
  const unexpected = await constrainOrigins(context)
  const errors: string[] = []
  page.on('pageerror', (error) => errors.push(error.message))
  await login(page, 'browser-communication@example.test')
  await page.getByRole('button', { name: /Ouvrir Communication Alpha/ }).click()
  const calendar = page.getByRole('region', { name: 'Calendrier des activités', exact: true })
  await calendar.getByRole('button', { name: 'Ouvrir le calendrier', exact: true }).click()
  const filters = calendar.getByRole('form', { name: 'Filtres du calendrier' })
  await filters.getByLabel('Du (inclus)').fill(data.day)
  await filters.getByLabel('Au (exclu)').fill(new Date(new Date(data.day + 'T00:00:00Z').getTime() + 86400000).toISOString().slice(0, 10))
  await filters.getByRole('button', { name: 'Afficher la période', exact: true }).click()
  const card = calendar.getByRole('listitem').filter({ has: page.getByRole('heading', { name: 'Événement de discussion', exact: true }) })
  await card.getByRole('button', { name: 'Ouvrir la discussion', exact: true }).click()
  const discussion = card.getByRole('region', { name: 'Discussion liée à cet élément', exact: true })
  await expect(discussion.getByText('Aucun commentaire accessible.')).toBeVisible()
  await discussion.getByLabel('Message', { exact: true }).fill('<script>Commentaire réel</script>')
  await discussion.getByRole('button', { name: 'Ajouter le commentaire', exact: true }).click()
  const confirm = discussion.getByRole('region', { name: 'Confirmer l’action', exact: true })
  await expect(confirm).toContainText('Communication Alpha')
  const sent = page.waitForResponse((response) => response.url().endsWith('/communication/discussions/event/' + data.activity) && response.request().method() === 'POST')
  await confirm.getByRole('button', { name: 'Confirmer et continuer', exact: true }).click()
  expect((await sent).status()).toBe(201)
  await expect(discussion.getByText('<script>Commentaire réel</script>', { exact: true })).toBeVisible()
  await discussion.getByRole('button', { name: 'Actualiser les communications', exact: true }).click()
  await expect(discussion.getByText('<script>Commentaire réel</script>', { exact: true })).toBeVisible()
  expect(await discussion.locator('script,img,iframe').count()).toBe(0)
  const plainHeaders = { Accept: 'application/json', Origin: frontend }
  const selected = await context.request.get(api + '/api/v1/active-organization', { headers: plainHeaders })
  expect(selected.ok()).toBe(true)
  const revision = (await selected.json()).data.revision as string
  const denied = await context.request.get(api + '/api/v1/tenants/' + data.alpha + '/communication/discussions/event/' + data.betaActivity, { headers: { ...plainHeaders, 'X-Tenant-Revision': revision } })
  expect(denied.status()).toBe(404)

  const communication = page.getByRole('region', { name: 'Annonces et discussions', exact: true })
  await communication.getByRole('button', { name: 'Ouvrir les communications', exact: true }).click()
  await communication.getByRole('button', { name: 'Gérer les annonces', exact: true }).click()
  await communication.getByRole('button', { name: 'Nouvelle annonce', exact: true }).click()
  const form = communication.getByRole('form', { name: 'Enregistrer le brouillon', exact: true })
  await form.getByLabel('Titre de l’annonce', { exact: true }).fill('Annonce de l’événement')
  await form.getByLabel('Message', { exact: true }).fill('Préparation de la séance')
  await form.getByLabel('Type de destinataires', { exact: true }).selectOption('event')
  await expect(form.getByRole('option', { name: 'Événement de discussion', exact: true })).toHaveCount(1)
  await form.getByLabel('Destinataire', { exact: true }).selectOption(data.activity)
  await form.getByRole('button', { name: 'Enregistrer le brouillon', exact: true }).click()
  const created = page.waitForResponse((response) => response.url().endsWith('/communication/announcements') && response.request().method() === 'POST')
  await communication.getByRole('button', { name: 'Confirmer et continuer', exact: true }).click()
  const result = await created
  expect(result.status()).toBe(201)
  expect((await result.json()).data.target_id).toBe(data.activity)
  await expect(communication.getByRole('heading', { name: 'Annonce de l’événement', exact: true })).toBeVisible()
  expect(errors).toEqual([])
  expect(unexpected).toEqual([])
})
