import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { tenancyRequest, type ActiveOrganization } from '../../api/tenancy'
import { ApiClientError } from '../../api/errors'
import type { Sponsor } from '../../api/partnerships'
import type { SponsorshipAgreement, SponsorshipFinancial, SponsorshipExposure } from '../../api/sponsorshipAgreements'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { SponsorshipAgreementsPanel } from './SponsorshipAgreementsPanel'

vi.mock('../../api/tenancy', () => ({ tenancyRequest: vi.fn() }))
const request = vi.mocked(tenancyRequest), invalid = vi.fn()
const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222', version = '33333333-3333-4333-8333-333333333333', nextVersion = '44444444-4444-4444-8444-444444444444'
const root = 'tenants/' + org + '/sponsors/' + id + '/agreements', detail = root + '/' + id
const active: ActiveOrganization = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', language: 'fr', timezone: 'UTC', roles: ['member'], permissions: ['partnerships.read', 'partnerships.write', 'partnerships.validate', 'financial.read', 'financial.write', 'financial.validate'], actor_user_id: 42, is_owner: false, membership_id: id }, revision: version, confirmed: false }
const sponsor: Sponsor = { id, name: 'Sponsor exemple', industry: null, status: 'prospect', version, publication_status: 'private' }
const base: SponsorshipAgreement = { id, sponsor_id: id, opportunity_id: null, reference: 'accord-pilote', title: 'Accord matériel', starts_on: '2026-01-01', ends_on: '2026-12-31', responsible_user_id: 42, status: 'draft', version, prepared_by: 43, approved_by: null, approved_at: null, publication_status: 'private' }
const scope: SponsorshipExposure = { id, agreement_id: id, target_type: 'organization', target_id: org, target_label: 'Alpha', channel: 'website', description: null, authorization_reference: 'Consentement vérifié', exclusivity_key: null, status: 'active', version, exposure_authorized: false, publication_status: 'private' }
let record = base, rows: SponsorshipAgreement[] = [], scopes: SponsorshipExposure[] = [], financial: SponsorshipFinancial
function show(selection = active, partner = sponsor) { return render(<NotificationProvider><SponsorshipAgreementsPanel active={selection} sponsor={partner} disabled={false} onInvalid={invalid} /></NotificationProvider>) }
async function open(user: ReturnType<typeof userEvent.setup>) { await user.click(await screen.findByRole('button', { name: 'Ouvrir l’accord' })); await screen.findByRole('heading', { name: /accord-pilote.*Brouillon/ }) }
beforeEach(() => {
  vi.resetAllMocks(); record = { ...base }; rows = [record]; scopes = []; financial = { id: null, version: null, terms: null, agreement_version: version }
  request.mockImplementation(async (path, options) => {
    if (path === 'active-organization/confirmation') return { data: { ...active, confirmed: true } }
    if (options?.method) {
      if (path === root) { rows = [record]; return { data: record } }
      if (path.endsWith('/exposures')) { scopes = [scope]; record = { ...record, version: nextVersion }; return { data: { ...scope, agreement_version: nextVersion } } }
      if (path.endsWith('/financial')) { record = { ...record, version: nextVersion }; return { data: { ...financial, agreement_version: nextVersion } } }
      if (path.endsWith('/transitions')) { record = { ...record, status: 'approved', version: nextVersion }; return { data: record } }
      return { data: record }
    }
    if (path === detail) return { data: record }
    if (path.endsWith('/financial')) return { data: financial }
    let data: unknown = path.includes('/exposures?') ? scopes : path.includes('/history?') ? [] : rows
    if (path.includes('sponsorship-references/')) data = [{ id: '42', label: 'Camille membre' }]
    if (path.includes('/opportunities?')) data = []
    return { data, meta: { current_page: 1, last_page: 1, total: Array.isArray(data) ? data.length : 0, request_id: 'test' } }
  })
})

