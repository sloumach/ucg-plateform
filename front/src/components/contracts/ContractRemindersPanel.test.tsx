import { act, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { tenancyRequest, type ActiveOrganization } from '../../api/tenancy'
import { ApiClientError } from '../../api/errors'
import type { ReminderPlan, ReminderDelivery } from '../../api/contractReminders'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { ContractRemindersPanel, MyContractReminders } from './ContractRemindersPanel'

vi.mock('../../api/tenancy', () => ({ tenancyRequest: vi.fn() }))
const request = vi.mocked(tenancyRequest)
const id = '11111111-1111-4111-8111-111111111111', version = '22222222-2222-4222-8222-222222222222'
const active: ActiveOrganization = { context: { organization_id: id, name: 'Alpha', slug: 'alpha', timezone: 'Europe/Paris', language: 'fr',
  roles: ['member'], permissions: ['administrative.read', 'administrative.write'], is_owner: false, actor_user_id: 42, membership_id: id }, revision: version, confirmed: false }
const path = 'tenants/' + id + '/contracts/' + id + '/reminders'
const plan: ReminderPlan = { id, version, enabled: true, notice_on: '2026-11-01', renewal_on: null, offsets: [7, 30, 90], channels: ['in_app', 'email'], checked_at: null }
const delivery: ReminderDelivery = { id, version, contract_id: id, kind: 'deadline', due_on: '2026-12-01', offset_days: 30,
  in_app_status: 'sent', email_status: 'uncertain', reason: 'delivery_uncertain', attempts: 1, created_at: '2026-11-01T00:00:00Z', email_sent_at: null }
const changed = vi.fn(), invalid = vi.fn()
const page = (items: ReminderDelivery[]) => ({ data: items, meta: { current_page: 1, last_page: 1, total: items.length } })
function show(selection = active, restricted = false, editable = true) {
  return render(<NotificationProvider><ContractRemindersPanel active={selection} path={path} contractVersion={version} endDate="2026-12-01" restricted={restricted}
    editable={editable} disabled={false} onChanged={changed} onInvalid={invalid} /></NotificationProvider>)
}
beforeEach(() => {
  vi.resetAllMocks()
  request.mockImplementation(async (url, options) => {
    if (options?.method) return { data: plan }
    return url.includes('/history') ? page([delivery]) : { data: plan, meta: { scheduler_enabled: true, email_ready: true } }
  })
})
async function open(user: ReturnType<typeof userEvent.setup>) {
  await user.click(screen.getByRole('button', { name: 'Échéances et rappels' }))
  return screen.findByRole('form', { name: 'Enregistrer les rappels' })
}
async function confirm(user: ReturnType<typeof userEvent.setup>) {
  const region = screen.getByRole('region', { name: 'Confirmer l’action' })
  expect(region).toHaveTextContent('Alpha')
  await user.click(within(region).getByRole('button', { name: 'Confirmer et continuer' }))
}
describe('rappels de contrats', () => {
  it('n’expose ni ne charge un dossier financier sans habilitation', () => {
    show(active, true)
    expect(screen.queryByText('Échéances et rappels')).not.toBeInTheDocument()
    expect(request).not.toHaveBeenCalled()
  })
  it('charge à la demande et confirme le tenant avant les deux canaux et les versions', async () => {
    const user = userEvent.setup(); show()
    expect(request).not.toHaveBeenCalled()
    const form = await open(user)
    await user.clear(within(form).getByLabelText('Délais des rappels (jours)'))
    await user.type(within(form).getByLabelText('Délais des rappels (jours)'), '60, 15, 0')
    await user.click(within(form).getByRole('button', { name: 'Enregistrer les rappels' }))
    expect(request.mock.calls.filter(([, options]) => options?.method)).toHaveLength(0)
    await confirm(user)
    await waitFor(() => expect(changed).toHaveBeenCalledOnce())
    expect(request).toHaveBeenCalledWith(path, expect.objectContaining({ method: 'PATCH', revision: version,
      body: { contract_version: version, expected_version: version, enabled: true, notice_on: '2026-11-01', renewal_on: null, offsets: [60, 15, 0], channels: ['in_app', 'email'] } }))
    const writes = request.mock.calls.filter(([, options]) => options?.method)
    expect(writes[0][0]).toBe('active-organization/confirmation')
  })
  it('affiche l’attente de configuration et prépare une première configuration désactivée', async () => {
    request.mockImplementation(async (url) => url.includes('/history') ? page([]) : { data: null, meta: { scheduler_enabled: false, email_ready: false } })
    const user = userEvent.setup(); show(); const form = await open(user)
    expect(within(form).getByLabelText('Activer les rappels')).not.toBeChecked()
    expect(screen.getByText(/déclenchement automatique.*activation/)).toBeVisible()
    expect(screen.getByText(/canal e-mail attend/)).toBeVisible()
    await user.click(within(form).getByRole('button', { name: 'Enregistrer les rappels' })); await confirm(user)
    await waitFor(() => expect(request).toHaveBeenCalledWith(path, expect.objectContaining({ body: expect.objectContaining({ expected_version: null, enabled: false }) })))
  })
  it('demande une décision humaine et une acceptation explicite avant une relance incertaine', async () => {
    const user = userEvent.setup(); show(); await open(user)
    expect(screen.getByText(/Aucune relance automatique/)).toBeVisible()
    const form = screen.getByRole('form', { name: 'Enregistrer la décision sur l’envoi' })
    await user.selectOptions(within(form).getByLabelText('Décision après vérification'), 'retry')
    await user.click(within(form).getByLabelText(/J’accepte le risque/))
    await user.click(within(form).getByRole('button', { name: 'Enregistrer la décision sur l’envoi' })); await confirm(user)
    await waitFor(() => expect(request).toHaveBeenCalledWith(path + '/' + id + '/resolution', expect.objectContaining({ method: 'POST',
      body: { expected_version: version, action: 'retry', confirm_possible_duplicate: true } })))
  })
  it('rend les réglages et le suivi en lecture seule', async () => {
    show({ ...active, context: { ...active.context!, permissions: ['administrative.read'] } }, false, false)
    await userEvent.click(screen.getByRole('button', { name: 'Échéances et rappels' }))
    expect(await screen.findByText('Réglages des rappels en lecture seule.')).toBeVisible()
    expect(screen.queryByRole('form')).not.toBeInTheDocument()
    expect(screen.getByText(/Envoi à vérifier/)).toBeVisible()
  })
  it('invalide un accès révoqué et ne répète pas une écriture rejetée', async () => {
    request.mockImplementation(async (url, options) => {
      if (options?.method === 'PATCH') throw new ApiClientError('Accès révoqué.', { code: 'FORBIDDEN', status: 403 })
      return url.includes('/history') ? page([]) : { data: plan }
    })
    const user = userEvent.setup(); show(); const form = await open(user)
    await user.click(within(form).getByRole('button', { name: 'Enregistrer les rappels' })); await confirm(user)
    await waitFor(() => expect(invalid).toHaveBeenCalledOnce())
    expect(request.mock.calls.filter(([, options]) => options?.method === 'PATCH')).toHaveLength(1)
  })
  it('annule les deux lectures au démontage sans traiter leurs réponses tardives', async () => {
    const finish: ((value: unknown) => void)[] = []
    request.mockImplementation(() => new Promise((resolve) => finish.push(resolve)))
    const view = show(); await userEvent.click(screen.getByRole('button', { name: 'Échéances et rappels' }))
    const signals = request.mock.calls.map(([, options]) => options?.signal)
    view.unmount(); signals.forEach((signal) => expect(signal?.aborted).toBe(true))
    await act(async () => { finish[0]({ data: plan }); finish[1](page([delivery])) })
    expect(changed).not.toHaveBeenCalled(); expect(invalid).not.toHaveBeenCalled()
  })
  it('charge la boîte personnelle avec la révision tenant et ouvre le dossier choisi', async () => {
    const select = vi.fn()
    request.mockResolvedValue(page([delivery]))
    render(<MyContractReminders active={active} disabled={false} onInvalid={invalid} onSelect={select} />)
    expect(request).not.toHaveBeenCalled()
    await userEvent.click(screen.getByRole('button', { name: 'Mes rappels de contrats' }))
    await userEvent.click(await screen.findByRole('button', { name: 'Consulter le contrat' }))
    expect(select).toHaveBeenCalledWith(id)
    expect(request).toHaveBeenCalledWith('tenants/' + id + '/contracts/reminders?per_page=10&page=1', expect.objectContaining({ revision: version, signal: expect.any(AbortSignal) }))
  })
})
