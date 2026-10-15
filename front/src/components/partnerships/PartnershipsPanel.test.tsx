import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { tenancyRequest, type ActiveOrganization } from '../../api/tenancy'
import { ApiClientError } from '../../api/errors'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { PartnershipsPanel } from './PartnershipsPanel'
import type { Sponsor, SponsorContact, SponsorshipOpportunity, SponsorshipInteraction } from '../../api/partnerships'

vi.mock('../../api/tenancy', () => ({ tenancyRequest: vi.fn() }))
const request = vi.mocked(tenancyRequest)
const org = '11111111-1111-4111-8111-111111111111', revision = '22222222-2222-4222-8222-222222222222', id = '33333333-3333-4333-8333-333333333333'
const active: ActiveOrganization = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'], permissions: ['partnerships.read', 'partnerships.write'], is_owner: false, actor_user_id: 42, membership_id: 'member' }, revision, confirmed: false }
const sponsor: Sponsor = { id, name: 'Prospect exemple', industry: 'Matériel', status: 'prospect', version: revision, publication_status: 'private' }
const contact: SponsorContact = { id, sponsor_id: id, name: 'Camille contact', position: 'Direction', email: 'contact@example.test', phone: null, status: 'active', version: revision, publication_status: 'private' }
const opportunity: SponsorshipOpportunity = { id, sponsor_id: id, title: 'Campagne été', description: 'Activation', status: 'negotiating', version: revision, publication_status: 'private' }
const interaction: SponsorshipInteraction = { id, sponsor_id: id, contact_id: id, opportunity_id: null, channel: 'meeting', summary: '<script>PRIVATE</script>', occurred_at: '2026-01-01T12:00:00+00:00', created_at: '2026-01-01T12:00:00+00:00', created_by: 42, publication_status: 'private' }
const invalid = vi.fn()
let sponsors: Sponsor[] = [], contacts: SponsorContact[] = [], opportunities: SponsorshipOpportunity[] = [], interactions: SponsorshipInteraction[] = []
function show(selection = active) { return render(<NotificationProvider><PartnershipsPanel active={selection} disabled={false} onInvalid={invalid} /></NotificationProvider>) }
beforeEach(() => {
  vi.resetAllMocks(); sponsors = []; contacts = []; opportunities = []; interactions = []
  request.mockImplementation(async (path, options) => {
    if (path === 'active-organization/confirmation') return { data: { ...active, confirmed: true } }
    if (options?.method) return { data: path.includes('/contacts') ? contact : path.includes('/opportunities') ? opportunity : path.includes('/interactions') ? interaction : sponsor }
    const rows = path.includes('/contacts?') ? contacts : path.includes('/opportunities?') ? opportunities : path.includes('/interactions?') ? interactions : sponsors
    return { data: rows, meta: { current_page: 1, last_page: 1, total: rows.length, request_id: 'list' } }
  })
})
async function openDetails(user: ReturnType<typeof userEvent.setup>) {
  await user.click(screen.getByRole('button', { name: 'Ouvrir les prospects' }))
  await user.click(await screen.findByRole('button', { name: 'Ouvrir le dossier' }))
  await screen.findByRole('heading', { name: 'Contacts', level: 4 })
}

