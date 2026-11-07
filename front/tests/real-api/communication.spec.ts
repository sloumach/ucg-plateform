import { readFileSync } from 'node:fs'
import { expect, test, type Page, type BrowserContext } from '@playwright/test'
import { api, constrainOrigins, frontend, login } from './support'

const fixture = JSON.parse(readFileSync(process.env.UCG_BROWSER_ROOT + '/fixture.json', 'utf8')) as {
  communication: { alpha: string; beta: string; activity: string; betaActivity: string; day: string }
}
const data = fixture.communication

async function exerciseTask(page: Page, context: BrowserContext, mediaId: string) {
  const tasks = page.getByRole('region', { name: 'Tâches internes', exact: true })
  await tasks.getByRole('button', { name: 'Ouvrir les tâches', exact: true }).click()
  await tasks.getByRole('button', { name: 'Créer une tâche', exact: true }).click()
  const form = tasks.getByRole('form', { name: 'Créer une tâche', exact: true })
  await form.getByLabel('Titre de la tâche', { exact: true }).fill('Dossier de tournoi réel')
  await form.getByLabel('Description de la tâche', { exact: true }).fill('<script>Préparer les pièces</script>')
  await form.getByLabel('Échéance', { exact: true }).fill('2026-12-15')
  await form.getByRole('button', { name: 'Enregistrer la tâche', exact: true }).click()
  const created = page.waitForResponse((response) => response.url().endsWith('/tasks') && response.request().method() === 'POST')
  await tasks.getByRole('button', { name: 'Confirmer et continuer', exact: true }).click()
  const response = await created
  expect(response.status()).toBe(201)
  const task = (await response.json()).data as { id: string; version: string }
  const card = tasks.getByRole('listitem').filter({ has: page.getByRole('heading', { name: 'Dossier de tournoi réel', exact: true }) })
  await card.getByRole('button', { name: 'Modifier la tâche', exact: true }).click()
  const edit = tasks.getByRole('form', { name: 'Modifier la tâche', exact: true })
  await edit.getByLabel('Statut de la tâche', { exact: true }).selectOption('done')
  await edit.getByRole('button', { name: 'Enregistrer la tâche', exact: true }).click()
  await tasks.getByRole('button', { name: 'Confirmer et continuer', exact: true }).click()
  await expect(card.getByText('Terminée', { exact: true })).toBeVisible()
  await card.getByRole('button', { name: 'Ouvrir la discussion', exact: true }).click()
  const discussion = card.getByRole('region', { name: 'Discussion liée à cet élément', exact: true })
  await expect(discussion.getByText('Aucun commentaire accessible.')).toBeVisible()
  await discussion.getByLabel('Message', { exact: true }).fill('Dossier vérifié et terminé')
  await discussion.getByRole('button', { name: 'Ajouter le commentaire', exact: true }).click()
  await discussion.getByRole('button', { name: 'Confirmer et continuer', exact: true }).click()
  await expect(discussion.getByText('Dossier vérifié et terminé', { exact: true })).toBeVisible()
  await discussion.getByRole('button', { name: 'Actualiser les communications', exact: true }).click()
  await expect(discussion.getByText('Dossier vérifié et terminé', { exact: true })).toBeVisible()
  expect(await tasks.locator('script,img,iframe').count()).toBe(0)
  await page.getByRole('button', { name: /Ouvrir Communication Beta/ }).click()
  await expect(page.getByRole('button', { name: 'Ouvrir les tâches', exact: true })).toBeVisible()
  const headers = { Accept: 'application/json', Origin: frontend }
  const selected = await context.request.get(api + '/api/v1/active-organization', { headers })
  const active = (await selected.json()).data
  expect(active.context.organization_id).toBe(data.beta)
  const revision = active.revision as string
  const denied = await context.request.get(api + '/api/v1/tenants/' + data.beta + '/tasks/' + task.id, { headers: { ...headers, 'X-Tenant-Revision': revision } })
  expect(denied.status(), await denied.text()).toBe(404)
  const hiddenMedia = await context.request.get(api + '/api/v1/tenants/' + data.beta + '/media/' + mediaId, { headers: { ...headers, 'X-Tenant-Revision': revision } })
  expect(hiddenMedia.status()).toBe(404)
}

async function exerciseMedia(page: Page): Promise<string> {
  const media = page.getByRole('region', { name: 'Streams, VOD et clips', exact: true })
  await media.getByRole('button', { name: 'Ouvrir les médias', exact: true }).click()
  await media.getByRole('button', { name: 'Nouveau média', exact: true }).click()
  const form = media.getByRole('form', { name: 'Enregistrer le média', exact: true })
  await form.getByLabel('Titre du média', { exact: true }).fill('VOD de l’événement réel')
  await expect(form.getByRole('option', { name: 'Équipe communication', exact: true })).toHaveCount(1)
  await form.getByLabel('Équipe associée', { exact: true }).selectOption({ label: 'Équipe communication' })
  await form.getByLabel('Lien HTTPS du média', { exact: true }).fill('https://youtu.be/abcdefghijk')
  await form.getByLabel('Rattachement complémentaire', { exact: true }).selectOption('event')
  await expect(form.getByRole('option', { name: 'Événement de discussion', exact: true })).toHaveCount(1)
  await form.getByLabel('Match ou événement associé', { exact: true }).selectOption(data.activity)
  await form.getByRole('button', { name: 'Enregistrer le média', exact: true }).click()
  const created = page.waitForResponse((response) => response.url().endsWith('/media') && response.request().method() === 'POST')
  await media.getByRole('button', { name: 'Confirmer et continuer', exact: true }).click()
  const response = await created
  expect(response.status()).toBe(201)
  const item = (await response.json()).data as { id: string; context_id: string }
  expect(item.context_id).toBe(data.activity)
  const calendar = page.getByRole('region', { name: 'Calendrier des activités', exact: true })
  const card = calendar.getByRole('listitem').filter({ has: page.getByRole('heading', { name: 'Événement de discussion', exact: true }) })
  await card.getByRole('button', { name: 'Voir les médias associés', exact: true }).click()
  const attached = card.getByRole('region', { name: 'Médias associés', exact: true })
  await expect(attached.getByText('VOD de l’événement réel', { exact: true })).toBeVisible()
  await attached.getByRole('button', { name: 'Actualiser les médias', exact: true }).click()
  await expect(attached.getByText('VOD de l’événement réel', { exact: true })).toBeVisible()
  expect(await attached.locator('iframe,script').count()).toBe(0)
  return item.id
}

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
  const mediaId = await exerciseMedia(page)
  await exerciseTask(page, context, mediaId)
  expect(errors).toEqual([])
  expect(unexpected).toEqual([])
})
