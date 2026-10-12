import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { tenancyRequest, type ActiveOrganization } from '../../api/tenancy'
import type { PersonImport } from '../../api/personImports'
import { ApiClientError } from '../../api/errors'
import { t } from '../../i18n/fr'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { PersonImportsPanel } from './PersonImportsPanel'

vi.mock('../../api/tenancy', () => ({ tenancyRequest: vi.fn() }))
const request = vi.mocked(tenancyRequest)
const org = '11111111-1111-4111-8111-111111111111', version = '22222222-2222-4222-8222-222222222222', id = '33333333-3333-4333-8333-333333333333'
const permissions = ['sports.read', 'sports.write', 'administrative.read', 'administrative.write']
const active = (rights = permissions): ActiveOrganization => ({ context: { organization_id: org, name: 'Alpha', slug: 'alpha', roles: ['member'], permissions: rights, is_owner: false, timezone: 'UTC', language: 'fr', actor_user_id: 42, membership_id: 'membership' }, revision: version, confirmed: false })
const initial = (): PersonImport => ({ id, version, status: 'uploaded', headers: ['pseudo', 'email'], mapping: {}, defaults: {}, row_count: 2, counts: {}, failure_code: null, rows: [], pagination: { current_page: 1, last_page: 1, total: 0 } })
let current: PersonImport
function setup(rights = permissions) {
  const invalid = vi.fn(), imported = vi.fn()
  const result = render(<NotificationProvider><PersonImportsPanel active={active(rights)} disabled={false} onInvalid={invalid} onImported={imported} /></NotificationProvider>)
  return { ...result, invalid, imported }
}
async function openRun(user: ReturnType<typeof userEvent.setup>) {
  await user.click(screen.getByRole('button', { name: t('people.import.title') }))
  await user.click(await screen.findByRole('button', { name: /Fichier déposé ·|Prévisualisé ·/ }))
  return screen.findByRole('form', { name: t('people.import.mapping') })
}
async function approve(user: ReturnType<typeof userEvent.setup>) {
  const region = await screen.findByRole('region', { name: t('tenant.confirmTitle') })
  await user.click(within(region).getByRole('button', { name: t('tenant.confirm') }))
}
describe('Import privé de personnes', () => {
  it('affiche les erreurs de mapping et de défaut tout en conservant le brouillon sur 422', async () => {
    const user = userEvent.setup(); const { invalid } = setup(); const form = await openRun(user)
    const timezone = within(form).getByLabelText(t('people.import.defaultTimezone'))
    await user.clear(timezone); await user.type(timezone, 'INVALID')
    await user.selectOptions(within(form).getByLabelText(t('people.contact_email')), '1')
    const before = request.getMockImplementation()!
    request.mockImplementation((path, options) => path.endsWith('/preview') ? Promise.reject(new ApiClientError('Corrigez les champs.', { status: 422, fieldErrors: { 'defaults.timezone': ['Choisissez un fuseau valide.'], mapping: ['Chaque colonne doit être distincte.'] } })) : before(path, options))
    await user.click(within(form).getByRole('button', { name: t('people.import.preview') })); await approve(user)
    await screen.findByText('Choisissez un fuseau valide.')
    expect(screen.getByText('Chaque colonne doit être distincte.')).toBeVisible()
    expect(timezone).toHaveValue('INVALID')
    expect(timezone).toHaveAttribute('aria-invalid', 'true')
    expect(within(form).getByLabelText(t('people.contact_email'))).toHaveValue('1')
    expect(invalid).not.toHaveBeenCalled()
  })
  it('charge une page de lignes puis la suivante sans conserver les valeurs précédentes', async () => {
    const user = userEvent.setup(); setup()
    current = { ...current, status: 'previewed', counts: { ready: 11 }, rows: [{ id: 'a', line: 2, status: 'ready', values: { pseudonym: 'Alpha-page-one' }, errors: [], person_id: null }], pagination: { current_page: 1, last_page: 2, total: 11 } }
    const before = request.getMockImplementation()!
    request.mockImplementation((path, options) => path.includes('/' + id + '?page=2') ? Promise.resolve({ data: { ...current, rows: [{ id: 'b', line: 12, status: 'ready', values: { pseudonym: 'Beta-page-two' }, errors: [], person_id: null }], pagination: { current_page: 2, last_page: 2, total: 11 } } }) : before(path, options))
    await openRun(user)
    await screen.findByText('Alpha-page-one')
    await user.click(screen.getByRole('button', { name: t('pagination.next') }))
    await screen.findByText('Beta-page-two')
    expect(screen.queryByText('Alpha-page-one')).not.toBeInTheDocument()
  })
  it('télécharge uniquement le rapport du tenant et libère son URL temporaire', async () => {
    const user = userEvent.setup(); setup(); await openRun(user)
    const create = vi.fn().mockReturnValue('blob:report'), revoke = vi.fn()
    class ReportURL extends URL { static createObjectURL = create; static revokeObjectURL = revoke }
    vi.stubGlobal('URL', ReportURL)
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
    const before = request.getMockImplementation()!
    request.mockImplementation((path, options) => path.endsWith('/report') ? Promise.resolve(new Blob(['line,status,errors,person_id\r\n'], { type: 'text/csv' })) : before(path, options))
    await user.click(screen.getByRole('button', { name: t('people.import.report') }))
    await waitFor(() => expect(click).toHaveBeenCalledOnce())
    expect(request).toHaveBeenCalledWith('tenants/' + org + '/people-imports/' + id + '/report', expect.objectContaining({ revision: version, download: true, signal: expect.any(AbortSignal) }))
    expect(revoke).toHaveBeenCalledWith('blob:report')
  })
  beforeEach(() => {
    current = initial()
    request.mockReset()
    request.mockImplementation(async (path, options) => {
      if (path === 'active-organization/confirmation') return { data: {} }
      if (options?.method === 'POST' && path.endsWith('/preview')) {
        current = { ...current, version: '44444444-4444-4444-8444-444444444444', status: 'previewed', mapping: { pseudonym: 0, contact_email: 1 }, counts: { ready: 1, invalid: 1 }, rows: [{ id: 'row', line: 2, status: 'ready', values: { pseudonym: '<script>secret</script>', contact_email: 'a@example.test' }, errors: [], person_id: null }], pagination: { current_page: 1, last_page: 1, total: 1 } }
      }
      if (options?.method === 'POST' && path.endsWith('/confirm')) current = { ...current, status: 'queued' }
      if (path.includes('/people-imports/' + id)) return { data: current }
      if (options?.method === 'POST') return { data: current }
      return { data: [current], meta: { current_page: 1, last_page: 1, total: 1, request_id: 'import-test' } }
    })
  })
  afterEach(() => { vi.unstubAllGlobals(); vi.restoreAllMocks() })
  it('cache les imports sans les quatre permissions explicites et ne charge aucun fichier', () => {
    setup(['sports.read', 'administrative.read'])
    expect(screen.queryByRole('button', { name: t('people.import.title') })).not.toBeInTheDocument()
    expect(request).not.toHaveBeenCalled()
  })
  it('le dépôt attend la confirmation tenant et transporte le fichier sans JSON', async () => {
    const user = userEvent.setup(); setup()
    await user.click(screen.getByRole('button', { name: t('people.import.title') }))
    const form = await screen.findByRole('form', { name: t('people.import.upload') })
    await user.upload(within(form).getByLabelText(t('people.import.file')), new File(['pseudo\nAlex'], 'people.csv', { type: 'text/csv' }))
    await user.click(within(form).getByRole('button', { name: t('people.import.upload') }))
    expect(request.mock.calls.some(([, options]) => options?.method === 'POST')).toBe(false)
    await approve(user)
    await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/' + org + '/people-imports', expect.objectContaining({ method: 'POST', revision: version, body: expect.any(FormData) })))
    const body = request.mock.calls.find(([path, options]) => path.endsWith('/people-imports') && options?.method === 'POST')![1]!.body as FormData
    expect((body.get('file') as File).name).toBe('people.csv')
    expect(body.get('delimiter')).toBe(',')
    expect(request).toHaveBeenCalledWith('active-organization/confirmation', expect.objectContaining({ body: { organization_id: org, revision: version, confirm: true } }))
  })
  it('prévisualise les colonnes choisies sans confirmer la création et échappe les valeurs', async () => {
    const user = userEvent.setup(); setup(); const form = await openRun(user)
    await user.selectOptions(within(form).getByLabelText(t('people.contact_email')), '1')
    await user.click(within(form).getByRole('button', { name: t('people.import.preview') })); await approve(user)
    await screen.findByText('<script>secret</script>')
    expect(document.querySelector('script')).toBeNull()
    expect(request).toHaveBeenCalledWith('tenants/' + org + '/people-imports/' + id + '/preview', expect.objectContaining({ body: { version, mapping: { pseudonym: 0, contact_email: 1 }, defaults: { language: 'fr', timezone: 'UTC', status: 'active' } } }))
    expect(request.mock.calls.some(([path]) => path.endsWith('/confirm'))).toBe(false)
  })
  it('annuler la confirmation ne dépose pas le fichier', async () => {
    const user = userEvent.setup(); setup()
    await user.click(screen.getByRole('button', { name: t('people.import.title') }))
    const form = await screen.findByRole('form', { name: t('people.import.upload') })
    await user.upload(within(form).getByLabelText(t('people.import.file')), new File(['pseudo\nAlex'], 'people.csv', { type: 'text/csv' }))
    await user.click(within(form).getByRole('button', { name: t('people.import.upload') }))
    const region = await screen.findByRole('region', { name: t('tenant.confirmTitle') })
    await user.click(within(region).getByRole('button', { name: t('tenant.cancel') }))
    expect(request.mock.calls.some(([, options]) => options?.method === 'POST')).toBe(false)
  })
  it('la création exige confirmation métier et reconnaissance des lignes refusées', async () => {
    const user = userEvent.setup(); const { imported } = setup(); const form = await openRun(user)
    await user.selectOptions(within(form).getByLabelText(t('people.contact_email')), '1')
    await user.click(within(form).getByRole('button', { name: t('people.import.preview') })); await approve(user)
    const confirmation = await screen.findByRole('form', { name: t('people.import.confirm') })
    await user.click(within(confirmation).getByLabelText(t('people.import.confirmation')))
    await user.click(within(confirmation).getByLabelText(t('people.import.acknowledge')))
    await user.click(within(confirmation).getByRole('button', { name: t('people.import.confirm') })); await approve(user)
    await waitFor(() => expect(imported).toHaveBeenCalled())
    expect(request).toHaveBeenCalledWith('tenants/' + org + '/people-imports/' + id + '/confirm', expect.objectContaining({ body: { version: '44444444-4444-4444-8444-444444444444', confirm: true, acknowledge_rejected: true } }))
  })
  it('un conflit de version recharge le contexte sans rejouer', async () => {
    const user = userEvent.setup(); const { invalid } = setup(); const form = await openRun(user)
    const before = request.getMockImplementation()!
    request.mockImplementation((path, options) => path.endsWith('/preview') ? Promise.reject(new ApiClientError('Import modifié.', { status: 409, code: 'PERSON_IMPORT_STALE' })) : before(path, options))
    await user.click(within(form).getByRole('button', { name: t('people.import.preview') })); await approve(user)
    await waitFor(() => expect(invalid).toHaveBeenCalledOnce())
    expect(request.mock.calls.filter(([path]) => path.endsWith('/preview'))).toHaveLength(1)
    expect(request.mock.calls.some(([path]) => path.endsWith('/confirm'))).toBe(false)
  })
  it('une réponse tardive après fermeture ne réaffiche pas les données privées', async () => {
    const user = userEvent.setup(); const result = setup()
    let resolve!: (value: unknown) => void
    request.mockImplementation(() => new Promise((done) => { resolve = done }))
    await user.click(screen.getByRole('button', { name: t('people.import.title') }))
    await waitFor(() => expect(request).toHaveBeenCalled())
    result.unmount(); resolve({ data: [{ ...current, headers: ['SECRET_A'] }], meta: { current_page: 1, last_page: 1, total: 1 } })
    await Promise.resolve()
    expect(screen.queryByText('SECRET_A')).not.toBeInTheDocument()
  })
})