describe('prospects et contacts partenaires', () => {
  it('loads only when opened and makes no request with only HR permissions', async () => {
    const user = userEvent.setup(), view = show()
    expect(request).not.toHaveBeenCalled()
    await user.click(screen.getByRole('button', { name: 'Ouvrir les prospects' }))
    expect(await screen.findByText('Aucun résultat pour cette recherche.')).toBeInTheDocument()
    expect(request).toHaveBeenCalledWith(expect.stringContaining('/sponsors?per_page=10&page=1'), expect.objectContaining({ revision, signal: expect.any(AbortSignal) }))
    view.unmount(); request.mockClear()
    show({ ...active, context: { ...active.context!, permissions: ['administrative.read'] } })
    expect(screen.queryByRole('heading', { name: 'Prospects et partenariats' })).not.toBeInTheDocument()
    expect(request).not.toHaveBeenCalled()
  })

  it('requires tenant confirmation before creating a prospect and never sends a tenant or account in the body', async () => {
    const user = userEvent.setup(); show()
    await user.click(screen.getByRole('button', { name: 'Ouvrir les prospects' }))
    await screen.findByText('Aucun résultat pour cette recherche.')
    await user.click(screen.getByRole('button', { name: 'Nouveau prospect' }))
    await user.type(screen.getByLabelText('Nom de la société'), 'Prospect exemple')
    await user.click(screen.getByRole('button', { name: 'Enregistrer la fiche partenaire' }))
    expect(request.mock.calls.some(([, options]) => options?.method)).toBe(false)
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/' + org + '/sponsors', expect.objectContaining({ method: 'POST', revision, body: { name: 'Prospect exemple', industry: null, status: 'prospect' } })))
    const writes = request.mock.calls.filter(([, options]) => options?.method)
    expect(writes[0][0]).toBe('active-organization/confirmation')
  })

  it('shows read-only contacts without editing controls or HR lookups', async () => {
    sponsors = [sponsor]; contacts = [contact]
    const user = userEvent.setup(); show({ ...active, context: { ...active.context!, permissions: ['partnerships.read'] } })
    await openDetails(user)
    expect(await screen.findByText(/contact@example.test/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Modifier' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Nouveau contact' })).not.toBeInTheDocument()
    expect(request.mock.calls.every(([path]) => path.includes('/sponsors'))).toBe(true)
  })

  it('creates a commercial contact without linking to a login or HR record', async () => {
    sponsors = [sponsor]
    const user = userEvent.setup(); show(); await openDetails(user)
    await screen.findByText('Aucun résultat pour cette recherche.')
    await user.click(screen.getByRole('button', { name: 'Nouveau contact' }))
    await user.type(screen.getByLabelText('Nom du contact'), 'Camille contact')
    await user.type(screen.getByLabelText('E-mail professionnel'), 'contact@example.test')
    await user.click(screen.getByRole('button', { name: 'Enregistrer le contact' }))
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/' + org + '/sponsors/' + id + '/contacts', expect.objectContaining({ method: 'POST', body: { name: 'Camille contact', position: null, email: 'contact@example.test', phone: null, status: 'active' } })))
  })

  it('edits negotiation status with the current version and only changed fields', async () => {
    sponsors = [sponsor]; opportunities = [opportunity]
    const user = userEvent.setup(); show(); await openDetails(user)
    await user.click(screen.getByRole('button', { name: 'Opportunités' }))
    await screen.findByText('Campagne été · En négociation')
    const detail = screen.getByRole('region', { name: 'Prospect exemple' })
    await user.click(within(detail).getByRole('button', { name: 'Modifier' }))
    const form = screen.getByRole('form', { name: 'Enregistrer l’opportunité' })
    await user.selectOptions(within(form).getByLabelText('Statut'), 'won')
    await user.click(within(form).getByRole('button', { name: 'Enregistrer l’opportunité' }))
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith(expect.stringContaining('/opportunities/' + id), expect.objectContaining({ method: 'PATCH', body: { status: 'won', version: revision } })))
  })

  it('records a contact-linked manual exchange and escapes the history', async () => {
    sponsors = [sponsor]; contacts = [contact]; interactions = [interaction]
    const user = userEvent.setup(); show(); await openDetails(user)
    await user.click(await screen.findByRole('button', { name: 'Consigner un échange lié' }))
    expect(await screen.findByText('Échange concernant : Camille contact')).toBeInTheDocument()
    await user.type(screen.getByLabelText('Compte rendu de l’échange'), 'Rendez-vous réalisé')
    await user.click(screen.getByRole('button', { name: 'Ajouter à l’historique' }))
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith(expect.stringContaining('/interactions'), expect.objectContaining({ method: 'POST', body: expect.objectContaining({ summary: 'Rendez-vous réalisé', contact_id: id, channel: 'note' }) })))
    expect(screen.getByText('<script>PRIVATE</script>')).toBeInTheDocument()
    expect(document.querySelector('script')).toBeNull()
    expect(screen.queryByRole('button', { name: 'Modifier' })).toBeInTheDocument() // The parent sponsor remains editable.
    const detail = screen.getByRole('region', { name: 'Prospect exemple' })
    expect(within(detail).queryByRole('button', { name: 'Modifier' })).not.toBeInTheDocument()
  })

  it('keeps archived dossiers readable without write controls', async () => {
    sponsors = [{ ...sponsor, status: 'archived' }]; contacts = [contact]
    const user = userEvent.setup(); show(); await openDetails(user)
    await screen.findByText(/contact@example.test/)
    expect(screen.queryByRole('button', { name: 'Modifier' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Nouveau contact' })).not.toBeInTheDocument()
  })

  it('clears stale results and invalidates the workspace after access is revoked', async () => {
    const user = userEvent.setup(); show()
    request.mockRejectedValueOnce(new ApiClientError('Accès refusé.', { status: 403 }))
    await user.click(screen.getByRole('button', { name: 'Ouvrir les prospects' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('Accès refusé.')
    expect(invalid).toHaveBeenCalledOnce()
  })

  it('aborts an obsolete read on close and does not display its late response', async () => {
    const user = userEvent.setup(); show()
    let resolve: (value: unknown) => void = () => {}
    request.mockImplementationOnce(() => new Promise((done) => { resolve = done }))
    await user.click(screen.getByRole('button', { name: 'Ouvrir les prospects' }))
    const signal = request.mock.calls[0][1]?.signal
    await user.click(screen.getByRole('button', { name: 'Fermer les prospects' }))
    expect(signal?.aborted).toBe(true)
    resolve({ data: [sponsor], meta: { current_page: 1, last_page: 1 } })
    expect(screen.queryByText('Prospect exemple')).not.toBeInTheDocument()
  })
})
