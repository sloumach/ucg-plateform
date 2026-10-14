import { act, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { tenancyRequest, type ActiveOrganization } from '../../api/tenancy'
import { ApiClientError } from '../../api/errors'
import type { ContractFinancialTerms } from '../../api/contractGovernance'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { ContractFinancialPanel } from './ContractFinancialPanel'

vi.mock('../../api/tenancy', () => ({ tenancyRequest: vi.fn() }))
const request = vi.mocked(tenancyRequest)
const id = '11111111-1111-4111-8111-111111111111', version = '22222222-2222-4222-8222-222222222222'
const active: ActiveOrganization = { context: { organization_id: id, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr',
  roles: ['member'], permissions: ['administrative.read', 'administrative.write', 'financial.read', 'financial.write'], is_owner: false, actor_user_id: 42, membership_id: id }, revision: version, confirmed: false }
const path = 'tenants/' + id + '/contracts/' + id + '/financial-terms'
const stored: ContractFinancialTerms = { id, version, prepared_by: 41, updated_at: '2026-10-15T00:00:00Z',
  terms: { currency: 'EUR', remuneration: '123456789012.99', remuneration_period: 'monthly', bonus: '1500.01', prize_share_percent: '12.50', payment_terms: '<script>PRIVATE</script>' } }
const changed = vi.fn(), invalid = vi.fn()
function show(selection = active, editable = true) {
  return render(<NotificationProvider><ContractFinancialPanel active={selection} path={path} contractVersion={version} editable={editable} disabled={false} onChanged={changed} onInvalid={invalid} /></NotificationProvider>)
}
beforeEach(() => {
  vi.resetAllMocks()
  request.mockImplementation(async (_url, options) => options?.method ? { data: stored } : { data: stored })
})
async function open(user: ReturnType<typeof userEvent.setup>) {
  await user.click(screen.getByRole('button', { name: 'Conditions financières restreintes' }))
  return await screen.findByRole('form', { name: 'Enregistrer les conditions financières' })
}
async function confirm(user: ReturnType<typeof userEvent.setup>) {
  const region = screen.getByRole('region', { name: 'Confirmer l’action' })
  expect(region).toHaveTextContent('Alpha')
  await user.click(within(region).getByRole('button', { name: 'Confirmer et continuer' }))
}
describe('conditions financières', () => {
  it('ne charge ni ne rend aucune condition sans lecture financière explicite', () => {
    show({ ...active, context: { ...active.context!, permissions: ['administrative.read', 'administrative.write'], is_owner: true } })
    expect(request).not.toHaveBeenCalled()
    expect(screen.queryByText('Conditions financières restreintes')).not.toBeInTheDocument()
    expect(screen.queryByText('123456789012.99')).not.toBeInTheDocument()
  })
  it('charge à la demande et rend le texte sensible échappé en lecture seule', async () => {
    show({ ...active, context: { ...active.context!, permissions: ['administrative.read', 'financial.read'] } }, false)
    expect(request).not.toHaveBeenCalled()
    await userEvent.click(screen.getByRole('button', { name: 'Conditions financières restreintes' }))
    expect(await screen.findByText('123456789012.99')).toBeVisible()
    expect(screen.getByText('<script>PRIVATE</script>')).toBeVisible()
    expect(document.querySelector('script')).toBeNull()
    expect(screen.queryByRole('form')).not.toBeInTheDocument()
    expect(request).toHaveBeenCalledWith(path, expect.objectContaining({ revision: version, signal: expect.any(AbortSignal) }))
  })
  it('conserve les décimales en texte et exige la confirmation tenant avant le PATCH', async () => {
    const user = userEvent.setup(); show(); const form = await open(user)
    await user.clear(within(form).getByLabelText('Prime')); await user.type(within(form).getByLabelText('Prime'), '999999999999.99')
    await user.click(within(form).getByRole('button', { name: 'Enregistrer les conditions financières' }))
    expect(request.mock.calls.filter(([, options]) => options?.method)).toHaveLength(0)
    await confirm(user)
    await waitFor(() => expect(changed).toHaveBeenCalledOnce())
    expect(request).toHaveBeenCalledWith(path, expect.objectContaining({ method: 'PATCH', revision: version,
      body: { contract_version: version, expected_version: version, terms: { ...stored.terms, bonus: '999999999999.99' } } }))
    expect(request.mock.calls.findIndex(([url]) => url === 'active-organization/confirmation')).toBeLessThan(request.mock.calls.findIndex(([, options]) => options?.method === 'PATCH'))
  })
  it('propose une création avec une version attendue nulle après une lecture vide', async () => {
    request.mockResolvedValue({ data: null })
    const user = userEvent.setup(); show(); const form = await open(user)
    expect(screen.getByText('Aucune condition financière structurée renseignée.')).toBeVisible()
    await user.click(within(form).getByRole('button', { name: 'Enregistrer les conditions financières' })); await confirm(user)
    await waitFor(() => expect(request).toHaveBeenCalledWith(path, expect.objectContaining({ body: { contract_version: version, expected_version: null,
      terms: { currency: 'EUR', remuneration: null, remuneration_period: null, bonus: null, prize_share_percent: null, payment_terms: null } } })))
  })
  it('invalide le contexte après un refus sans réessayer le PATCH', async () => {
    request.mockImplementation(async (_url, options) => {
      if (options?.method === 'PATCH') throw new ApiClientError('Accès financier révoqué.', { code: 'FORBIDDEN', status: 403 })
      return { data: stored }
    })
    const user = userEvent.setup(); show(); const form = await open(user)
    await user.click(within(form).getByRole('button', { name: 'Enregistrer les conditions financières' })); await confirm(user)
    await waitFor(() => expect(invalid).toHaveBeenCalledOnce())
    expect(request.mock.calls.filter(([, options]) => options?.method === 'PATCH')).toHaveLength(1)
  })
  it('annule une lecture en cours au démontage et ignore son résultat tardif', async () => {
    let finish: (value: unknown) => void = () => {}
    request.mockImplementation(() => new Promise((resolve) => { finish = resolve }))
    const view = show(); await userEvent.click(screen.getByRole('button', { name: 'Conditions financières restreintes' }))
    const signal = request.mock.calls[0][1]?.signal
    view.unmount(); expect(signal?.aborted).toBe(true)
    await act(async () => finish({ data: stored }))
    expect(changed).not.toHaveBeenCalled(); expect(invalid).not.toHaveBeenCalled()
  })
})
