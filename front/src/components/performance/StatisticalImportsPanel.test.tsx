import { beforeEach, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ActiveOrganization } from '../../api/tenancy'
import type { StatisticalImport } from '../../api/statisticalImports'
import { ApiClientError } from '../../api/errors'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { StatisticalImportsPanel } from './StatisticalImportsPanel'
const fake = vi.hoisted(() => ({ request: vi.fn(), invalid: vi.fn() }))
vi.mock('../../api/tenancy', async (original) => ({ ...await original<typeof import('../../api/tenancy')>(), tenancyRequest: fake.request }))
const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222', revision = '33333333-3333-4333-8333-333333333333'
const active: ActiveOrganization = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'], permissions: ['sports.read', 'sports.write', 'sports.statistics.read', 'sports.statistics.write', 'sports.statistics.import'], actor_user_id: 42, is_owner: false, membership_id: id }, revision, confirmed: false }
const run: StatisticalImport = { id, version: id, can_resume: false, processed_count: 0, counts: { created: 0, duplicate: 0, rejected: 0 }, failure_code: null, status: 'previewed', row_count: 1, valid_count: 0, invalid_count: 1, created_at: '2026-10-19T10:00:00Z', can_confirm: false, headers: ['value'], delimiter: ',', rows: { data: [{ line: 2, status: 'invalid', values: { value: '0', source_label: '<script>Source privée</script>' }, errors: { reference: ['Référence inaccessible.'] }, resolved: null }], meta: { current_page: 1, last_page: 2, per_page: 10, total: 11 } } }
const root = 'tenants/' + org + '/statistical-imports'
let failure: ApiClientError | null
beforeEach(() => {
  vi.resetAllMocks(); failure = null
  fake.request.mockImplementation(async (path: string, options?: { method?: string }) => {
    if (path === 'active-organization/confirmation') return { data: { ...active, confirmed: true } }
    if (options?.method === 'POST') { if (failure) throw failure; return { data: run } }
    if (path.includes('/statistical-imports/')) return { data: run }
    return { data: [run], meta: { request_id: 'stat003a-test', current_page: 1, last_page: 1, total: 1 } }
  })
})
function show(selection = active) { return render(<NotificationProvider><StatisticalImportsPanel active={selection} disabled={false} onInvalid={fake.invalid} /></NotificationProvider>) }
async function open() { await userEvent.click(screen.getByRole('button', { name: 'Préparer un CSV statistique' })); await screen.findByRole('button', { name: 'Voir cet aperçu · 22222222' }) }
async function upload() {
  const form = screen.getByRole('form', { name: 'Déposer et prévisualiser le CSV' })
  const file = new File(['value\n0'], 'statistics.csv', { type: 'text/csv' })
  await userEvent.upload(within(form).getByLabelText('Fichier CSV statistique'), file)
  fireEvent.submit(form)
  return { form, file }
}
it('does not load private data while closed or without every permission', async () => {
  const rendered = show(); expect(fake.request).not.toHaveBeenCalled()
  rendered.unmount()
  show({ ...active, context: { ...active.context!, permissions: ['sports.read', 'sports.statistics.read', 'sports.write', 'sports.statistics.write'] } })
  expect(screen.queryByRole('button', { name: 'Préparer un CSV statistique' })).not.toBeInTheDocument()
  expect(fake.request).not.toHaveBeenCalled()
})
it('confirms the tenant before uploading the original file and never offers processing', async () => {
  const rendered = show(); await open(); const { file } = await upload()
  expect(fake.request.mock.calls.filter(([, options]) => options?.method === 'POST')).toHaveLength(0)
  await userEvent.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
  await screen.findByText('Référence inaccessible.', { exact: false })
  const writes = fake.request.mock.calls.filter(([, options]) => options?.method === 'POST')
  expect(writes.map(([path]) => path)).toEqual(['active-organization/confirmation', root])
  const sent = writes[1]![1]; expect(sent.revision).toBe(revision); expect(sent.body).toBeInstanceOf(FormData)
  expect(sent.body.get('file')).toBe(file); expect(sent.body.get('delimiter')).toBe(',')
  expect(screen.getByText('CSV UTF-8, 1 Mio et 100 lignes maximum. Ce dépôt prépare un aperçu ; aucune observation ne sera créée.')).toBeVisible()
  expect(rendered.container.querySelector('script,img,iframe')).toBeNull()
  expect(screen.queryByRole('button', { name: /traiter|importer les observations/i })).not.toBeInTheDocument()
})
it('pages through row errors without exposing another preview or submitting a mutation', async () => {
  show(); await open(); await userEvent.click(screen.getByRole('button', { name: 'Voir cet aperçu · 22222222' }))
  const preview = await screen.findByRole('region', { name: 'Prévisualisation du CSV' })
  await within(preview).findByText('Ligne 2 · À corriger')
  await userEvent.click(within(preview).getByRole('button', { name: /suivant/i }))
  await waitFor(() => expect(fake.request.mock.calls.some(([path]) => path === root + '/' + id + '?page=2')).toBe(true))
  expect(fake.request.mock.calls.some(([, options]) => options?.method === 'POST')).toBe(false)
})
it('keeps the selected CSV and focuses the error after a 422', async () => {
  failure = new ApiClientError('CSV refusé.', { status: 422, fieldErrors: { file: ['Schéma invalide.'] } })
  show(); await open(); const { file } = await upload()
  await userEvent.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
  await screen.findByText('Schéma invalide.')
  expect((screen.getByLabelText('Fichier CSV statistique') as HTMLInputElement).files?.[0]).toBe(file)
  expect(screen.getByLabelText('Fichier CSV statistique')).toHaveFocus()
  expect(fake.invalid).not.toHaveBeenCalled()
})
it('invalidates a stale context once and does not replay the upload', async () => {
  failure = new ApiClientError('Contexte modifié.', { status: 409, fieldErrors: {} })
  show(); await open(); await upload(); await userEvent.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
  await waitFor(() => expect(fake.invalid).toHaveBeenCalledOnce())
  expect(fake.request.mock.calls.filter(([path, options]) => path === root && options?.method === 'POST')).toHaveLength(1)
})
it('does not finish an upload after the organization panel unmounts during confirmation', async () => {
  let release: (() => void) | undefined
  fake.request.mockImplementation(async (path: string) => {
    if (path === 'active-organization/confirmation') await new Promise<void>((resolve) => { release = resolve })
    return { data: [run], meta: { current_page: 1, last_page: 1, total: 1 } }
  })
  const rendered = show(); await open(); await upload(); await userEvent.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
  rendered.unmount(); release?.()
  await waitFor(() => expect(fake.request.mock.calls.filter(([path]) => path === 'active-organization/confirmation')).toHaveLength(1))
  expect(fake.request.mock.calls.some(([path, options]) => path === root && options?.method === 'POST')).toBe(false)
})

