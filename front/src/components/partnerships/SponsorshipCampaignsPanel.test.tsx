import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ActiveOrganization } from '../../api/tenancy'
import type { SponsorshipAgreement } from '../../api/sponsorshipAgreements'
import type { SponsorshipCampaign, SponsorshipDeliverable } from '../../api/sponsorshipCampaigns'
import { ApiClientError } from '../../api/errors'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { SponsorshipCampaignsPanel } from './SponsorshipCampaignsPanel'
import { SponsorshipDashboardPanel } from './SponsorshipDashboardPanel'
const { request, invalid, changed } = vi.hoisted(() => ({ request: vi.fn(), invalid: vi.fn(), changed: vi.fn() }))
vi.mock('../../api/tenancy', async (original) => ({ ...await original<typeof import('../../api/tenancy')>(), tenancyRequest: request }))
const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222', scopeId = '33333333-3333-4333-8333-333333333333', version = '44444444-4444-4444-8444-444444444444', nextVersion = '55555555-5555-4555-8555-555555555555'
const root = 'tenants/' + org + '/sponsors/' + id + '/agreements/' + id + '/campaigns', detail = root + '/' + id
const active: ActiveOrganization = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', language: 'fr', timezone: 'UTC', roles: ['member'], permissions: ['partnerships.read', 'partnerships.write', 'partnerships.validate'], actor_user_id: 42, is_owner: false, membership_id: id }, revision: version, confirmed: false }
const agreement: SponsorshipAgreement = { id, sponsor_id: id, opportunity_id: null, reference: 'accord', title: 'Accord', starts_on: '2026-01-01', ends_on: '2026-12-31', responsible_user_id: 41, status: 'approved', version, prepared_by: 41, approved_by: 43, approved_at: '2026-01-01T00:00:00Z', publication_status: 'private' }
const base: SponsorshipCampaign = { id, agreement_id: id, title: 'Campagne automne', starts_on: '2026-01-01', ends_on: '2026-12-31', responsible_user_id: 42, responsible_label: 'Camille', status: 'active', version, publication_status: 'private' }
const promise: SponsorshipDeliverable = { id, agreement_id: id, campaign_id: id, exposure_id: scopeId, kind: 'video', title: 'Vidéo promise', responsible_user_id: 41, responsible_label: 'Alex', due_at: '2026-01-02T12:00:00Z', status: 'promised', version, prepared_by: 41, proof_reference: null, delivered_at: null, delivered_by: null, validated_at: null, validated_by: null, overdue: true, delivered_late: false, exposure_authorized: true, validation_matches_agreement: false, publication_status: 'private' }
let campaign: SponsorshipCampaign, rows: SponsorshipCampaign[], promises: SponsorshipDeliverable[]
function show(selection = active, readOnly = false) { return render(<NotificationProvider><SponsorshipCampaignsPanel active={selection} agreement={agreement} path={root} disabled={false} readOnly={readOnly} onInvalid={invalid} onChanged={changed} /></NotificationProvider>) }
async function open(user: ReturnType<typeof userEvent.setup>) {
  await user.click(screen.getByRole('button', { name: 'Ouvrir les campagnes' }))
  await user.click(await screen.findByRole('button', { name: 'Ouvrir la campagne' }))
  await screen.findByRole('heading', { name: 'Livrables de la campagne' })
}
async function confirm(user: ReturnType<typeof userEvent.setup>) { await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' })) }
beforeEach(() => {
  vi.resetAllMocks(); campaign = { ...base }; rows = [campaign]; promises = [{ ...promise }]
  request.mockImplementation(async (path, options) => {
    if (path === 'active-organization/confirmation') return { data: { ...active, confirmed: true } }
    if (options?.method) {
      const body = options.body as Record<string, unknown>
      if (path === root) { campaign = { ...base, ...body, version: nextVersion } as SponsorshipCampaign; rows = [campaign]; return { data: campaign } }
      if (path.endsWith('/deliveries')) promises = [{ ...promises[0], status: 'delivered', proof_reference: String(body.proof_reference), delivered_by: 42, delivered_at: String(body.delivered_at), version: nextVersion, overdue: false }]
      else if (path.endsWith('/transitions')) promises = [{ ...promises[0], status: body.target_status, validated_by: 42, validated_at: '2026-10-16T12:00:00Z', version: nextVersion, validation_matches_agreement: true } as SponsorshipDeliverable]
      else if (path.endsWith('/deliverables')) promises = [{ ...promise, ...body, version: nextVersion } as SponsorshipDeliverable]
      campaign = { ...campaign, version: nextVersion }; rows = [campaign]
      return { data: { ...promises[0], campaign_version: nextVersion, agreement_version: version } }
    }
    if (path === detail) return { data: campaign }
    let data: unknown[] = path.includes('/deliverables?') ? promises : path.includes('/history?') ? [] : rows
    if (path.includes('sponsorship-references/members')) data = [{ id: '42', label: 'Camille' }, { id: '41', label: 'Alex' }]
    if (path.includes('/exposures?')) data = [{ id: scopeId, target_label: 'Organisation Alpha' }]
    if (path.includes('/sponsorship-dashboard?')) return { data: promises, summary: { promised: 3, overdue: 2, delivered: 1, validated: 4 }, meta: { current_page: 1, last_page: 2, total: 3, request_id: 'test' } }
    return { data, meta: { current_page: 1, last_page: 1, total: data.length, request_id: 'test' } }
  })
})
describe('campagnes et livrables', () => {
  it('does not load campaigns until opened, then uses bounded requests without financial data', async () => {
    const user = userEvent.setup(); show()
    expect(request).not.toHaveBeenCalled()
    await open(user)
    expect(await screen.findByText('En retard — livraison attendue')).toBeInTheDocument()
    expect(request).toHaveBeenCalledWith(root + '?per_page=10&page=1', expect.objectContaining({ revision: version, signal: expect.any(AbortSignal) }))
    expect(request.mock.calls.every(([path]) => !path.includes('/financial') && !path.includes('/people'))).toBe(true)
  })
  it('creates a campaign only after tenant confirmation with the current agreement version', async () => {
    rows = []; const user = userEvent.setup(); show()
    await user.click(screen.getByRole('button', { name: 'Ouvrir les campagnes' }))
    await user.click(screen.getByRole('button', { name: 'Nouvelle campagne' }))
    const form = screen.getByRole('form', { name: 'Enregistrer la campagne' })
    await user.type(within(form).getByLabelText('Nom de la campagne'), 'Campagne test')
    await user.click(within(form).getByRole('button', { name: 'Enregistrer la campagne' }))
    expect(request.mock.calls.every(([, options]) => !options?.method)).toBe(true)
    await confirm(user)
    await waitFor(() => expect(request).toHaveBeenCalledWith(root, expect.objectContaining({ method: 'POST', body: expect.objectContaining({ title: 'Campagne test', responsible_user_id: 42, agreement_version: version }) })))
    expect(request.mock.calls.filter(([, options]) => options?.method)[0][0]).toBe('active-organization/confirmation')
    expect(changed).toHaveBeenCalled()
  })
  it('promises a deliverable against a selected exposure and sends typed versions', async () => {
    const user = userEvent.setup(); show(); await open(user)
    await user.click(screen.getByRole('button', { name: 'Nouveau livrable' }))
    const form = screen.getByRole('form', { name: 'Enregistrer le livrable' })
    await user.type(within(form).getByLabelText('Titre du livrable'), 'Placement test')
    await user.selectOptions(within(form).getByLabelText('Type de livrable'), 'brand_placement')
    await user.selectOptions(within(form).getByLabelText('Périmètre de l’accord'), scopeId)
    await user.click(within(form).getByRole('button', { name: 'Enregistrer le livrable' }))
    await confirm(user)
    await waitFor(() => expect(request).toHaveBeenCalledWith(detail + '/deliverables', expect.objectContaining({ method: 'POST', body: expect.objectContaining({ title: 'Placement test', kind: 'brand_placement', exposure_id: scopeId, responsible_user_id: 42, agreement_version: version, campaign_version: version }) })))
  })
  it('records evidence as text without validating the delivery automatically', async () => {
    const user = userEvent.setup(); show(); await open(user)
    await user.click(screen.getByRole('button', { name: 'Déclarer une livraison' }))
    const form = screen.getByRole('form', { name: 'Enregistrer la livraison' })
    await user.type(within(form).getByLabelText('Référence de la preuve'), '<script>TEST_PROOF</script>')
    await user.click(within(form).getByRole('button', { name: 'Enregistrer la livraison' }))
    await confirm(user)
    await waitFor(() => expect(request).toHaveBeenCalledWith(detail + '/deliverables/' + id + '/deliveries', expect.objectContaining({ method: 'POST', body: expect.objectContaining({ proof_reference: '<script>TEST_PROOF</script>', version, campaign_version: version, agreement_version: version }) })))
    expect(await screen.findByText(/Référence de la preuve : <script>TEST_PROOF/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Valider la preuve' })).not.toBeInTheDocument()
    expect(screen.getByText('Un autre membre doit valider cette livraison.')).toBeInTheDocument()
    expect(document.querySelector('script')).toBeNull()
  })
  it('requires an explicit evidence confirmation for an independent validator', async () => {
    promises = [{ ...promise, status: 'delivered', overdue: false, delivered_by: 41, delivered_at: '2026-10-16T10:00:00Z', proof_reference: 'Preuve vérifiée' }]
    const user = userEvent.setup(); show(); await open(user)
    const form = screen.getByRole('form', { name: 'Valider la preuve' })
    await user.click(within(form).getByRole('checkbox'))
    await user.click(within(form).getByRole('button', { name: 'Valider la preuve' }))
    await confirm(user)
    await waitFor(() => expect(request).toHaveBeenCalledWith(detail + '/deliverables/' + id + '/transitions', expect.objectContaining({ method: 'POST', body: { agreement_version: version, campaign_version: version, version, target_status: 'validated', confirm_evidence: true } })))
  })
  it('preserves reads while hiding mutation controls for readers and archived dossiers', async () => {
    const user = userEvent.setup(); show({ ...active, context: { ...active.context!, permissions: ['partnerships.read'] } }, true); await open(user)
    await screen.findByText('Vidéo promise · Promis')
    expect(screen.queryByRole('button', { name: 'Nouvelle campagne' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Nouveau livrable' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Déclarer une livraison' })).not.toBeInTheDocument()
  })
  it('invalidates the workspace if access is revoked', async () => {
    request.mockRejectedValue(new ApiClientError('Accès retiré', { status: 403 }))
    const user = userEvent.setup(); show(); await user.click(screen.getByRole('button', { name: 'Ouvrir les campagnes' }))
    await waitFor(() => expect(invalid).toHaveBeenCalled())
  })
})
describe('suivi des engagements', () => {
  it('shows separate counts and paginated overdue deliveries with escaped titles', async () => {
    promises = [{ ...promise, title: '<script>RETARD</script>' }]
    const user = userEvent.setup()
    render(<SponsorshipDashboardPanel active={active} disabled={false} onInvalid={invalid} />)
    expect(await screen.findByText('<script>RETARD</script>')).toBeInTheDocument()
    expect(screen.getByText('Preuves à valider')).toBeInTheDocument()
    expect(screen.getByText('Validations enregistrées')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Page suivante' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/' + org + '/sponsorship-dashboard?per_page=10&page=2', expect.anything()))
  })
  it('does not fetch the dashboard without partnership access', () => {
    render(<SponsorshipDashboardPanel active={{ ...active, context: { ...active.context!, permissions: ['sports.read'] } }} disabled={false} onInvalid={invalid} />)
    expect(request).not.toHaveBeenCalled()
    expect(screen.queryByText('Suivi des engagements partenaires')).not.toBeInTheDocument()
  })
})
