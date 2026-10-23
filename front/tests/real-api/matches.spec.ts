import { readFileSync } from 'node:fs'
import { expect, test, type BrowserContext, type Page } from '@playwright/test'
import type { MatchCorrection, MatchCorrectionPage, MatchGamePage, MatchLineupRevision, MatchRecord, MatchResult, MatchResultPage, MatchScorecard, MatchStatus } from '../../src/api/matches'
import { api, constrainOrigins, frontend, login } from './support'

const fixture = JSON.parse(readFileSync(`${process.env.UCG_BROWSER_ROOT}/fixture.json`, 'utf8')) as {
  foreignPerson: string
  match: { alpha: string; beta: string; day: string; responsible: string; game: string; teams: { team: string; lineup: string }[]; betaMatch: string; foreignMatch: string }
}
const data = fixture.match
const plainHeaders = { Accept: 'application/json', Origin: frontend }
test.use({ timezoneId: 'UTC' })

async function headers(context: BrowserContext) {
  const response = await context.request.get(`${api}/api/v1/active-organization`, { headers: plainHeaders })
  expect(response.ok()).toBe(true)
  const active = (await response.json()).data
  const csrf = (await context.cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN')
  expect(csrf).toBeDefined()
  return { ...plainHeaders, 'X-Tenant-Revision': active.revision as string, 'X-XSRF-TOKEN': decodeURIComponent(csrf!.value) }
}

async function approve<T>(page: Page, url: string, status: number): Promise<T> {
  const confirmation = page.getByRole('region', { name: 'Confirmer l’action', exact: true })
  await expect(confirmation).toContainText('Match Alpha')
  const pending = page.waitForResponse((response) => response.url() === url && response.request().method() === 'POST')
  await confirmation.getByRole('button', { name: 'Confirmer et continuer', exact: true }).click()
  const response = await pending
  expect(response.status(), await response.text()).toBe(status)
  return (await response.json()).data as T
}

test('match réel : compositions, scores, approbation indépendante, correction asynchrone et isolation', async ({ page, context, browser }) => {
  test.setTimeout(180_000)
  const unexpected = await constrainOrigins(context)
  const errors: string[] = []
  page.on('pageerror', (error) => errors.push(error.message))
  await page.setViewportSize({ width: 375, height: 812 })
  const root = `${api}/api/v1/tenants/${data.alpha}/matches`
  const panel = page.getByRole('region', { name: 'Scrims et matchs', exact: true })
  const card = panel.getByRole('heading', { name: 'Match navigateur réel', exact: true }).locator('..')
  const scores = panel.getByRole('region', { name: 'Parties et scores', exact: true })
  const results = panel.getByRole('region', { name: 'Résultats et validation', exact: true })
  const corrections = panel.getByRole('region', { name: 'Corrections des scores', exact: true })
  await login(page, 'browser-match@example.test')
  await page.getByRole('button', { name: /Ouvrir Match Alpha/ }).click()
  await expect(page.getByText('Organisation active : Match Alpha')).toBeVisible()
  await panel.getByRole('button', { name: 'Ouvrir les matchs', exact: true }).click()
  await panel.getByRole('button', { name: 'Nouveau match', exact: true }).click()
  const form = panel.getByRole('form', { name: 'Enregistrer le match', exact: true })
  await form.getByLabel('Titre du match', { exact: true }).fill('Match navigateur réel')
  await form.getByLabel('Jeu du match', { exact: true }).selectOption(data.game)
  await form.getByLabel('Format du match', { exact: true }).selectOption('bo1')
  await form.getByLabel('Responsable du match', { exact: true }).selectOption(data.responsible)
  await form.getByLabel('Fuseau IANA d’origine').fill('UTC')
  await form.getByLabel('Début dans le fuseau d’origine').fill(`${data.day}T10:00`)
  await form.getByLabel('Fin dans le fuseau d’origine').fill(`${data.day}T11:00`)
  await form.getByLabel('Nature du participant', { exact: true }).selectOption('team')
  for (const team of data.teams) {
    await form.getByLabel('Participant à ajouter', { exact: true }).selectOption(team.team)
    await form.getByRole('button', { name: 'Ajouter ce participant', exact: true }).click()
  }
  for (const width of [375, 320]) {
    await page.setViewportSize({ width, height: 812 })
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
  }
  await form.getByRole('button', { name: 'Enregistrer le match', exact: true }).click()
  const created = await approve<MatchRecord>(page, root, 201)
  expect(created.status).toBe('proposed')
  expect(created.starts_at).toBe(`${data.day}T10:00:00+00:00`)
  expect(created.participants.map((participant) => participant.source_id)).toEqual(data.teams.map((team) => team.team))
  const alphaHeaders = await headers(context)
  for (const foreign of [data.betaMatch, data.foreignMatch]) {
    const hidden = await context.request.get(`${root}/${foreign}`, { headers: alphaHeaders })
    expect(hidden.status()).toBe(404)
    expect(await hidden.text()).not.toMatch(/Beta match|Foreign match/)
  }
  const invalidParticipant = await context.request.post(root, {
    headers: alphaHeaders,
    data: { title: created.title, game_id: data.game, kind: created.kind, format: created.format,
      starts_at: created.starts_at, ends_at: created.ends_at, origin_timezone: created.origin_timezone,
      responsible_person_id: created.responsible_person_id,
      participants: [{ kind: 'person', source_id: fixture.foreignPerson }, { kind: 'team', source_id: data.teams[0].team }] },
  })
  expect(invalidParticipant.status()).toBe(404)
  const unchanged = await context.request.get(root, { headers: alphaHeaders })
  expect(unchanged.ok()).toBe(true)
  expect((await unchanged.json()).data.map((item: MatchRecord) => item.id)).toEqual([created.id])

  for (const [index, team] of data.teams.entries()) {
    const participant = created.participants[index]
    await card.getByRole('button', { name: `Composition et remplaçants · ${participant.label}`, exact: true }).click()
    const lineups = panel.getByRole('region', { name: 'Composition et remplaçants', exact: true })
    await lineups.getByRole('button', { name: 'Affecter une composition', exact: true }).click()
    const assignment = lineups.getByRole('form', { name: 'Affecter une composition', exact: true })
    await assignment.getByLabel('Composition finalisée', { exact: true }).selectOption(team.lineup)
    await assignment.getByLabel('Motif de la composition ou du remplacement').fill('Composition finalisée choisie pour cette rencontre.')
    await assignment.getByRole('button', { name: 'Enregistrer la composition', exact: true }).click()
    const saved = await approve<MatchLineupRevision>(page, `${root}/${created.id}/participants/${participant.id}/lineups`, 201)
    expect(saved.snapshot.id).toBe(team.lineup)
    expect(saved.snapshot.participants).toHaveLength(1)
  }
  const stale = await context.request.patch(`${root}/${created.id}`, { headers: alphaHeaders, data: { version: created.version, title: 'Must never persist' } })
  expect(stale.status()).toBe(409)
  expect((await stale.json()).code).toBe('MATCH_VERSION_STALE')

  async function transition(status: MatchStatus) {
    await card.getByRole('button', { name: 'Changer le statut du match', exact: true }).click()
    const transitionForm = panel.getByRole('form', { name: 'Changement de statut du match', exact: true })
    await transitionForm.getByLabel('Nouveau statut du match').selectOption(status)
    await transitionForm.getByLabel('Motif du changement de statut').fill('Progression contrôlée du parcours navigateur réel.')
    await transitionForm.getByRole('button', { name: 'Changer le statut du match', exact: true }).click()
    expect((await approve<MatchRecord>(page, `${root}/${created.id}/transitions`, 200)).status).toBe(status)
  }
  await transition('confirmed')
  await transition('in_progress')
  await card.getByRole('button', { name: 'Parties et scores', exact: true }).click()
  const rules = scores.getByRole('form', { name: 'Fixer les règles de score', exact: true })
  await rules.getByLabel('Motif des règles ou du résultat').fill('Une seule partie décisive, sans égalité autorisée.')
  await rules.getByRole('button', { name: 'Fixer les règles de score', exact: true }).click()
  expect((await approve<MatchScorecard>(page, `${root}/${created.id}/score-rules`, 201)).rules?.game_limit).toBe(1)
  await card.getByRole('button', { name: 'Parties et scores', exact: true }).click()
  const game = scores.getByRole('form', { name: 'Ajouter une partie', exact: true })
  await game.getByLabel('Carte de la partie').fill('Ascent')
  await game.getByLabel('Version du jeu').fill('Browser-2026')
  await game.getByLabel(`Score de la partie · ${created.participants[0].label}`, { exact: true }).fill('13')
  await game.getByLabel(`Score de la partie · ${created.participants[1].label}`, { exact: true }).fill('7')
  await game.getByLabel('Motif des règles ou du résultat').fill('Scores du rapport fictif conservé pour la recette.')
  await game.getByRole('button', { name: 'Enregistrer la partie', exact: true }).click()
  const scored = await approve<MatchScorecard>(page, `${root}/${created.id}/games`, 201)
  expect(scored.series?.winner_ids).toEqual([created.participants[0].id])
  expect(scored.game?.results).toHaveLength(2)
  expect(scored.game?.results.every((entry) => entry.lineup_revision_id !== null)).toBe(true)

  async function submit(reference: string) {
    await card.getByRole('button', { name: 'Résultats et validation', exact: true }).click()
    const submission = results.getByRole('form', { name: 'Soumettre le résultat', exact: true })
    await submission.getByLabel('Référence de preuve', { exact: true }).fill(reference)
    await submission.getByLabel('Je confirme que cette preuve est conservée et consultable par l’approbateur habilité.', { exact: true }).check()
    await submission.getByLabel('Motif de la proposition ou de la décision').fill('Rapport fictif vérifié avant soumission du résultat.')
    await submission.getByRole('button', { name: 'Soumettre le résultat', exact: true }).click()
    const submitted = await approve<MatchResult>(page, `${root}/${created.id}/results`, 201)
    expect(submitted.status).toBe('awaiting_validation')
    return submitted
  }
  const submitted = await submit('Rapport privé fictif MATCH-BROWSER-001')
  const selfApproval = await context.request.post(`${root}/${created.id}/results/approvals`, {
    headers: alphaHeaders, data: { version: submitted.match_version, submission_id: submitted.submission!.id, confirm_evidence: true, reason: 'Tentative par le soumissionnaire pourtant habilité.' },
  })
  expect(selfApproval.status()).toBe(409)
  expect((await selfApproval.json()).code).toBe('MATCH_RESULT_INDEPENDENT_REQUIRED')

  const reviewerContext = await browser.newContext({ viewport: { width: 320, height: 812 }, timezoneId: 'UTC', serviceWorkers: 'block' })
  const reviewerUnexpected = await constrainOrigins(reviewerContext)
  try {
    const reviewer = await reviewerContext.newPage()
    reviewer.on('pageerror', (error) => errors.push(error.message))
    await login(reviewer, 'browser-reviewer@example.test')
    await reviewer.getByRole('button', { name: /Ouvrir Match Alpha/ }).click()
    const reviewerPanel = reviewer.getByRole('region', { name: 'Scrims et matchs', exact: true })
    async function validate(winner: string) {
      await reviewerPanel.getByRole('button', { name: 'Ouvrir les matchs', exact: true }).click()
      const reviewerCard = reviewerPanel.getByRole('heading', { name: created.title, exact: true }).locator('..')
      await expect(reviewerCard.getByRole('button', { name: 'Changer le statut du match', exact: true })).toHaveCount(0)
      await reviewerCard.getByRole('button', { name: 'Résultats et validation', exact: true }).click()
      const decision = reviewerPanel.getByRole('form', { name: 'Approuver le résultat', exact: true })
      await decision.getByLabel('J’ai personnellement consulté la preuve et vérifié le résultat proposé.', { exact: true }).check()
      await decision.getByLabel('Motif de la proposition ou de la décision').fill('Lecture indépendante du rapport fictif et des scores.')
      await decision.getByRole('button', { name: 'Approuver le résultat', exact: true }).click()
      const validated = await approve<MatchResult>(reviewer, `${root}/${created.id}/results/approvals`, 200)
      expect(validated.status).toBe('validated')
      expect(validated.submission?.snapshot?.winner_ids).toEqual([winner])
      expect(await reviewer.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
      return validated
    }
    await validate(created.participants[0].id)
    const reviewerHeaders = await headers(reviewerContext)
    const denied = await reviewerContext.request.post(`${root}/${created.id}/corrections`, { headers: reviewerHeaders, data: {} })
    expect(denied.status()).toBe(403)
    await page.reload()
    await panel.getByRole('button', { name: 'Ouvrir les matchs', exact: true }).click()
    await card.getByRole('button', { name: 'Corrections des scores', exact: true }).click()
    await corrections.getByRole('button', { name: 'Préparer une correction', exact: true }).click()
    const correction = corrections.getByRole('form', { name: 'Demander la correction et le recalcul', exact: true })
    await correction.getByLabel('Corriger cette partie #1', { exact: true }).check()
    await correction.getByLabel(`Score de la partie · ${created.participants[0].label} #1`, { exact: true }).fill('7')
    await correction.getByLabel(`Score de la partie · ${created.participants[1].label} #1`, { exact: true }).fill('13')
    await correction.getByLabel('Motif de la correction').fill('Inversion des scores selon le rapport fictif corrigé.')
    await correction.getByLabel('Je confirme ces corrections et la remise en validation du résultat.', { exact: true }).check()
    await correction.getByRole('button', { name: 'Demander la correction et le recalcul', exact: true }).click()
    const accepted = await approve<MatchCorrection>(page, `${root}/${created.id}/corrections`, 202)
    expect(accepted.state).toBe('pending')
    expect(accepted.before[0].results.map((entry) => entry.score)).toEqual([13, 7])
    expect(accepted.after[0].results.map((entry) => entry.score)).toEqual([7, 13])
    const freshHeaders = await headers(context)
    await expect.poll(async () => {
      const response = await context.request.get(`${root}/${created.id}/corrections`, { headers: freshHeaders })
      expect(response.ok()).toBe(true)
      const history = await response.json() as MatchCorrectionPage
      return history.data.find((entry) => entry.id === accepted.id)?.state
    }, { timeout: 30_000, intervals: [250, 500, 1000] }).toBe('applied')
    const correctedResponse = await context.request.get(`${root}/${created.id}/games`, { headers: freshHeaders })
    expect(correctedResponse.ok()).toBe(true)
    const corrected = await correctedResponse.json() as MatchGamePage
    expect(corrected.scorecard.series?.winner_ids).toEqual([created.participants[1].id])
    expect(corrected.scorecard.score_revision).toBe(accepted.id)
    // Original score entries stay sealed; corrections overlay them and require a new result.
    expect(corrected.scorecard.result_locked).toBe(true)
    expect(corrected.scorecard.recalculation_pending).toBe(false)
    expect(corrected.data[0].results.map((entry) => entry.score)).toEqual([7, 13])
    await page.reload()
    await panel.getByRole('button', { name: 'Ouvrir les matchs', exact: true }).click()
    await card.getByRole('button', { name: 'Corrections des scores', exact: true }).click()
    await expect(corrections.getByRole('heading', { name: '#1 · Recalcul terminé', exact: true })).toBeVisible()
    await expect(corrections).toContainText('Inversion des scores selon le rapport fictif corrigé.')
    const invalidatedResponse = await context.request.get(`${root}/${created.id}/results`, { headers: freshHeaders })
    expect(invalidatedResponse.ok()).toBe(true)
    expect((await invalidatedResponse.json() as MatchResultPage).result.status).toBe('disputed')
    const correctedSubmission = await submit('Rapport privé fictif MATCH-BROWSER-001 corrigé')
    expect(correctedSubmission.score_revision).toBe(accepted.id)
    await reviewer.reload()
    await validate(created.participants[1].id)
    await page.reload()
    await panel.getByRole('button', { name: 'Ouvrir les matchs', exact: true }).click()
    await expect(card.getByText('Validé · Privé : créateur, responsable et personnes inscrites', { exact: true })).toBeVisible()
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
    const persisted = await context.request.get(`${root}/${created.id}/results`, { headers: await headers(context) })
    expect(persisted.ok()).toBe(true)
    const resultHistory = await persisted.json() as MatchResultPage
    expect(resultHistory.result.status).toBe('validated')
    expect(resultHistory.result.submission?.snapshot?.winner_ids).toEqual([created.participants[1].id])
    expect(resultHistory.data.filter((entry) => entry.action === 'approve')).toHaveLength(2)
    expect(reviewerUnexpected).toEqual([])
  } finally {
    await reviewerContext.close()
  }
  await page.getByRole('button', { name: /Ouvrir Match Beta/ }).click()
  await expect(page.getByText('Organisation active : Match Beta')).toBeVisible()
  await panel.getByRole('button', { name: 'Ouvrir les matchs', exact: true }).click()
  await expect(panel.getByRole('heading', { name: 'Beta match privé', exact: true })).toBeVisible()
  await expect(card).toHaveCount(0)
  await expect(panel.getByRole('button', { name: 'Nouveau match', exact: true })).toHaveCount(0)
  const betaHeaders = await headers(context)
  const confirmed = await context.request.post(`${api}/api/v1/active-organization/confirmation`, {
    headers: betaHeaders, data: { organization_id: data.beta, revision: betaHeaders['X-Tenant-Revision'], confirm: true },
  })
  expect(confirmed.ok()).toBe(true)
  const denied = await context.request.post(`${api}/api/v1/tenants/${data.beta}/matches`, { headers: betaHeaders, data: {} })
  expect(denied.status()).toBe(403)
  const betaList = await context.request.get(`${api}/api/v1/tenants/${data.beta}/matches`, { headers: betaHeaders })
  expect(betaList.ok()).toBe(true)
  expect((await betaList.json()).data.map((item: MatchRecord) => item.id)).toEqual([data.betaMatch])
  expect(unexpected).toEqual([])
  expect(errors).toEqual([])
})
