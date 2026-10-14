import { act, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { tenancyRequest, type ActiveOrganization } from '../../api/tenancy'
import { ApiClientError } from '../../api/errors'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { ContractDocumentsPanel } from './ContractDocumentsPanel'
import type { ContractRecord } from '../../api/contractRecords'
import type { ContractDocument, ContractDocumentVersion } from '../../api/contractDocuments'

vi.mock('../../api/tenancy', () => ({ tenancyRequest: vi.fn() }))
const request = vi.mocked(tenancyRequest)
const org = '11111111-1111-4111-8111-111111111111', revision = '22222222-2222-4222-8222-222222222222'
const id = '33333333-3333-4333-8333-333333333333', documentId = '44444444-4444-4444-8444-444444444444', versionId = '55555555-5555-4555-8555-555555555555'
const active: ActiveOrganization = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr',
  roles: ['member'], permissions: ['administrative.read', 'administrative.write', 'administrative.validate'], is_owner: false, actor_user_id: 42, membership_id: id }, revision, confirmed: false }
const record = { id, version: revision, status: 'draft', signed_on: null } as ContractRecord
const base: ContractDocument = { id: documentId, reference: 'original', kind: 'original', title: 'Document original', original_document_id: null, effective_on: null,
  created_by: 42, created_at: '2026-10-14T12:00:00+00:00', versions_count: 1, signed_version: null, signature_evidence_status: 'not_declared', publication_status: 'private' }
const version: ContractDocumentVersion = { id: versionId, number: 1, purpose: 'draft', filename: 'contrat.pdf', sha256: 'a'.repeat(64), bytes: 85,
  created_by: 42, created_at: '2026-10-14T12:00:00+00:00', scan_status: 'pending', download_available: false, publication_status: 'private' }