it('requires acknowledgment and an explicit tenant confirmation before processing valid rows', async () => {
  const executable = { ...run, can_confirm: true, valid_count: 1 }
  fake.request.mockImplementation(async (path: string, options?: { method?: string }) => {
    if (path === 'active-organization/confirmation') return { data: { ...active, confirmed: true } }
    if (options?.method === 'POST') return { data: { ...executable, status: 'queued', can_confirm: false, can_resume: true } }
    if (path.includes('/statistical-imports/')) return { data: executable }
    return { data: [executable], meta: { current_page: 1, last_page: 1, total: 1 } }
  })
  show(); await open(); await userEvent.click(screen.getByRole('button', { name: 'Voir cet aperçu · 22222222' }))
  const execute = await screen.findByRole('button', { name: 'Importer les observations valides' })
  expect(execute).toBeDisabled()
  await userEvent.click(screen.getByRole('checkbox', { name: 'J’accepte que les lignes invalides de cet aperçu soient ignorées.' }))
  await userEvent.click(execute)
  expect(fake.request.mock.calls.some(([path]) => path.endsWith('/confirm'))).toBe(false)
  await userEvent.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
  await waitFor(() => expect(fake.request.mock.calls.some(([path]) => path === root + '/' + id + '/confirm')).toBe(true))
  const writes = fake.request.mock.calls.filter(([, options]) => options?.method === 'POST')
  expect(writes.map(([path]) => path)).toEqual(['active-organization/confirmation', root + '/' + id + '/confirm'])
  expect(writes[1]![1]).toMatchObject({ revision, body: { version: id, confirm: true, acknowledge_rejected: true } })
})

it('resumes an interrupted import with its execution version and displays saved outcomes', async () => {
  const interrupted = { ...run, status: 'failed', version: revision, can_resume: true, processed_count: 1, counts: { created: 0, duplicate: 0, rejected: 1 }, failure_code: 'PROCESSING_INTERRUPTED', rows: { ...run.rows, data: [{ ...run.rows.data[0]!, outcome: { status: 'rejected', code: 'OBSERVATION_CONFLICT' } }] } }
  fake.request.mockImplementation(async (path: string, options?: { method?: string }) => {
    if (options?.method === 'POST' || path.includes('/statistical-imports/')) return { data: interrupted }
    return { data: [interrupted], meta: { current_page: 1, last_page: 1, total: 1 } }
  })
  show({ ...active, confirmed: true }); await open(); await userEvent.click(screen.getByRole('button', { name: 'Voir cet aperçu · 22222222' }))
  const resume = await screen.findByRole('button', { name: 'Reprendre le traitement' })
  expect(await screen.findByText(/Une observation de même origine existe/)).toBeVisible()
  await userEvent.click(resume); await userEvent.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
  await waitFor(() => expect(fake.request).toHaveBeenCalledWith(root + '/' + id + '/resume', expect.objectContaining({ revision, body: { version: revision, confirm: true } })))
})

it('does not replay a confirmation refused with a stale version', async () => {
  fake.request.mockImplementation(async (path: string, options?: { method?: string }) => {
    if (options?.method === 'POST') throw new ApiClientError('Import modifié.', { status: 409 })
    if (path.includes('/statistical-imports/')) return { data: { ...run, can_confirm: true, invalid_count: 0 } }
    return { data: [run], meta: { current_page: 1, last_page: 1, total: 1 } }
  })
  show({ ...active, confirmed: true }); await open(); await userEvent.click(screen.getByRole('button', { name: 'Voir cet aperçu · 22222222' }))
  await userEvent.click(await screen.findByRole('button', { name: 'Importer les observations valides' }))
  await userEvent.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
  await waitFor(() => expect(fake.invalid).toHaveBeenCalledOnce())
  expect(fake.request.mock.calls.filter(([, options]) => options?.method === 'POST')).toHaveLength(1)
})
