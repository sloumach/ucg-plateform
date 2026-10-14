import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { tenancyRequest, type ActiveOrganization } from '../../api/tenancy'
import { ApiClientError } from '../../api/errors'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { ContractsPanel } from './ContractsPanel'
import type { ContractRecord } from '../../api/contractRecords'

vi.mock('../../api/tenancy', () => ({ tenancyRequest: vi.fn() }))
const request = vi.mocked(tenancyRequest)
const org = '11111111-1111-4111-8111-111111111111', revision = '22222222-2222-4222-8222-222222222222'
const id = '33333333-3333-4333-8333-333333333333', personId = '44444444-4444-4444-8444-444444444444'
const active: ActiveOrganization = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr',
  roles: ['member'], permissions: ['administrative.read', 'administrative.write', 'administrative.validate'], is_owner: false, actor_user_id: 42, membership_id: id }, revision, confirmed: false }
const base: ContractRecord = { id, reference: 'dossier-pilote', type: 'player', title: 'Dossier pilote', responsible_person_id: personId, responsible_name: 'Camille Martin',
  starts_on: '2026-01-01', ends_on: null, status: 'draft', version: revision, prepared_by: 42, approved_by: null, approved_at: null, signed_on: null,
  signature_reference: null, signature_recorded_at: null, terminated_on: null, signature_evidence_status: 'not_declared', in_force: false,
  sporting_eligibility: 'not_assessed', publication_status: 'private', parties_count: 1, obligations_count: 1, pending_obligations_count: 1, available_transitions: ['in_review', 'archived'],
  parties: [{ id: personId, kind: 'external', person_id: null, name: 'Partie privée', position: 1 }],
  obligations: [{ id: personId, title: 'Restitution', due_on: null, position: 1, status: 'pending', version: revision }] }
