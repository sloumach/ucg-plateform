import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { tenancyRequest, type ActiveOrganization } from '../../api/tenancy'
import { ApiClientError } from '../../api/errors'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { PersonLifecyclePanel } from './PersonLifecyclePanel'

vi.mock('../../api/tenancy', () => ({ tenancyRequest: vi.fn() }))
const request = vi.mocked(tenancyRequest)
const org = '11111111-1111-4111-8111-111111111111'
const version = '22222222-2222-4222-8222-222222222222'
const person = { id: '33333333-3333-4333-8333-333333333333', version, pseudonym: 'Camille', user_id: 43 }
const period = { id: '44444444-4444-4444-8444-444444444444', version, person_id: person.id, kind: 'assignment', role_key: 'coach', starts_at: '2026-10-12T12:00:00+00:00', ends_at: null, status: 'active', effective: true }
const item = { id: '55555555-5555-4555-8555-555555555555', version, person_id: person.id, kind: 'equipment', title: 'Keyboard', notes: 'Private note', status: 'open', due_at: null, completed_at: null }
const all = ['sports.read', 'sports.write', 'administrative.read', 'administrative.write', 'memberships.manage', 'invitations.manage']
function active(permissions = all): ActiveOrganization {
  return { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'], permissions, is_owner: false, actor_user_id: 42, membership_id: 'membership' }, revision: version, confirmed: false }
}
const page = (data: unknown[], extra = {}) => ({ data, meta: { request_id: 'lifecycle-test', current_page: 1, last_page: 1, total: data.length, ...extra } })
function show(permissions = all) {
  const invalid = vi.fn(), closed = vi.fn()
  const result = render(<NotificationProvider><PersonLifecyclePanel active={active(permissions)} person={person} view="general" catalogue={{ games: [] }} disabled={false} onInvalid={invalid} onClosed={closed} /></NotificationProvider>)
  return { ...result, invalid, closed }
}
describe('Suivi des affectations et départ', () => {
  beforeEach(() => {
    request.mockReset()
    request.mockImplementation(async (path, options) => {
      if (options?.method) return { data: { departed: true, person_version: version }, notification: { type: 'success', message: 'Enregistré.' } }
      if (path.endsWith('/departure')) return { data: { departed: false, person_version: version } }
      if (path.includes('/departure-items')) return page([item], { pending_items_count: 1 })
      if (path.includes('/periods')) return page([period], { unavailable_now: false })
      return page([])
    })
  })
  it('un lecteur sportif ne charge ni checklist privée ni commande de départ', async () => {
    show(['sports.read'])
    await screen.findByText('Périodes de suivi sportif')
    expect(screen.queryByText('Private note')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Confirmer le départ' })).not.toBeInTheDocument()
    expect(request.mock.calls.some(([path]) => path.includes('departure-items'))).toBe(false)
  })
  it('une permission de gouvernance manquante cache le départ malgré les droits métiers', async () => {
    show(all.filter((permission) => permission !== 'memberships.manage'))
    await screen.findByText('Périodes de suivi sportif')
    expect(screen.queryByRole('form', { name: 'Départ effectif de l’organisation' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Ajouter un élément à régulariser' })).toBeVisible()
  })
  it('la confirmation précise organisation et personne puis envoie version et reconnaissance des éléments ouverts', async () => {
    const user = userEvent.setup()
    const { invalid } = show()
    const form = await screen.findByRole('form', { name: 'Départ effectif de l’organisation' })
    await user.selectOptions(within(form).getByLabelText('Motif de départ'), 'decision')
    await user.click(within(form).getByLabelText('Je confirme le départ effectif de cette personne.'))
    await user.click(within(form).getByLabelText('Je confirme que les éléments ouverts resteront à traiter après le retrait des accès.'))
    await user.click(within(form).getByRole('button', { name: 'Confirmer le départ' }))
    expect(request.mock.calls.some(([, options]) => options?.method)).toBe(false)
    const confirmation = screen.getByRole('region', { name: 'Confirmer l’action' })
    expect(confirmation).toHaveFocus()
    expect(confirmation).toHaveTextContent('Camille')
    expect(confirmation).toHaveTextContent('Alpha')
    await user.click(within(confirmation).getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(invalid).toHaveBeenCalledTimes(1))
    expect(request).toHaveBeenCalledWith('tenants/' + org + '/people/' + person.id + '/departure', expect.objectContaining({ method: 'POST', revision: version, body: { version, reason_key: 'decision', confirm: true, acknowledge_pending: true } }))
  })
  it('annuler la confirmation ne clôture ni la fiche ni les accès', async () => {
    const user = userEvent.setup()
    show()
    const form = await screen.findByRole('form', { name: 'Départ effectif de l’organisation' })
    await user.click(within(form).getByRole('button', { name: 'Confirmer le départ' }))
    await user.click(screen.getByRole('button', { name: 'Annuler' }))
    expect(request.mock.calls.some(([, options]) => options?.method)).toBe(false)
  })
  it('une fiche clôturée cache départ et périodes modifiables mais conserve la régularisation', async () => {
    const base = request.getMockImplementation()!
    request.mockImplementation(async (path, options) => path.endsWith('/departure') ? { data: { departed: true, person_version: version, departed_at: '2026-10-12T12:00:00+00:00' } } : base(path, options))
    const { closed } = show()
    await screen.findByText(/Départ enregistré le/)
    expect(closed).toHaveBeenCalledWith(true)
    expect(screen.queryByRole('button', { name: 'Ajouter une période' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Confirmer le départ' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Modifier l’élément · Keyboard' })).toBeVisible()
  })
  it('annuler une période envoie seulement statut et version, sans refaire les dates ni le rôle', async () => {
    const user = userEvent.setup()
    show()
    await user.click(await screen.findByRole('button', { name: 'Modifier la période · Coach' }))
    const form = screen.getByRole('form', { name: 'Enregistrer la période' })
    await user.selectOptions(within(form).getByLabelText('État de la référence'), 'cancelled')
    await user.click(within(form).getByRole('button', { name: 'Enregistrer la période' }))
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/' + org + '/people/' + person.id + '/periods/' + period.id, expect.objectContaining({ method: 'PATCH', body: { status: 'cancelled', version } })))
  })
  it('régulariser un équipement préserve les notes intactes et ne choisit pas la date de réalisation côté client', async () => {
    const user = userEvent.setup()
    show()
    await user.click(await screen.findByRole('button', { name: 'Modifier l’élément · Keyboard' }))
    const form = screen.getByRole('form', { name: 'Enregistrer l’élément' })
    await user.selectOptions(within(form).getByLabelText('État de la régularisation'), 'resolved')
    await user.click(within(form).getByRole('button', { name: 'Enregistrer l’élément' }))
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/' + org + '/people/' + person.id + '/departure-items/' + item.id, expect.objectContaining({ method: 'PATCH', body: { status: 'resolved', version } })))
  })
  it('une version obsolète recharge le contexte sans rejouer le départ', async () => {
    const user = userEvent.setup()
    const base = request.getMockImplementation()!
    request.mockImplementation(async (path, options) => {
      if (path.endsWith('/departure') && options?.method === 'POST') throw new ApiClientError('Fiche modifiée.', { status: 409, code: 'PERSON_VERSION_STALE' })
      return base(path, options)
    })
    const { invalid } = show()
    const form = await screen.findByRole('form', { name: 'Départ effectif de l’organisation' })
    await user.click(within(form).getByRole('button', { name: 'Confirmer le départ' }))
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(invalid).toHaveBeenCalledTimes(1))
    expect(request.mock.calls.filter(([path, options]) => path.endsWith('/departure') && options?.method === 'POST')).toHaveLength(1)
  })
})
