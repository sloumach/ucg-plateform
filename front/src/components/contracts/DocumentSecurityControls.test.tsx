import { act, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest'
import { tenancyRequest, type ActiveOrganization } from '../../api/tenancy'
import { ApiClientError } from '../../api/errors'
import type { ContractDocumentVersion } from '../../api/contractDocuments'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { DocumentSecurityControls } from './DocumentSecurityControls'

vi.mock('../../api/tenancy', () => ({ tenancyRequest: vi.fn() }))
const request = vi.mocked(tenancyRequest), invalid = vi.fn(), changed = vi.fn()
const scanId = '11111111-1111-4111-8111-111111111111'
const path = 'tenants/org/contracts/contract/documents/document/versions/version'
const active = { context: { organization_id: 'org', actor_user_id: 42, name: 'Alpha' }, revision: 'revision', confirmed: true } as ActiveOrganization
const version: ContractDocumentVersion = { id: 'version', number: 1, purpose: 'draft', filename: 'contrat.pdf', sha256: 'a'.repeat(64), bytes: 15,
  created_by: 42, created_at: '2026-10-14', scan_status: 'clean', scan_id: scanId, download_available: true, publication_status: 'private' }
function show(overrides: Partial<ContractDocumentVersion> = {}, manage = true) {
  return render(<NotificationProvider><DocumentSecurityControls active={active} path={path} version={{ ...version, ...overrides }}
    manage={manage} disabled={false} onInvalid={invalid} onChanged={changed} /></NotificationProvider>)
}
beforeEach(() => {
  vi.resetAllMocks()
  Object.defineProperty(URL, 'createObjectURL', { configurable: true, value: vi.fn(() => 'blob:private') })
  Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: vi.fn() })
  vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
  request.mockImplementation(async (_url, options) => options?.download ? new Blob(['%PDF-1.4\n%%EOF'], { type: 'application/pdf' }) : {
    data: { url: '/api/v1/' + path + '/download?actor=42&scan=' + scanId + '&expires=9999999999&signature=signed', filename: version.filename, expires_at: '2026-10-14' },
    meta: { request_id: 'download' },
  })
})
afterEach(() => vi.restoreAllMocks())
describe('analyses et téléchargements privés', () => {
  it.each(['pending', 'queued', 'processing', 'failed', 'rejected', 'stale'] as const)('bloque le téléchargement pour %s', (status) => {
    show({ scan_status: status, download_available: true })
    expect(screen.queryByRole('button', { name: 'Télécharger le PDF privé' })).not.toBeInTheDocument()
    expect(request).not.toHaveBeenCalled()
  })
  it('laisse le lecteur télécharger après émission d’un lien frais, sans relance', async () => {
    show({}, false); const user = userEvent.setup()
    expect(screen.queryByRole('form')).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Télécharger le PDF privé' }))
    await waitFor(() => expect(URL.createObjectURL).toHaveBeenCalledTimes(1))
    expect(request).toHaveBeenNthCalledWith(1, path + '/download-link', expect.objectContaining({ method: 'POST', revision: 'revision', body: { expected_scan_id: scanId }, signal: expect.any(AbortSignal) }))
    expect(request).toHaveBeenNthCalledWith(2, expect.stringContaining(path + '/download?'), expect.objectContaining({ download: 'pdf', revision: 'revision' }))
    expect(HTMLAnchorElement.prototype.click).toHaveBeenCalledTimes(1)
    expect(screen.queryByRole('link')).not.toBeInTheDocument()
    await waitFor(() => expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:private'))
  })
  it('confirme la relance avec l’identifiant de la dernière analyse', async () => {
    request.mockResolvedValue({ data: { id: scanId, status: 'queued' } })
    show({ scan_status: 'failed', download_available: false })
    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Demander une nouvelle analyse' }))
    expect(request).not.toHaveBeenCalled()
    await user.click(within(screen.getByRole('region', { name: 'Confirmer l’action' })).getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(changed).toHaveBeenCalledOnce())
    expect(request).toHaveBeenCalledWith(path + '/scans', expect.objectContaining({ method: 'POST', body: { expected_scan_id: scanId }, revision: 'revision' }))
  })
  it.each([401, 403, 404, 409])('invalide une réponse %s sans rejouer le téléchargement', async (status) => {
    request.mockRejectedValue(new ApiClientError('Téléchargement refusé.', { status, code: 'CONTRACT_DOCUMENT_NOT_READY', requestId: 'refused' }))
    show(); await userEvent.setup().click(screen.getByRole('button', { name: 'Télécharger le PDF privé' }))
    await waitFor(() => expect(invalid).toHaveBeenCalledOnce())
    expect(request).toHaveBeenCalledTimes(1)
    expect(URL.createObjectURL).not.toHaveBeenCalled()
  })
  it('refuse une URL externe avant d’envoyer les cookies', async () => {
    request.mockResolvedValue({ data: { url: 'https://external.example/document.pdf' } })
    show(); await userEvent.setup().click(screen.getByRole('button', { name: 'Télécharger le PDF privé' }))
    await screen.findAllByRole('alert')
    expect(request).toHaveBeenCalledTimes(1)
    expect(URL.createObjectURL).not.toHaveBeenCalled()
  })
  it('annule la requête et ignore les octets reçus après fermeture', async () => {
    let resolve: ((value: Blob) => void) | undefined
    const base = request.getMockImplementation()!
    request.mockImplementation((url, options) => options?.download ? new Promise((done) => { resolve = done }) : base(url, options))
    const view = show(); await userEvent.setup().click(screen.getByRole('button', { name: 'Télécharger le PDF privé' }))
    await waitFor(() => expect(resolve).toBeDefined())
    const signal = request.mock.calls[1][1]?.signal
    view.unmount(); expect(signal?.aborted).toBe(true)
    await act(async () => { resolve?.(new Blob(['private'])) })
    expect(URL.createObjectURL).not.toHaveBeenCalled()
  })
  it('charge l’historique uniquement à la demande et avec une pagination bornée', async () => {
    request.mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, total: 0 } })
    show()
    expect(request).not.toHaveBeenCalled()
    await userEvent.setup().click(screen.getByRole('button', { name: 'Consulter les analyses' }))
    await screen.findByText('Aucune analyse enregistrée.')
    expect(request).toHaveBeenCalledWith(path + '/scans?per_page=10&page=1', expect.objectContaining({ revision: 'revision', signal: expect.any(AbortSignal) }))
  })
})
