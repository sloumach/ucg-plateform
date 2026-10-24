import { readFileSync } from 'node:fs'
import { expect, test, type BrowserContext, type Page } from '@playwright/test'
import type { Invitation, Membership } from '../../src/api/tenancy'
import { api, constrainOrigins, frontend, login } from './support'

const fixture = JSON.parse(readFileSync(`${process.env.UCG_BROWSER_ROOT}/fixture.json`, 'utf8')) as {
  invitation: { alpha: string; beta: string; recipient: number; expired: string; betaInvitation: string; start: string; end: string }
}
const data = fixture.invitation
const email = 'browser-invitee@example.test'
const plainHeaders = { Accept: 'application/json', Origin: frontend }
test.use({ timezoneId: 'UTC', actionTimeout: 15_000 })

async function headers(context: BrowserContext): Promise<Record<string, string>> {
  const response = await context.request.get(`${api}/api/v1/active-organization`, { headers: plainHeaders })
  expect(response.ok()).toBe(true)
  const active = (await response.json()).data
  const csrf = (await context.cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN')
  expect(csrf).toBeDefined()
  return { ...plainHeaders, ...(active.revision ? { 'X-Tenant-Revision': active.revision } : {}), 'X-XSRF-TOKEN': decodeURIComponent(csrf!.value) }
}

async function approve(page: Page, organization: string, path: string, method: 'POST' | 'PUT' | 'DELETE', status: number): Promise<Invitation> {
  const confirmation = page.getByRole('region', { name: 'Confirmer l’action', exact: true })
  await expect(confirmation).toContainText(organization)
  const pending = page.waitForResponse((response) => response.url() === `${api}/api/v1/${path}` && response.request().method() === method)
  await confirmation.getByRole('button', { name: 'Confirmer et continuer', exact: true }).click()
  const response = await pending
  expect(response.status(), await response.text()).toBe(status)
  return (await response.json()).data as Invitation
}

test('invitations réelles : expiration, révocation, acceptation, rôle limité et refus inter-tenant', async ({ page, context, browser }) => {
  test.setTimeout(180_000)
  const unexpected = await constrainOrigins(context)
  const errors: string[] = []
  page.on('pageerror', (error) => errors.push(error.message))
  await page.setViewportSize({ width: 375, height: 812 })
  const root = `tenants/${data.alpha}/invitations`
  const recipientContext = await browser.newContext({ viewport: { width: 320, height: 812 }, timezoneId: 'UTC', serviceWorkers: 'block' })
  const recipientUnexpected = await constrainOrigins(recipientContext)
  try {
    const recipient = await recipientContext.newPage()
    recipient.on('pageerror', (error) => errors.push(error.message))
    await login(recipient, email)
    await expect(recipient.getByText('Vous ne disposez actuellement d’aucune organisation accessible.', { exact: true })).toBeVisible()
    const expiredCard = recipient.getByText('Invitation Alpha · Membre · Expirée', { exact: true }).locator('..')
    await expect(expiredCard).toBeVisible()
    await expect(expiredCard.getByRole('button', { name: 'Accepter', exact: true })).toHaveCount(0)
    const expired = await recipientContext.request.put(`${api}/api/v1/my-invitations/${data.expired}`, {
      headers: await headers(recipientContext), data: { decision: 'accepted', confirm: true },
    })
    expect(expired.status()).toBe(409)
    expect((await expired.json()).code).toBe('INVITATION_EXPIRED')
    const betaCard = recipient.getByText('Invitation Beta · Membre · En attente', { exact: true }).locator('..')
    await betaCard.getByRole('button', { name: 'Refuser', exact: true }).click()
    expect((await approve(recipient, 'Invitation Beta', `my-invitations/${data.betaInvitation}`, 'PUT', 200)).status).toBe('declined')
    await expect(recipient.getByText('Invitation Beta · Membre · Refusée', { exact: true })).toBeVisible()
    const initialAccess = await recipientContext.request.get(`${api}/api/v1/accessible-organizations`, { headers: plainHeaders })
    expect(initialAccess.ok()).toBe(true)
    expect((await initialAccess.json()).data).toEqual([])

    await login(page, 'browser-inviter@example.test')
    await page.getByRole('button', { name: /Ouvrir Invitation Alpha/ }).click()
    await expect(page.getByText('Organisation active : Invitation Alpha')).toBeVisible()
    const form = page.getByRole('heading', { name: 'Inviter un membre', exact: true }).locator('..')
    async function invite() {
      await form.getByLabel('E-mail de la personne invitée').fill('BROWSER-INVITEE@example.test')
      await form.getByLabel(/^Rôle dans cette organisation/).selectOption('member')
      await expect(form.locator('option[value="administrator"]')).toHaveCount(0)
      await form.getByLabel('Début de l’adhésion', { exact: true }).fill(`${data.start}T10:00`)
      await form.getByLabel('Fin de l’adhésion (facultative)', { exact: true }).fill(`${data.end}T10:00`)
      await form.getByRole('button', { name: 'Envoyer l’invitation', exact: true }).click()
      const invited = await approve(page, 'Invitation Alpha', root, 'POST', 201)
      expect(invited.email).toBe(email)
      expect(invited.roles).toEqual(['member'])
      expect(invited.status).toBe('pending')
      expect(invited.ends_at).toBe(`${data.end}T10:00:00+00:00`)
      return invited
    }
    for (const width of [375, 320]) {
      await page.setViewportSize({ width, height: 812 })
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
    }
    const first = await invite()
    const managerHeaders = await headers(context)
    const duplicate = await context.request.post(`${api}/api/v1/${root}`, { headers: managerHeaders, data: { email, roles: ['member'] } })
    expect(duplicate.status()).toBe(409)
    expect((await duplicate.json()).code).toBe('INVITATION_ALREADY_PENDING')
    const escalation = await context.request.post(`${api}/api/v1/${root}`, { headers: managerHeaders, data: { email: 'browser-admin-attempt@example.test', roles: ['administrator'] } })
    expect(escalation.status()).toBe(403)
    const otherAccount = await context.request.put(`${api}/api/v1/my-invitations/${first.id}`, { headers: managerHeaders, data: { decision: 'accepted', confirm: true } })
    expect(otherAccount.status()).toBe(404)
    const foreignRevoke = await context.request.delete(`${api}/api/v1/${root}/${data.betaInvitation}`, { headers: managerHeaders })
    expect(foreignRevoke.status()).toBe(404)
    await page.reload()
    await expect(page.getByText(`${email} · Membre · En attente`, { exact: true })).toBeVisible()
    const managerCard = page.getByText(`${email} · Membre · En attente`, { exact: true }).locator('..')
    await managerCard.getByRole('button', { name: 'Révoquer', exact: true }).click()
    expect((await approve(page, 'Invitation Alpha', `${root}/${first.id}`, 'DELETE', 200)).status).toBe('revoked')
    await expect(page.getByText(`${email} · Membre · Révoquée`, { exact: true })).toBeVisible()
    await recipient.reload()
    const revokedCard = recipient.getByText('Invitation Alpha · Membre · Révoquée', { exact: true }).locator('..')
    await expect(revokedCard).toBeVisible()
    await expect(revokedCard.getByRole('button', { name: 'Accepter', exact: true })).toHaveCount(0)
    const revoked = await recipientContext.request.put(`${api}/api/v1/my-invitations/${first.id}`, {
      headers: await headers(recipientContext), data: { decision: 'accepted', confirm: true },
    })
    expect(revoked.status()).toBe(409)
    expect((await revoked.json()).code).toBe('INVITATION_ALREADY_CLOSED')
    const beforeAdmission = await context.request.get(`${api}/api/v1/tenants/${data.alpha}/memberships`, { headers: await headers(context) })
    expect(beforeAdmission.ok()).toBe(true)
    expect((await beforeAdmission.json()).data.some((membership: Membership) => membership.user_id === data.recipient)).toBe(false)
    const second = await invite()
    expect(second.id).not.toBe(first.id)
    await recipient.reload()
    const injection = await recipientContext.request.put(`${api}/api/v1/my-invitations/${second.id}`, {
      headers: await headers(recipientContext), data: { decision: 'accepted', confirm: true, roles: ['administrator'] },
    })
    expect(injection.status()).toBe(422)
    const pendingCard = recipient.getByText('Invitation Alpha · Membre · En attente', { exact: true }).locator('..')
    await pendingCard.getByRole('button', { name: 'Accepter', exact: true }).click()
    const accepted = await approve(recipient, 'Invitation Alpha', `my-invitations/${second.id}`, 'PUT', 200)
    expect(accepted.status).toBe('accepted')
    expect(accepted.roles).toEqual(['member'])
    await recipient.getByRole('button', { name: /Ouvrir Invitation Alpha/ }).click()
    await expect(recipient.getByText('Organisation active : Invitation Alpha')).toBeVisible()
    await expect(recipient.getByRole('heading', { name: 'Inviter un membre', exact: true })).toHaveCount(0)
    const recipientHeaders = await headers(recipientContext)
    const activeResponse = await recipientContext.request.get(`${api}/api/v1/active-organization`, { headers: plainHeaders })
    expect(activeResponse.ok()).toBe(true)
    const active = (await activeResponse.json()).data
    expect(active.context.roles).toEqual(['member'])
    expect(active.context.permissions).toEqual(['organization.view'])
    const organizations = await recipientContext.request.get(`${api}/api/v1/accessible-organizations`, { headers: plainHeaders })
    expect(organizations.ok()).toBe(true)
    expect((await organizations.json()).data.map((organization: { id: string }) => organization.id)).toEqual([data.alpha])
    const replay = await recipientContext.request.put(`${api}/api/v1/my-invitations/${second.id}`, { headers: recipientHeaders, data: { decision: 'accepted', confirm: true } })
    expect(replay.status()).toBe(409)
    expect((await replay.json()).code).toBe('INVITATION_ALREADY_CLOSED')
    const forbiddenList = await recipientContext.request.get(`${api}/api/v1/${root}`, { headers: recipientHeaders })
    expect(forbiddenList.status()).toBe(403)
    const noBeta = await recipientContext.request.post(`${api}/api/v1/active-organization`, { headers: recipientHeaders, data: { organization_id: data.beta } })
    expect(noBeta.status()).toBe(404)
    await recipient.reload()
    await expect(recipient.getByText('Organisation active : Invitation Alpha')).toBeVisible()
    await expect(recipient.getByText('Invitation Alpha · Membre · Acceptée', { exact: true })).toBeVisible()
    expect(await recipient.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)

    await page.reload()
    const memberships = await context.request.get(`${api}/api/v1/tenants/${data.alpha}/memberships`, { headers: await headers(context) })
    expect(memberships.ok()).toBe(true)
    const joined = ((await memberships.json()).data as Membership[]).filter((membership) => membership.user_id === data.recipient)
    expect(joined).toHaveLength(1)
    expect(joined[0].roles).toEqual(['member'])
    expect(joined[0].status).toBe('active')
    expect(joined[0].ends_at).toBe(second.ends_at)
    const invitations = await context.request.get(`${api}/api/v1/${root}`, { headers: await headers(context) })
    expect(invitations.ok()).toBe(true)
    expect((await invitations.json()).data.map((invitation: Invitation) => invitation.status).sort()).toEqual(['expired', 'revoked', 'accepted'].sort())
    await page.getByRole('button', { name: /Ouvrir Invitation Beta/ }).click()
    await expect(page.getByText('Organisation active : Invitation Beta')).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Inviter un membre', exact: true })).toHaveCount(0)
    await expect(page.getByText(`${email} · Membre · Acceptée`, { exact: true })).toHaveCount(0)
    const betaHeaders = await headers(context)
    const confirmed = await context.request.post(`${api}/api/v1/active-organization/confirmation`, {
      headers: betaHeaders, data: { organization_id: data.beta, revision: betaHeaders['X-Tenant-Revision'], confirm: true },
    })
    expect(confirmed.ok()).toBe(true)
    const forbidden = await context.request.post(`${api}/api/v1/tenants/${data.beta}/invitations`, { headers: betaHeaders, data: { email, roles: ['member'] } })
    expect(forbidden.status()).toBe(403)
    expect(recipientUnexpected).toEqual([])
    expect(unexpected).toEqual([])
    expect(errors).toEqual([])
  } finally {
    await recipientContext.close()
  }
})
