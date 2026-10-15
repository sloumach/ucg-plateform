import { act, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { tenancyRequest, type ActiveOrganization } from '../../api/tenancy'
import { ApiClientError } from '../../api/errors'
import type { SensitiveAccessEntry } from '../../api/sensitiveAccess'
import { SensitiveAccessPanel } from './SensitiveAccessPanel'

vi.mock('../../api/tenancy', () => ({ tenancyRequest: vi.fn() }))
const request = vi.mocked(tenancyRequest)
const id = '11111111-1111-4111-8111-111111111111', revision = '22222222-2222-4222-8222-222222222222'
const active: ActiveOrganization = { context: { organization_id: id, name: 'Alpha', slug: 'alpha', timezone: 'Europe/Paris', language: 'fr',
  roles: ['member'], permissions: ['organization.view', 'audit.view'], is_owner: false, actor_user_id: 42, membership_id: id }, revision, confirmed: false }
const entry: SensitiveAccessEntry = { id, source: 'tenancy.sensitive', organization_id: id, actor_user_id: 43, action: 'contract.document_downloaded',
  target: { type: 'contract_document_version', id }, reason_code: 'internal_control', request_id: revision, ip_address: '127.0.0.1',
  before: {}, after: { salary: 'PRIVATE_CONTENT' }, occurred_at: '2026-10-15T10:00:00Z' }
const invalid = vi.fn()
const result = (next: string | null = null, entries = [entry]) => ({ data: entries, meta: { request_id: revision, per_page: 10, next_cursor: next } })
function show(selection = active) { return render(<SensitiveAccessPanel active={selection} disabled={false} onInvalid={invalid} />) }
beforeEach(() => { vi.resetAllMocks(); request.mockResolvedValue(result()) })
describe('journal des accès sensibles', () => {
  it('exige audit.view sans déduire un droit du statut de propriétaire', () => {
    show({ ...active, context: { ...active.context!, permissions: ['administrative.read'], is_owner: true } })
    expect(screen.queryByText('Journal des accès sensibles')).not.toBeInTheDocument()
    expect(request).not.toHaveBeenCalled()
  })
  it('charge à la demande les métadonnées dans le fuseau du tenant sans afficher le contenu arbitraire', async () => {
    show(); expect(request).not.toHaveBeenCalled()
    await userEvent.click(screen.getByRole('button', { name: 'Journal des accès sensibles' }))
    expect(await screen.findByRole('heading', { name: 'Téléchargement du document autorisé' })).toBeVisible()
    expect(screen.getByText(/12:00:00/)).toBeVisible()
    expect(screen.getByText(/Motif catégorisé : Contrôle interne/, { selector: 'p' })).toBeVisible()
    expect(screen.queryByText('PRIVATE_CONTENT')).not.toBeInTheDocument()
    expect(request).toHaveBeenCalledWith('tenants/' + id + '/audit-events?source=tenancy.sensitive&per_page=10', expect.objectContaining({ revision, signal: expect.any(AbortSignal) }))
    expect(screen.getByRole('button', { name: 'Accès suivants' })).toBeDisabled()
  })
  it('utilise le curseur opaque puis réinitialise la pagination après un filtre', async () => {
    request.mockImplementation(async (url) => result(url.includes('cursor=') ? null : 'OPAQUE_CURSOR'))
    const user = userEvent.setup(); show()
    await user.click(screen.getByRole('button', { name: 'Journal des accès sensibles' }))
    await user.click(await screen.findByRole('button', { name: 'Accès suivants' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith(expect.stringContaining('cursor=OPAQUE_CURSOR'), expect.anything()))
    const form = screen.getByRole('form', { name: 'Filtrer le journal' })
    await user.selectOptions(within(form).getByLabelText('Action'), 'tenant.export_read')
    await user.type(within(form).getByLabelText('Identifiant du compte intervenant'), '43')
    await user.click(within(form).getByRole('button', { name: 'Filtrer le journal' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/' + id + '/audit-events?source=tenancy.sensitive&per_page=10&action=tenant.export_read&actor_user_id=43', expect.anything()))
    expect(screen.getByRole('button', { name: 'Accès précédents' })).toBeDisabled()
  })
  it('efface le journal et invalide le contexte si le droit est retiré', async () => {
    const user = userEvent.setup(); show()
    await user.click(screen.getByRole('button', { name: 'Journal des accès sensibles' }))
    await screen.findByRole('heading', { name: 'Téléchargement du document autorisé' })
    request.mockRejectedValue(new ApiClientError('Droit retiré.', { code: 'FORBIDDEN', status: 403 }))
    await user.click(screen.getByRole('button', { name: 'Actualiser le journal' }))
    expect(await screen.findByText('Droit retiré.')).toBeVisible()
    expect(screen.queryByRole('heading', { name: 'Téléchargement du document autorisé' })).not.toBeInTheDocument()
    expect(invalid).toHaveBeenCalledOnce()
  })
  it('annule une lecture au démontage et ignore son résultat tardif', async () => {
    let finish: (value: unknown) => void = () => {}
    request.mockImplementation(() => new Promise((resolve) => { finish = resolve }))
    const view = show()
    await userEvent.click(screen.getByRole('button', { name: 'Journal des accès sensibles' }))
    const signal = request.mock.calls[0][1]?.signal
    view.unmount(); expect(signal?.aborted).toBe(true)
    await act(async () => finish(result()))
    expect(invalid).not.toHaveBeenCalled()
  })
  it('échappe les identifiants rendus et conserve une liste vide explicite', async () => {
    request.mockResolvedValue(result(null, [{ ...entry, target: { ...entry.target, id: '<script>test</script>' } }]))
    const user = userEvent.setup(); show()
    await user.click(screen.getByRole('button', { name: 'Journal des accès sensibles' }))
    expect(await screen.findByText('<script>test</script>')).toBeVisible()
    expect(document.querySelector('script')).toBeNull()
    request.mockResolvedValue(result(null, []))
    await user.click(screen.getByRole('button', { name: 'Actualiser le journal' }))
    expect(await screen.findByText('Aucun accès correspondant.')).toBeVisible()
  })
})