const path = 'tenants/' + org + '/contracts/' + id + '/documents', invalid = vi.fn(), changed = vi.fn()
let documents: ContractDocument[] = [], versions: ContractDocumentVersion[] = []
function show(selection = active, contract = record) {
  return render(<NotificationProvider><ContractDocumentsPanel active={selection} record={contract} disabled={false} onInvalid={invalid} onChanged={changed} /></NotificationProvider>)
}
beforeEach(() => {
  vi.resetAllMocks(); documents = []; versions = []
  request.mockImplementation(async (url, options) => {
    if (url === 'active-organization/confirmation') return { data: { ...active, confirmed: true } }
    if (options?.method) return { data: base, meta: { request_id: 'save', contract_version: versionId } }
    return { data: url.includes('/versions?') ? versions : documents, meta: { current_page: 1, last_page: 1, total: documents.length } }
  })
})
async function open(user: ReturnType<typeof userEvent.setup>) {
  await user.click(screen.getByRole('button', { name: 'Documents et avenants' }))
  await screen.findByRole('button', { name: 'Fermer les documents' })
  await waitFor(() => expect(screen.queryByText('Chargement des documents…')).not.toBeInTheDocument())
}
async function history(user: ReturnType<typeof userEvent.setup>) {
  await user.click(await screen.findByRole('button', { name: 'Consulter les versions · original' }))
  return await screen.findByRole('region', { name: 'Consulter les versions' })
}
async function confirm(user: ReturnType<typeof userEvent.setup>) {
  const region = screen.getByRole('region', { name: 'Confirmer l’action' })
  expect(region).toHaveTextContent('Alpha')
  await user.click(within(region).getByRole('button', { name: 'Confirmer et continuer' }))
}
describe('documents et avenants', () => {
  it('n’expose rien sans administrative.read et charge seulement à l’ouverture', async () => {
    const view = show({ ...active, context: { ...active.context!, permissions: ['sports.read'], is_owner: true } })
    expect(screen.queryByRole('button', { name: 'Documents et avenants' })).not.toBeInTheDocument()
    expect(request).not.toHaveBeenCalled()
    view.unmount(); show()
    expect(request).not.toHaveBeenCalled()
    await open(userEvent.setup())
    expect(request).toHaveBeenCalledWith(path + '?per_page=10&page=1', expect.objectContaining({ revision, signal: expect.any(AbortSignal) }))
  })
  it('affiche des versions échappées sans commande de gestion ni téléchargement au lecteur', async () => {
    documents = [{ ...base, title: '<script>DOC_BAD</script>' }]; versions = [{ ...version, filename: '<img onerror=BAD>.pdf' }]
    const user = userEvent.setup(); show({ ...active, context: { ...active.context!, permissions: ['administrative.read'] } }); await open(user)
    const panel = await history(user)
    expect(screen.getByRole('heading', { name: '<script>DOC_BAD</script>' })).toBeVisible()
    expect(within(panel).getByText(/<img onerror=BAD>.pdf/)).toBeVisible()
    expect(document.querySelector('script')).toBeNull()
    expect(within(panel).queryByRole('form')).not.toBeInTheDocument()
    expect(screen.queryByRole('link')).not.toBeInTheDocument()
    expect(within(panel).getByText(/En attente d’analyse/)).toBeVisible()
  })
  it('crée l’original après confirmation humaine avec la version courante du contrat', async () => {
    const user = userEvent.setup(); show(); await open(user)
    await user.click(screen.getByRole('button', { name: 'Ajouter un document ou un avenant' }))
    const form = screen.getByRole('form', { name: 'Ajouter un document ou un avenant' })
    await user.type(within(form).getByLabelText('Référence du document'), 'original')
    await user.type(within(form).getByLabelText('Intitulé du document'), 'Contrat original')
    await user.click(within(form).getByRole('button', { name: 'Ajouter un document ou un avenant' }))
    expect(request.mock.calls.filter(([, options]) => options?.method === 'POST')).toHaveLength(0)
    await confirm(user)
    expect(request).toHaveBeenCalledWith(path, expect.objectContaining({ method: 'POST', revision,
      body: { reference: 'original', title: 'Contrat original', kind: 'original', contract_version: revision } }))
    expect(changed).toHaveBeenCalledTimes(1)
  })
  it('annule une confirmation sans transmettre le document', async () => {
    const user = userEvent.setup(); show(); await open(user)
    await user.click(screen.getByRole('button', { name: 'Ajouter un document ou un avenant' }))
    await user.click(within(screen.getByRole('form')).getByRole('button', { name: 'Ajouter un document ou un avenant' }))
    await user.click(within(screen.getByRole('region', { name: 'Confirmer l’action' })).getByRole('button', { name: 'Annuler' }))
    expect(request.mock.calls.filter(([, options]) => options?.method)).toHaveLength(0)
  })
  it('transmet le fichier réel en multipart avec CSRF/révision et version du contrat', async () => {
    documents = [base]; versions = [version]
    const user = userEvent.setup(); show(); await open(user); const panel = await history(user)
    const form = await within(panel).findByRole('form', { name: 'Conserver une nouvelle version' })
    const file = new File(['%PDF-1.4\n%%EOF\n'], 'revision.pdf', { type: 'application/pdf' })
    await user.upload(within(form).getByLabelText('Fichier PDF privé (5 Mo maximum)'), file)
    await user.click(within(form).getByRole('button', { name: 'Conserver une nouvelle version' })); await confirm(user)
    const call = request.mock.calls.find(([url, options]) => url.endsWith('/versions') && options?.method === 'POST')!
    expect(call[1]?.revision).toBe(revision)
    const body = call[1]?.body as FormData
    expect(body.get('contract_version')).toBe(revision); expect(body.get('purpose')).toBe('draft'); expect(body.get('file')).toBe(file)
    expect(body.has('sha256')).toBe(false); expect(body.has('artifact_id')).toBe(false)
    expect(changed).toHaveBeenCalledTimes(1)
  })
  it('masque la copie signée et son identification sans administrative.validate', async () => {
    documents = [base]; versions = [{ ...version, purpose: 'signed_copy' }]
    const user = userEvent.setup(); show({ ...active, context: { ...active.context!, permissions: ['administrative.read', 'administrative.write'] } }, { ...record, status: 'signed' })
    await open(user); const panel = await history(user)
    expect(within(panel).queryByRole('form')).not.toBeInTheDocument()
  })
  it('identifie précisément la copie signée choisie après déclaration et confirmation', async () => {
    documents = [base]; versions = [{ ...version, purpose: 'signed_copy', number: 2 }]
    const user = userEvent.setup(); show(active, { ...record, status: 'signed', signed_on: '2026-10-14' }); await open(user); const panel = await history(user)
    const form = await within(panel).findByRole('form', { name: 'Identifier la version signée' })
    await user.click(within(form).getByRole('checkbox'))
    await user.click(within(form).getByRole('button', { name: 'Identifier la version signée' })); await confirm(user)
    expect(request).toHaveBeenCalledWith(path + '/' + documentId + '/signature', expect.objectContaining({
      body: { document_version_id: versionId, signed_on: '2026-10-14', confirm_signed_copy: true, contract_version: revision }, revision }))
  })
  it('lie l’avenant au document signé et garde ce dernier en lecture seule', async () => {
    documents = [{ ...base, signed_version: { id: versionId, number: 2, sha256: version.sha256, signed_on: '2026-10-14', recorded_by: 43, recorded_at: base.created_at } }]
    versions = [{ ...version, purpose: 'signed_copy', number: 2 }]
    const user = userEvent.setup(); show(active, { ...record, status: 'active' }); await open(user); const panel = await history(user)
    expect(within(panel).queryByRole('form')).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Ajouter un document ou un avenant' }))
    const form = screen.getByRole('form', { name: 'Ajouter un document ou un avenant' })
    await user.type(within(form).getByLabelText('Référence du document'), 'avenant-1')
    await user.type(within(form).getByLabelText('Intitulé du document'), 'Nouvel avenant')
    await user.type(within(form).getByLabelText('Date d’effet déclarée de l’avenant'), '2027-01-01')
    await user.click(within(form).getByRole('button', { name: 'Ajouter un document ou un avenant' })); await confirm(user)
    expect(request).toHaveBeenCalledWith(path, expect.objectContaining({ body: { reference: 'avenant-1', title: 'Nouvel avenant', kind: 'amendment',
      original_document_id: documentId, effective_on: '2027-01-01', contract_version: revision } }))
  })
  it('conserve la saisie après 422 et exige une relecture après 409 sans rejeu', async () => {
    const user = userEvent.setup(); show(); await open(user)
    await user.click(screen.getByRole('button', { name: 'Ajouter un document ou un avenant' }))
    const form = screen.getByRole('form')
    await user.type(within(form).getByLabelText('Référence du document'), 'original')
    // Confirm the tenant, then reject the business POST while retaining the input.
    request.mockImplementationOnce(async () => ({ data: { ...active, confirmed: true } }))
    request.mockRejectedValueOnce(new ApiClientError('Invalide', { status: 422, fieldErrors: { title: ['Titre requis'] } }))
    await user.click(within(form).getByRole('button', { name: 'Ajouter un document ou un avenant' })); await confirm(user)
    expect(await within(form).findByText('Titre requis')).toBeVisible()
    expect(within(form).getByLabelText('Référence du document')).toHaveValue('original')
    request.mockImplementationOnce(async () => ({ data: { ...active, confirmed: true } }))
    request.mockRejectedValueOnce(new ApiClientError('Obsolète', { status: 409 }))
    await user.click(within(form).getByRole('button', { name: 'Ajouter un document ou un avenant' })); await confirm(user)
    await waitFor(() => expect(invalid).toHaveBeenCalledTimes(1))
    expect(request.mock.calls.filter(([url, options]) => url === path && options?.method === 'POST')).toHaveLength(2)
  })
  it('garde les documents d’une archive en lecture seule', async () => {
    documents = [base]; versions = [version]
    const user = userEvent.setup(); show(active, { ...record, status: 'archived' }); await open(user); const panel = await history(user)
    expect(screen.queryByRole('button', { name: 'Ajouter un document ou un avenant' })).not.toBeInTheDocument()
    expect(within(panel).queryByRole('form')).not.toBeInTheDocument()
  })
  it('annule la lecture et ignore une réponse tardive après fermeture', async () => {
    let finish: ((value: unknown) => void) | undefined, signal: AbortSignal | undefined
    request.mockImplementationOnce(async (_url, options) => { signal = options?.signal; return new Promise((resolve) => { finish = resolve }) })
    const user = userEvent.setup(); show()
    await user.click(screen.getByRole('button', { name: 'Documents et avenants' }))
    await user.click(screen.getByRole('button', { name: 'Fermer les documents' }))
    expect(signal?.aborted).toBe(true)
    await act(async () => finish?.({ data: [{ ...base, title: 'LATE PRIVATE' }], meta: { current_page: 1, last_page: 1 } }))
    expect(screen.queryByText('LATE PRIVATE')).not.toBeInTheDocument()
  })
})
