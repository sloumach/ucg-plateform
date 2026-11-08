import { beforeEach, expect, it, vi } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ActiveOrganization } from '../../api/tenancy'
import type { SponsorshipAgreement, SponsorshipExposure } from '../../api/sponsorshipAgreements'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { AgreementExposuresPanel } from './AgreementExposuresPanel'

const { request, changed, invalid } = vi.hoisted(() => ({ request: vi.fn(), changed: vi.fn(), invalid: vi.fn() }))
vi.mock('../../api/tenancy', async (original) => ({ ...await original<typeof import('../../api/tenancy')>(), tenancyRequest: request }))
const id = '11111111-1111-4111-8111-111111111111', source = '22222222-2222-4222-8222-222222222222'
const version = '33333333-3333-4333-8333-333333333333', fresh = '44444444-4444-4444-8444-444444444444'
const active: ActiveOrganization = { context: { organization_id: id, name: 'Alpha', slug: 'alpha', language: 'fr', timezone: 'UTC', roles: ['member'], permissions: ['partnerships.read', 'partnerships.write', 'calendar.read', 'media.read'], actor_user_id: 42, membership_id: id, is_owner: false }, revision: version, confirmed: true }
const record: SponsorshipAgreement = { id, sponsor_id: id, opportunity_id: null, reference: 'accord', title: 'Accord', starts_on: '2026-01-01', ends_on: '2026-12-31', responsible_user_id: 42, status: 'draft', version, prepared_by: 42, approved_by: null, approved_at: null, publication_status: 'private' }
const path = 'tenants/' + id + '/sponsors/' + id + '/agreements/' + id + '/exposures'
const label = '<script>Référence partagée</script>'
let rows: SponsorshipExposure[]
function show(selection = active) { return render(<NotificationProvider><AgreementExposuresPanel active={selection} record={record} path={path} disabled={false} onChanged={changed} onInvalid={invalid} /></NotificationProvider>) }
beforeEach(() => {
  vi.resetAllMocks(); rows = []
  request.mockImplementation(async (target, options) => {
    if (options?.method) return { data: {} }
    if (target.includes('sponsorship-references')) return { data: target.includes('page=2') ? [{ id: fresh, label: 'Autre référence', version: fresh }] : [{ id: source, label, version }], meta: { current_page: target.includes('page=2') ? 2 : 1, last_page: 2, total: 2 } }
    return { data: rows, meta: { current_page: 1, last_page: 1, total: rows.length } }
  })
})

it.each(['event', 'content'])('sélectionne une référence %s et envoie sa version après confirmation', async (type) => {
  const user = userEvent.setup(), view = show()
  await user.click(await screen.findByRole('button', { name: 'Ajouter un périmètre' }))
  const form = within(screen.getByRole('form', { name: 'Enregistrer le périmètre' }))
  await user.selectOptions(form.getByLabelText('Périmètre concerné'), type)
  expect(form.getByRole('button', { name: 'Enregistrer le périmètre' })).toBeDisabled()
  await form.findByRole('option', { name: label })
  await user.selectOptions(form.getByLabelText('Événement ou média autorisé'), source)
  await user.type(form.getByLabelText('Référence de l’autorisation vérifiée'), 'Autorisation vérifiée')
  await user.click(form.getByRole('button', { name: 'Page suivante' }))
  await form.findByRole('option', { name: 'Autre référence' })
  await user.click(form.getByRole('button', { name: 'Enregistrer le périmètre' }))
  expect(request.mock.calls.some(([, options]) => options?.method)).toBe(false)
  await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
  expect(request).toHaveBeenCalledWith(path, expect.objectContaining({ method: 'POST', body: expect.objectContaining({ target_type: type, target_id: source, target_version: version, agreement_version: version }) }))
  expect(changed).toHaveBeenCalledOnce()
  expect(view.container.querySelector('script,img,iframe')).toBeNull()
})

it('ne propose aucun nouveau type sans la permission du module propriétaire', async () => {
  const user = userEvent.setup()
  show({ ...active, context: { ...active.context!, permissions: ['partnerships.read', 'partnerships.write'] } })
  await user.click(await screen.findByRole('button', { name: 'Ajouter un périmètre' }))
  expect(screen.queryByRole('option', { name: 'Événement du calendrier' })).not.toBeInTheDocument()
  expect(screen.queryByRole('option', { name: 'Média existant' })).not.toBeInTheDocument()
  expect(request.mock.calls.some(([target]) => target.includes('sponsorship-references'))).toBe(false)
})

it('efface la version et la sélection en revenant au périmètre organisation', async () => {
  const user = userEvent.setup(); show()
  await user.click(await screen.findByRole('button', { name: 'Ajouter un périmètre' }))
  const form = within(screen.getByRole('form', { name: 'Enregistrer le périmètre' }))
  await user.selectOptions(form.getByLabelText('Périmètre concerné'), 'event')
  await form.findByRole('option', { name: label })
  await user.selectOptions(form.getByLabelText('Événement ou média autorisé'), source)
  await user.selectOptions(form.getByLabelText('Périmètre concerné'), 'organization')
  await user.type(form.getByLabelText('Référence de l’autorisation vérifiée'), 'Autorisation vérifiée')
  await user.click(form.getByRole('button', { name: 'Enregistrer le périmètre' }))
  await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
  const body = request.mock.calls.find(([, options]) => options?.method === 'POST')![1].body
  expect(body).toMatchObject({ target_type: 'organization', target_id: id })
  expect(body).not.toHaveProperty('target_version')
})

it('exige une vérification explicite pour actualiser une référence fixe périmée', async () => {
  rows = [{ id: source, agreement_id: id, target_type: 'event', target_id: source, target_label: label, target_version: version, current_target_version: fresh, target_current: false,
    channel: 'event', description: null, authorization_reference: 'Autorisation vérifiée', exclusivity_key: null, status: 'active', version, exposure_authorized: false, publication_status: 'private' }]
  const user = userEvent.setup(); show()
  await user.click(await screen.findByRole('button', { name: 'Modifier le périmètre' }))
  const form = within(screen.getByRole('form', { name: 'Enregistrer le périmètre' }))
  expect(form.queryByLabelText('Périmètre concerné')).not.toBeInTheDocument()
  expect(form.getByRole('button', { name: 'Enregistrer le périmètre' })).toBeDisabled()
  await user.click(form.getByLabelText('J’ai vérifié la version courante de cette référence et je demande une nouvelle approbation de l’accord.'))
  await user.click(form.getByRole('button', { name: 'Enregistrer le périmètre' }))
  await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
  expect(request).toHaveBeenCalledWith(path + '/' + source, expect.objectContaining({ method: 'PATCH', body: { agreement_version: version, version, target_version: fresh } }))
})