const invalid = vi.fn()
let record: ContractRecord = base, rows: ContractRecord[] = []
function show(selection = active) { return render(<NotificationProvider><ContractsPanel active={selection} disabled={false} onInvalid={invalid} /></NotificationProvider>) }
beforeEach(() => {
  vi.resetAllMocks(); record = structuredClone(base); rows = []
  request.mockImplementation(async (path, options) => {
    if (path === 'active-organization/confirmation') return { data: { ...active, confirmed: true } }
    if (options?.method) return { data: record, meta: { request_id: 'saved' } }
    if (path.includes('/people?')) return { data: [{ id: personId, label: 'Camille Martin' }], meta: { current_page: 1, last_page: 1, total: 1 } }
    if (path.includes('/transitions?')) return { data: [{ id, from_status: null, to_status: 'draft', contract_version: revision, actor_user_id: 42, occurred_at: '2026-01-01T00:00:00+00:00' }], meta: { current_page: 1, last_page: 1, total: 1 } }
    if (path.endsWith('/' + id)) return { data: record }
    return { data: rows, meta: { current_page: 1, last_page: 1, total: rows.length } }
  })
})
async function open(user: ReturnType<typeof userEvent.setup>) {
  await user.click(screen.getByRole('button', { name: 'Contrats de l’organisation' }))
  await screen.findByRole('button', { name: 'Rechercher les contrats' })
}
async function detail(user: ReturnType<typeof userEvent.setup>) {
  await user.click(await screen.findByRole('button', { name: 'Consulter le dossier' }))
  return await screen.findByRole('region', { name: 'Consulter le dossier' })
}
async function approve(user: ReturnType<typeof userEvent.setup>) {
  const confirmation = screen.getByRole('region', { name: 'Confirmer l’action' })
  expect(confirmation).toHaveTextContent('Alpha')
  await user.click(within(confirmation).getByRole('button', { name: 'Confirmer et continuer' }))
}
describe('contrats administratifs', () => {
  it('n’expose rien au propriétaire ou au staff sportif sans droit administratif', () => {
    show({ ...active, context: { ...active.context!, permissions: ['sports.read', 'teams.manage'], is_owner: true } })
    expect(screen.queryByRole('button', { name: 'Contrats de l’organisation' })).not.toBeInTheDocument()
    expect(request).not.toHaveBeenCalled()
  })
  it('permet la lecture sans commandes de gestion et échappe les titres et parties', async () => {
    record = { ...base, title: '<script>CONTRACT_BAD</script>', parties: [{ ...base.parties![0], name: '<img onerror=BAD>' }] }; rows = [record]
    const user = userEvent.setup(); show({ ...active, context: { ...active.context!, permissions: ['administrative.read'] } }); await open(user)
    const panel = await detail(user)
    expect(within(panel).getByRole('heading', { name: '<script>CONTRACT_BAD</script>' })).toBeVisible()
    expect(within(panel).getByText(/<img onerror=BAD>/)).toBeVisible()
    expect(document.querySelector('script')).toBeNull()
    expect(screen.queryByRole('button', { name: 'Créer un dossier de contrat' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Modifier le dossier' })).not.toBeInTheDocument()
  })
  it('crée un dossier après confirmation du tenant avec des parties et obligations bornées', async () => {
    const user = userEvent.setup(); show(); await open(user)
    await user.click(screen.getByRole('button', { name: 'Créer un dossier de contrat' }))
    const form = screen.getByRole('form', { name: 'Enregistrer le dossier de contrat' })
    await within(form).findByRole('option', { name: 'Camille Martin' })
    await user.type(within(form).getByLabelText('Référence stable'), 'dossier-pilote')
    await user.type(within(form).getByLabelText('Intitulé du dossier'), 'Dossier pilote')
    await user.selectOptions(within(form).getByLabelText('Responsable du suivi'), personId)
    await user.type(within(form).getByLabelText('Nom administratif déclaré de la partie'), 'Partie privée')
    await user.click(within(form).getByRole('button', { name: 'Ajouter une obligation' }))
    await user.type(within(form).getByLabelText('Intitulé de l’obligation'), 'Restitution')
    await user.click(within(form).getByRole('button', { name: 'Enregistrer le dossier de contrat' }))
    expect(request.mock.calls.some(([, options]) => options?.method)).toBe(false)
    await approve(user)
    await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/' + org + '/contracts', expect.objectContaining({
      method: 'POST', revision, body: { reference: 'dossier-pilote', type: 'player', title: 'Dossier pilote', starts_on: null, ends_on: null,
        responsible_person_id: personId, parties: [{ kind: 'external', person_id: null, name: 'Partie privée' }], obligations: [{ title: 'Restitution', due_on: null }] },
    })))
  })
  it('n’envoie que le titre modifié sans remplacer les parties ni les obligations', async () => {
    rows = [record]
    const user = userEvent.setup(); show({ ...active, confirmed: true }); await open(user); const panel = await detail(user)
    await user.click(within(panel).getByRole('button', { name: 'Modifier le dossier' }))
    const form = screen.getByRole('form', { name: 'Enregistrer le dossier de contrat' })
    await within(form).findByRole('option', { name: 'Camille Martin' })
    await user.clear(within(form).getByLabelText('Intitulé du dossier')); await user.type(within(form).getByLabelText('Intitulé du dossier'), 'Nouveau titre')
    await user.click(within(form).getByRole('button', { name: 'Enregistrer le dossier de contrat' })); await approve(user)
    await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/' + org + '/contracts/' + id, expect.objectContaining({
      method: 'PATCH', body: { version: revision, title: 'Nouveau titre' },
    })))
  })
  it('cache l’auto-approbation même avec un droit de validation', async () => {
    record = { ...base, status: 'in_review', available_transitions: ['draft', 'approved', 'archived'] }; rows = [record]
    const user = userEvent.setup(); show(); await open(user); await detail(user)
    expect(within(screen.getByRole('form', { name: 'Enregistrer le changement de statut' })).queryByRole('option', { name: 'Approuvé' })).not.toBeInTheDocument()
    expect(screen.getByText(/Une autre personne habilitée/)).toBeVisible()
  })
  it('retire les transitions privilégiées pour un préparateur sans droit de validation', async () => {
    record = { ...base, status: 'sent', prepared_by: 7, available_transitions: ['in_review', 'signed'] }; rows = [record]
    const user = userEvent.setup(); show({ ...active, context: { ...active.context!, permissions: ['administrative.read', 'administrative.write'] } }); await open(user); await detail(user)
    const form = screen.getByRole('form', { name: 'Enregistrer le changement de statut' })
    expect(within(form).queryByRole('option', { name: 'Signature reçue' })).not.toBeInTheDocument()
    expect(within(form).getByRole('option', { name: 'En revue' })).toBeInTheDocument()
  })
  it('annuler un changement de statut ne déclenche aucune écriture', async () => {
    rows = [record]
    const user = userEvent.setup(); show(); await open(user); await detail(user)
    await user.click(screen.getByRole('button', { name: 'Enregistrer le changement de statut' }))
    await user.click(within(screen.getByRole('region', { name: 'Confirmer l’action' })).getByRole('button', { name: 'Annuler' }))
    expect(request.mock.calls.some(([, options]) => options?.method)).toBe(false)
  })
  it('enregistre une signature externe avec sa date, sa référence et une déclaration explicite', async () => {
    record = { ...base, status: 'sent', prepared_by: 7, available_transitions: ['in_review', 'signed'] }; rows = [record]
    const user = userEvent.setup(); show({ ...active, confirmed: true }); await open(user); await detail(user)
    const form = screen.getByRole('form', { name: 'Enregistrer le changement de statut' })
    await user.selectOptions(within(form).getByLabelText('Prochain statut'), 'signed')
    await user.type(within(form).getByLabelText('Date de la signature reçue'), '2026-01-01')
    await user.type(within(form).getByLabelText('Référence de l’original signé reçu'), 'Original conservé')
    await user.click(within(form).getByLabelText('Je confirme la réception d’une signature obtenue hors de la plateforme.'))
    await user.click(within(form).getByRole('button', { name: 'Enregistrer le changement de statut' })); await approve(user)
    await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/' + org + '/contracts/' + id + '/transitions', expect.objectContaining({
      method: 'POST', body: { version: revision, status: 'signed', signed_on: '2026-01-01', signature_reference: 'Original conservé', confirm_signature_received: true },
    })))
  })
  it('permet la régularisation d’une obligation après résiliation avec les deux versions courantes', async () => {
    record = { ...base, status: 'terminated', available_transitions: ['archived'] }; rows = [record]
    const user = userEvent.setup(); show({ ...active, confirmed: true }); await open(user); await detail(user)
    const form = screen.getByRole('form', { name: 'Obligation Restitution' })
    await user.selectOptions(within(form).getByLabelText('État de l’obligation'), 'waived')
    await user.click(within(form).getByRole('button', { name: 'Enregistrer le suivi de l’obligation' })); await approve(user)
    await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/' + org + '/contracts/' + id + '/obligations/' + personId, expect.objectContaining({
      method: 'PATCH', body: { version: revision, contract_version: revision, status: 'waived' },
    })))
  })
  it('conserve la saisie après 422 puis recharge le contexte après 409 sans rejouer la mutation', async () => {
    rows = [record]
    const user = userEvent.setup(); show({ ...active, confirmed: true }); await open(user); await detail(user)
    await user.click(screen.getByRole('button', { name: 'Modifier le dossier' }))
    const form = screen.getByRole('form', { name: 'Enregistrer le dossier de contrat' })
    await within(form).findByRole('option', { name: 'Camille Martin' })
    await user.clear(within(form).getByLabelText('Intitulé du dossier')); await user.type(within(form).getByLabelText('Intitulé du dossier'), 'Conservé')
    request.mockImplementationOnce(async () => { throw new ApiClientError('Invalide', { status: 422, fieldErrors: { title: ['Titre refusé.'] } }) })
    await user.click(within(form).getByRole('button', { name: 'Enregistrer le dossier de contrat' })); await approve(user)
    expect(await screen.findByText('Titre refusé.')).toBeVisible()
    expect(within(form).getByLabelText('Intitulé du dossier')).toHaveValue('Conservé')
    request.mockImplementationOnce(async () => { throw new ApiClientError('Obsolète', { status: 409, code: 'CONTRACT_VERSION_STALE' }) })
    await user.click(within(form).getByRole('button', { name: 'Enregistrer le dossier de contrat' })); await approve(user)
    await waitFor(() => expect(invalid).toHaveBeenCalledTimes(1))
    expect(request.mock.calls.filter(([, options]) => options?.method === 'PATCH')).toHaveLength(2)
  })
  it('les archives restent lisibles sans édition ni régularisation', async () => {
    record = { ...base, status: 'archived', available_transitions: [] }; rows = [record]
    const user = userEvent.setup(); show(); await open(user); await detail(user)
    expect(screen.queryByRole('button', { name: 'Modifier le dossier' })).not.toBeInTheDocument()
    expect(screen.queryByRole('form', { name: 'Enregistrer le changement de statut' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Enregistrer le suivi de l’obligation' })).not.toBeInTheDocument()
  })
  it('annule une lecture retardée de dossier après changement d’organisation', async () => {
    rows = [record]
    const user = userEvent.setup(); const view = show(); await open(user)
    let complete: ((value: unknown) => void) | undefined
    request.mockImplementationOnce(() => new Promise((resolve) => { complete = resolve }))
    await user.click(screen.getByRole('button', { name: 'Consulter le dossier' }))
    const signal = request.mock.calls.at(-1)?.[1]?.signal
    view.rerender(<NotificationProvider><ContractsPanel key="other" active={{ ...active, context: { ...active.context!, organization_id: 'other' } }} disabled={false} onInvalid={invalid} /></NotificationProvider>)
    expect(signal?.aborted).toBe(true)
    complete?.({ data: record })
    await waitFor(() => expect(screen.queryByRole('region', { name: 'Consulter le dossier' })).not.toBeInTheDocument())
  })
})
