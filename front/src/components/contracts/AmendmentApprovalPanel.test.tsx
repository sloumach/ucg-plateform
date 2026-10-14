import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { tenancyRequest, type ActiveOrganization } from '../../api/tenancy'
import type { ContractDocument } from '../../api/contractDocuments'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { AmendmentApprovalPanel } from './AmendmentApprovalPanel'

vi.mock('../../api/tenancy', () => ({ tenancyRequest: vi.fn() }))
const request = vi.mocked(tenancyRequest)
const id = '11111111-1111-4111-8111-111111111111', version = '22222222-2222-4222-8222-222222222222'
const active: ActiveOrganization = { context: { organization_id: id, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr',
  roles: ['member'], permissions: ['administrative.read', 'administrative.write', 'administrative.validate', 'financial.read', 'financial.validate'], is_owner: false, actor_user_id: 42, membership_id: id }, revision: version, confirmed: false }
const document = { id, kind: 'amendment', created_by: 41, latest_draft_id: version, latest_prepared_by: 41, financial_prepared_by: 41, financial_terms_present: true, approval: null, signed_version: null } as ContractDocument
const path = 'tenants/' + id + '/contracts/' + id + '/documents/' + id + '/approvals'
const changed = vi.fn(), invalid = vi.fn()
function show(selection = active, item = document) {
  return render(<NotificationProvider><AmendmentApprovalPanel active={selection} document={item} path={path} contractVersion={version} financialRequired disabled={false} onChanged={changed} onInvalid={invalid} /></NotificationProvider>)
}
beforeEach(() => {
  vi.resetAllMocks()
  request.mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 2, total: 11 } })
})
describe('approbation d’avenant', () => {
  it.each(['creator', 'preparer', 'finance', 'validator'] as const)('masque la commande sans séparation ou droit financier : %s', (reason) => {
    const item = { ...document }
    let selection = active
    if (reason === 'creator') item.created_by = 42
    if (reason === 'preparer') item.latest_prepared_by = 42
    if (reason === 'finance') item.financial_prepared_by = 42
    if (reason === 'validator') selection = { ...active, context: { ...active.context!, permissions: ['administrative.read', 'administrative.write', 'administrative.validate', 'financial.read'] } }
    show(selection, item)
    expect(screen.queryByRole('form')).not.toBeInTheDocument()
    expect(request).not.toHaveBeenCalled()
  })
  it('soumet la préparation courante après examen et confirmation de l’organisation', async () => {
    const user = userEvent.setup(); show()
    const form = screen.getByRole('form', { name: 'Approuver la préparation de l’avenant' })
    await user.click(within(form).getByRole('checkbox'))
    await user.click(within(form).getByRole('button', { name: 'Approuver la préparation de l’avenant' }))
    expect(request).not.toHaveBeenCalled()
    const region = screen.getByRole('region', { name: 'Confirmer l’action' })
    expect(region).toHaveTextContent('Alpha')
    await user.click(within(region).getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(changed).toHaveBeenCalledOnce())
    expect(request).toHaveBeenCalledWith(path, expect.objectContaining({ method: 'POST', revision: version,
      body: { contract_version: version, document_version_id: version, confirm_approval: true } }))
  })
  it('charge les décisions par pages seulement à la demande et empêche une nouvelle approbation identique', async () => {
    const user = userEvent.setup()
    show(active, { ...document, approval: { id, document_version_id: version, approved_by: 43, approved_at: '2026-10-15T00:00:00Z' } })
    expect(screen.queryByRole('form')).not.toBeInTheDocument(); expect(request).not.toHaveBeenCalled()
    await user.click(screen.getByRole('button', { name: 'Consulter les approbations de l’avenant' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith(path + '?per_page=10&page=1', expect.objectContaining({ revision: version })))
    await user.click(await screen.findByRole('button', { name: 'Page suivante' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith(path + '?per_page=10&page=2', expect.objectContaining({ signal: expect.any(AbortSignal) })))
  })
})