describe('accords partenaires', () => {
  it('loads bounded agreement lists without loading financial data or HR', async () => {
    show()
    expect(await screen.findByText('accord-pilote · Accord matériel')).toBeInTheDocument()
    expect(request).toHaveBeenCalledWith(root + '?per_page=10&page=1', expect.objectContaining({ revision: version, signal: expect.any(AbortSignal) }))
    expect(request.mock.calls.every(([path]) => !path.includes('/financial') && !path.includes('/people'))).toBe(true)
  })

  it('confirms a new agreement in the selected tenant before sending its typed fields', async () => {
    rows = []; const user = userEvent.setup(); show()
    await screen.findByText('Aucun résultat pour cette recherche.')
    await user.click(screen.getByRole('button', { name: 'Nouvel accord' }))
    const form = screen.getByRole('form', { name: 'Enregistrer l’accord' })
    await user.type(within(form).getByLabelText('Référence de l’accord'), 'accord-pilote')
    await user.type(within(form).getByLabelText('Titre de l’accord'), 'Accord matériel')
    await waitFor(() => expect(within(form).getByRole('option', { name: 'Camille membre' })).toBeInTheDocument())
    await user.click(within(form).getByRole('button', { name: 'Enregistrer l’accord' }))
    expect(request.mock.calls.filter(([, options]) => options?.method)).toHaveLength(0)
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith(root, expect.objectContaining({ method: 'POST', revision: version, body: expect.objectContaining({ reference: 'accord-pilote', title: 'Accord matériel', responsible_user_id: 42, opportunity_id: null }) })))
    expect(request.mock.calls.filter(([, options]) => options?.method)[0][0]).toBe('active-organization/confirmation')
  })

  it('keeps finance, approval and editing controls hidden for a partnership reader', async () => {
    const user = userEvent.setup(); show({ ...active, context: { ...active.context!, permissions: ['partnerships.read'] } }); await open(user)
    await screen.findByRole('heading', { name: 'Périmètres d’exposition' })
    expect(screen.queryByRole('button', { name: 'Conditions financières et apports' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Approuver l’accord et ses expositions' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Modifier l’accord' })).not.toBeInTheDocument()
    expect(request.mock.calls.every(([path]) => !path.includes('/financial'))).toBe(true)
  })

  it('adds a proposed organization exposure with the current parent version and a manual authorization reference', async () => {
    const user = userEvent.setup(); show(); await open(user)
    await user.click(screen.getByRole('button', { name: 'Ajouter un périmètre' }))
    const form = screen.getByRole('form', { name: 'Enregistrer le périmètre' })
    await user.type(within(form).getByLabelText('Référence de l’autorisation vérifiée'), 'Consentement vérifié')
    await user.click(within(form).getByRole('button', { name: 'Enregistrer le périmètre' }))
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith(detail + '/exposures', expect.objectContaining({ method: 'POST', body: { agreement_version: version, target_type: 'organization', target_id: org, channel: 'website', description: null, authorization_reference: 'Consentement vérifié', exclusivity_key: null } })))
    expect(await screen.findByText('Exposition non autorisée actuellement')).toBeInTheDocument()
  })

  it('keeps monetary values as decimal strings and sends a separate financial mutation', async () => {
    const user = userEvent.setup(); show(); await open(user)
    await user.click(screen.getByRole('button', { name: 'Conditions financières et apports' }))
    const form = await screen.findByRole('form', { name: 'Enregistrer les conditions financières' })
    await user.type(within(form).getByLabelText('Montant'), '12345.67')
    await user.click(within(form).getByRole('button', { name: 'Enregistrer les conditions financières' }))
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith(detail + '/financial', expect.objectContaining({ method: 'PATCH', body: { agreement_version: version, expected_version: null, terms: { kind: 'cash', currency: 'EUR', amount: '12345.67', in_kind: null, payment_terms: null } } })))
  })

  it('requires explicit exposure confirmation before requesting approval', async () => {
    const user = userEvent.setup(); show(); await open(user)
    const form = screen.getByRole('form', { name: 'Approuver l’accord et ses expositions' })
    await user.click(within(form).getByLabelText('Je confirme avoir vérifié les autorisations de tous les périmètres et les conditions financières.'))
    await user.click(within(form).getByRole('button', { name: 'Approuver l’accord et ses expositions' }))
    expect(request.mock.calls.filter(([path, options]) => path.endsWith('/transitions') && options?.method)).toHaveLength(0)
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith(detail + '/transitions', expect.objectContaining({ method: 'POST', body: { version, target_status: 'approved', confirm_authorizations: true } })))
    expect(await screen.findByRole('heading', { name: /accord-pilote.*Approuvé/ })).toBeInTheDocument()
  })

  it('explains separation of preparation and approval and freezes archived sponsors', async () => {
    record = { ...record, prepared_by: 42 }; const user = userEvent.setup(); const view = show(); await open(user)
    expect(screen.getByText(/L’approbation nécessite un autre intervenant/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Approuver l’accord et ses expositions' })).not.toBeInTheDocument()
    view.unmount(); show(active, { ...sponsor, status: 'archived' }); await open(user)
    expect(screen.queryByRole('button', { name: 'Modifier l’accord' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Ajouter un périmètre' })).not.toBeInTheDocument()
  })

  it('does not expose linked contract controls to a partnership-only role', async () => {
    record = { ...record, contract_id: id, contract_version: version }
    const user = userEvent.setup(); show(); await open(user)
    expect(screen.queryByRole('button', { name: 'Pièces du dossier contractuel lié' })).not.toBeInTheDocument()
    expect(request.mock.calls.every(([path]) => !path.includes('/contracts/'))).toBe(true)
  })

  it('discards agreement data when access is revoked', async () => {
    request.mockRejectedValueOnce(new ApiClientError('Accès refusé.', { status: 403 }))
    show()
    expect(await screen.findByRole('alert')).toHaveTextContent('Accès refusé.')
    expect(invalid).toHaveBeenCalledOnce()
    expect(screen.queryByText('accord-pilote · Accord matériel')).not.toBeInTheDocument()
  })
})
