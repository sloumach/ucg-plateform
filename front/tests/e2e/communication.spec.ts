import { expect, test } from '@playwright/test'
test('communications : brouillon ciblé, publication confirmée, réponse, préférences et retrait sur mobile', async ({ page }) => {
  const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222', version = '33333333-3333-4333-8333-333333333333'
  const root = 'tenants/' + org + '/communication', errors: string[] = []
  const title = '<script>COM001</script> ' + 'AnnonceÉquipeLongue'.repeat(5)
  let record: Record<string, unknown> | null = null, confirmed = false, read = false
  let preferences = { version: null as string | null, announcements_enabled: true, comments_enabled: false }
  const comments: Record<string, unknown>[] = []
  const headers = { 'Access-Control-Allow-Origin': 'http://localhost:5173', 'Access-Control-Allow-Credentials': 'true' }
  page.on('pageerror', (error) => errors.push(error.message))
  page.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()) })
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers }))
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request(), url = new URL(request.url()), path = url.pathname.replace('/api/v1/', ''), method = request.method()
    if (method === 'OPTIONS') { await route.fulfill({ status: 204, headers: { ...headers, 'Access-Control-Allow-Methods': 'GET, POST, PATCH', 'Access-Control-Allow-Headers': 'Content-Type, X-Tenant-Revision, X-XSRF-TOKEN' } }); return }
    let data: unknown = []
    if (path === 'auth/me') data = { id: 42, name: 'Membre communication', email: 'com@example.test', email_verified: true, mfa: { required: true, enabled: true, verified: true } }
    if (path === 'system/status') data = { name: 'UCG Platform', status: 'operational', api_version: 'v1' }
    if (path === 'accessible-organizations') data = [{ id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], is_owner: false, membership_id: id }]
    if (path === 'active-organization/confirmation') confirmed = true
    if (path === 'active-organization' || path === 'active-organization/confirmation') data = {
      context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'Europe/Paris', language: 'fr', roles: ['member'],
        permissions: ['organization.view', 'communication.read', 'communication.write', 'communication.publish', 'communication.comment'], is_owner: false, actor_user_id: 42, membership_id: id }, revision: version, confirmed }
    if (path.startsWith(root) && method !== 'GET') { expect(confirmed).toBe(true); expect(request.headers()['x-tenant-revision']).toBe(version) }
    if (path === root + '/targets') data = [{ id, label: '<script>Équipe</script>' }]
    if (path === root + '/announcements' && method === 'POST') {
      const body = request.postDataJSON()
      expect(body.target_type).toBe('team'); expect(body.target_id).toBe(id); expect(body.comments_enabled).toBe(true)
      expect(body).not.toHaveProperty('event_id')
      record = { ...body, id, version, status: 'draft', can_comment: false, published_at: null, withdrawn_at: null }
      data = record
    }
    if (path === root + '/announcements/' + id && method === 'PATCH') {
      const body = request.postDataJSON(); expect(body.version).toBe(version)
      if (body.action === 'publish') {
        expect(body.confirm_publication).toBe(true); expect(body.notify_members).toBe(true)
        record = { ...record, status: 'published', can_comment: true, published_at: '2026-10-16T12:00:00+00:00' }
      } else if (body.action === 'withdraw') record = { ...record, status: 'withdrawn', can_comment: false, withdrawn_at: '2026-10-16T13:00:00+00:00' }
      data = record
    }
    if (path === root + '/announcements' && method === 'GET') {
      const management = url.searchParams.get('management') === '1'
      data = record && (management || record.status === 'published') ? [record] : []
    }
    if (path === root + '/announcements/' + id + '/comments') {
      if (method === 'POST') {
        const body = request.postDataJSON(); expect(body.version).toBe(version); expect(body.notify_members).toBe(false)
        comments.push({ id, announcement_id: id, body: body.body, is_mine: true, created_at: '2026-10-16T12:30:00+00:00' })
        data = comments[comments.length - 1]
      } else data = comments
    }
    if (path === root + '/preferences') {
      if (method === 'PATCH') { const body = request.postDataJSON(); expect(body.version).toBeNull(); preferences = { ...body, version }; }
      data = preferences
    }
    if (path === root + '/inbox/' + id + '/read') { read = true; data = { read: true } }
    if (path === root + '/inbox') data = record?.status === 'published' && preferences.comments_enabled
      ? [{ id, announcement_id: id, comment_id: id, title, kind: 'comment', read, created_at: '2026-10-16T12:45:00+00:00' }] : []
    await route.fulfill({ status: method === 'POST' && path.includes('/announcements') ? 201 : 200, headers, contentType: 'application/json',
      body: JSON.stringify({ data, meta: { current_page: 1, last_page: 1, total: Array.isArray(data) ? data.length : 0 } }) })
  })
  await page.setViewportSize({ width: 390, height: 844 }); await page.goto('/')
  const panel = page.getByRole('region', { name: 'Annonces et discussions' })
  await panel.getByRole('button', { name: 'Ouvrir les communications' }).click()
  await expect(panel.getByText('Aucune annonce accessible dans cette sélection.')).toBeVisible()
  await panel.getByRole('button', { name: 'Gérer les annonces' }).click()
  await panel.getByRole('button', { name: 'Nouvelle annonce' }).click()
  let form = panel.getByRole('form', { name: 'Enregistrer le brouillon' })
  await form.getByLabel('Titre de l’annonce').fill(title)
  await form.getByLabel('Message', { exact: true }).fill('<img src=x onerror=alert(1)> Message interne')
  await form.getByLabel('Type de destinataires').selectOption('team')
  await form.getByLabel('Destinataire', { exact: true }).selectOption(id)
  await form.getByRole('button', { name: 'Enregistrer le brouillon' }).click()
  await panel.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await expect(panel.getByRole('heading', { name: title, exact: true })).toBeVisible()
  form = panel.getByRole('form', { name: 'Publier l’annonce' })
  await expect(form.getByRole('button', { name: 'Publier l’annonce' })).toBeDisabled()
  await form.getByLabel('Je confirme le contenu et les destinataires de cette annonce').check()
  await form.getByLabel('Créer une notification interne pour les lecteurs concernés').check()
  await form.getByRole('button', { name: 'Publier l’annonce' }).click()
  await panel.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await expect(panel.getByText('Publiée · Équipe')).toBeVisible()
  await expect(panel.getByRole('button', { name: 'Modifier le brouillon' })).toHaveCount(0)
  await panel.getByRole('button', { name: 'Mon fil', exact: true }).click()
  await expect(panel.getByRole('heading', { name: title, exact: true })).toBeVisible()
  await panel.getByRole('button', { name: 'Commentaires', exact: true }).click()
  form = panel.getByRole('form', { name: 'Ajouter le commentaire' })
  await form.getByLabel('Message', { exact: true }).fill('<script>Réponse</script>')
  await form.getByRole('button', { name: 'Ajouter le commentaire' }).click()
  // A temporarily hidden form can mean loading, not a completed mutation.
  const commentPath = '/api/v1/' + root + '/announcements/' + id + '/comments'
  const commentSaved = page.waitForResponse((response) => new URL(response.url()).pathname === commentPath && response.request().method() === 'POST')
  const feedReloaded = page.waitForResponse((response) => new URL(response.url()).pathname === '/api/v1/' + root + '/announcements' && response.request().method() === 'GET')
  await panel.getByRole('button', { name: 'Confirmer et continuer' }).click()
  const saved = await commentSaved
  expect(saved.status()).toBe(201)
  expect((await saved.json()).data.body).toBe('<script>Réponse</script>')
  const refreshed = await feedReloaded
  expect(refreshed.status()).toBe(200)
  await refreshed.finished()
  await expect(form).toHaveCount(0)
  await expect(panel.getByRole('heading', { name: title, exact: true })).toBeVisible()
  const commentsReloaded = page.waitForResponse((response) => new URL(response.url()).pathname === commentPath && response.request().method() === 'GET')
  await panel.getByRole('button', { name: 'Commentaires', exact: true }).click()
  const thread = await commentsReloaded
  expect(thread.status()).toBe(200)
  expect((await thread.json()).data).toEqual([expect.objectContaining({ body: '<script>Réponse</script>' })])
  await expect(panel.getByText('<script>Réponse</script>', { exact: true })).toBeVisible()
  await expect(panel.locator('script,iframe,img')).toHaveCount(0)
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
  await panel.screenshot({ path: 'reports/com001-mobile.png', style: 'header, [aria-label="Notifications"] { visibility: hidden !important; }' })
  await panel.getByRole('button', { name: 'Mes préférences' }).click()
  form = panel.getByRole('form', { name: 'Enregistrer mes préférences' })
  await form.getByLabel('Recevoir les réponses aux discussions auxquelles je participe').check()
  await form.getByRole('button', { name: 'Enregistrer mes préférences' }).click()
  await panel.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await panel.getByRole('button', { name: 'Mes notifications' }).click()
  await panel.getByRole('button', { name: 'Marquer comme lue' }).click()
  await panel.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await expect(panel.getByText(/ · Lue$/)).toBeVisible()
  await panel.getByRole('button', { name: 'Gérer les annonces' }).click()
  await panel.getByRole('button', { name: 'Retirer l’annonce', exact: true }).click()
  await panel.getByRole('button', { name: 'Confirmer et continuer' }).click()
  await expect(panel.getByText('Retirée · Équipe')).toBeVisible()
  await panel.getByRole('button', { name: 'Mon fil', exact: true }).click()
  await expect(panel.getByText('Aucune annonce accessible dans cette sélection.')).toBeVisible()
  await panel.getByRole('button', { name: 'Mes notifications' }).click()
  await expect(panel.getByText('Aucune notification accessible.')).toBeVisible()
  expect(errors).toEqual([])
})
