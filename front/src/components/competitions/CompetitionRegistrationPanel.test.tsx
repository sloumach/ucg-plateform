import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ActiveOrganization } from '../../api/tenancy'
import type { ExternalCompetition } from '../../api/externalCompetitions'
import { emptyRegistration, type CompetitionRegistration } from '../../api/competitionRegistrations'
import { ApiClientError } from '../../api/errors'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { CompetitionRegistrationPanel } from './CompetitionRegistrationPanel'
const fake = vi.hoisted(() => ({ request: vi.fn(), invalid: vi.fn() }))
vi.mock('../../api/tenancy', async (original) => ({ ...await original<typeof import('../../api/tenancy')>(), tenancyRequest: fake.request }))
const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222', version = '33333333-3333-4333-8333-333333333333'
const person = '44444444-4444-4444-8444-444444444444', itemId = '55555555-5555-4555-8555-555555555555'
const active: ActiveOrganization = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'], permissions: ['sports.read', 'sports.competitions.read', 'sports.registrations.read', 'sports.registrations.write'], actor_user_id: 42, is_owner: false, membership_id: id }, revision: version, confirmed: false }
const competition: ExternalCompetition = { id, reference: 'LEAGUE', game_id: id, game_key: 'pilot', game_name: 'Jeu', name: 'Compétition privée', organizer: 'Organisateur', region: 'Europe', season: '2026', website_url: null, contacts: [], status: 'active', version, created_at: '2026-10-18T10:00:00Z', updated_at: '2026-10-18T10:00:00Z' }
const registration: CompetitionRegistration = { version, number: 1, payload: { ...emptyRegistration, notes: '<script>Note privée</script>', source_url: 'https://organizer.example.test', roster: [{ person_id: person, role: 'starter', label: '<img>Joueur</img>' }], items: [{ id: itemId, kind: 'document', title: '<script>Pièce privée</script>', status: 'pending', due_at: '2026-10-20T10:00:00+00:00', responsible_person_id: person, responsible_label: '<img>Joueur</img>', note: null, document_version_id: null }] }, reason: 'Dossier privé ouvert par le manager.', actor_user_id: 42, occurred_at: '2026-10-18T10:00:00Z', summary: { pending: 1, due_soon: 1, overdue: 0 } }
const root = 'tenants/' + org + '/external-competitions', path = root + '/' + id + '/registration'
let current: CompetitionRegistration | null
let failure: ApiClientError | null
function page<T>(data: T[]) { return { data, meta: { current_page: 1, last_page: 1, total: data.length } } }
function show(selection = active, item = competition) { return render(<NotificationProvider><CompetitionRegistrationPanel active={selection} item={item} root={root} disabled={false} onInvalid={fake.invalid} onClose={() => {}} /></NotificationProvider>) }
beforeEach(() => {
  vi.resetAllMocks(); current = registration; failure = null
  fake.request.mockImplementation(async (target, options) => {
    if (target === 'active-organization/confirmation') return { data: { ...active, confirmed: true } }
    if (options?.method === 'PATCH') { if (failure) throw failure; current = { ...registration, number: 2 }; return { data: current } }
    if (target.includes('/people?')) return page([{ id: person, label: '<img>Joueur</img>' }])
    if (target.includes('/documents?')) return page([])
    if (target.includes('/history?')) return page([registration])
    if (target.includes('/reminders?')) return page([{ id, item_id: itemId, stage: 'week', payload: { title: 'Rappel privé', due_at: registration.payload.items[0].due_at, responsible_person_id: person }, occurred_at: registration.occurred_at }])
    return { data: current }
  })
})
describe('suivi de participation externe', () => {
  it('exige le droit dédié avant toute lecture', () => {
    show({ ...active, context: { ...active.context!, permissions: ['sports.read', 'sports.competitions.read'] } })
    expect(screen.queryByRole('region', { name: 'Suivi de participation' })).toBeNull(); expect(fake.request).not.toHaveBeenCalled()
  })
  it('échappe les textes, affiche les manquants et réserve les sources HTTPS', async () => {
    const view = show(); await screen.findByText(registration.payload.notes!)
    expect(view.container.querySelector('script,img,iframe')).toBeNull()
    expect(screen.getByRole('link', { name: 'Source externe HTTPS' })).toHaveAttribute('rel', 'noopener noreferrer')
    expect(screen.getByText(/Éléments manquants ou à faire : 1/)).toBeVisible(); expect(await screen.findByText('Rappel privé', { exact: false })).toBeVisible()
    expect(fake.request.mock.calls.some(([target]) => target.includes('/history?'))).toBe(false)
    await userEvent.click(screen.getByRole('button', { name: 'Historique du suivi de participation' })); expect(await screen.findByRole('region', { name: 'Historique du suivi de participation' })).toBeVisible()
  })
  it('envoie les deux versions et un roster sans labels après confirmation', async () => {
    show(); await screen.findByText(registration.payload.notes!); await userEvent.click(screen.getByRole('button', { name: 'Modifier le suivi de participation' }))
    const form = screen.getByRole('form', { name: 'Enregistrer le suivi de participation' })
    await within(within(form).getByLabelText('Joueur')).findByRole('option', { name: '<img>Joueur</img>' })
    fireEvent.change(within(form).getByLabelText('Notes internes'), { target: { value: 'Notes changées par le manager.' } })
    await userEvent.type(within(form).getByLabelText('Motif du changement'), 'Dépôt effectué sur le site externe.')
    fireEvent.submit(form); expect(fake.request.mock.calls.filter(([, options]) => options?.method === 'PATCH')).toHaveLength(0)
    await userEvent.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(screen.queryByRole('form', { name: 'Enregistrer le suivi de participation' })).toBeNull())
    const write = fake.request.mock.calls.find(([, options]) => options?.method === 'PATCH')!
    expect(write[0]).toBe(path); expect(write[1].body).toMatchObject({ competition_version: version, expected_version: version, roster: [{ person_id: person, role: 'starter' }], items: [{ id: itemId, due_at: '2026-10-20T10:00:00+00:00' }] })
    expect(write[1].body.roster[0]).not.toHaveProperty('label'); expect(write[1].body.items[0]).not.toHaveProperty('responsible_label')
  })
  it('crée un suivi vide avec expected_version null sans attribuer de statut externe', async () => {
    current = null; show(); await screen.findByText('Aucun suivi de participation enregistré.'); await userEvent.click(screen.getByRole('button', { name: 'Modifier le suivi de participation' }))
    const form = screen.getByRole('form', { name: 'Enregistrer le suivi de participation' }); await userEvent.type(within(form).getByLabelText('Motif du changement'), 'Ouverture du suivi de participation.')
    fireEvent.submit(form); await userEvent.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(fake.request.mock.calls.find(([, options]) => options?.method === 'PATCH')).toBeDefined())
    expect(fake.request.mock.calls.find(([, options]) => options?.method === 'PATCH')![1].body).toMatchObject({ expected_version: null, registration_status: 'preparing', roster_status: 'draft', roster: [], items: [] })
  })
  it('conserve les champs sur 422 et ne rejoue pas un conflit 409', async () => {
    show(); await screen.findByText(registration.payload.notes!); await userEvent.click(screen.getByRole('button', { name: 'Modifier le suivi de participation' }))
    const form = screen.getByRole('form', { name: 'Enregistrer le suivi de participation' }); await userEvent.type(within(form).getByLabelText('Motif du changement'), 'Confirmation de la source externe.')
    failure = new ApiClientError('Le roster doit être réévalué.', { status: 422, fieldErrors: { roster_status: ['Réévaluation requise.'] } }); fireEvent.submit(form); await userEvent.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    expect(await screen.findByText('Réévaluation requise.')).toBeVisible(); expect(within(form).getByLabelText('Motif du changement')).toHaveValue('Confirmation de la source externe.')
    failure = new ApiClientError('Le dossier a changé.', { status: 409 }); fireEvent.submit(form); await userEvent.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(fake.invalid).toHaveBeenCalledOnce()); expect(fake.request.mock.calls.filter(([, options]) => options?.method === 'PATCH')).toHaveLength(2)
  })
  it('laisse archives et lecteurs consulter sans formulaire et refuse les liens actifs', async () => {
    current = { ...registration, payload: { ...registration.payload, source_url: 'javascript:alert(1)' } }
    const view = show(active, { ...competition, status: 'archived' }); await screen.findByText(registration.payload.notes!)
    expect(screen.queryByRole('button', { name: 'Modifier le suivi de participation' })).toBeNull(); expect(screen.queryByRole('link')).toBeNull(); view.unmount()
    show({ ...active, context: { ...active.context!, permissions: ['sports.read', 'sports.competitions.read', 'sports.registrations.read'] } }); await screen.findByText(registration.payload.notes!); expect(screen.queryByRole('button', { name: 'Modifier le suivi de participation' })).toBeNull()
  })
  it('conserve la ligne restante et cible son champ après retrait et erreur 422', async () => {
    current = { ...registration, payload: { ...registration.payload, items: [registration.payload.items[0], { ...registration.payload.items[0], id: '77777777-7777-4777-8777-777777777777', title: 'Deuxième élément conservé' }] } }
    show(); await screen.findByText(registration.payload.notes!); await userEvent.click(screen.getByRole('button', { name: 'Modifier le suivi de participation' }))
    const form = screen.getByRole('form', { name: 'Enregistrer le suivi de participation' })
    await userEvent.click(within(form).getByRole('button', { name: 'Retirer l’élément 1' }))
    const title = within(form).getByLabelText('Intitulé de l’élément'); expect(title).toHaveValue('Deuxième élément conservé'); expect(title).toHaveAttribute('name', 'items.0.title')
    await userEvent.type(within(form).getByLabelText('Motif du changement'), 'Le premier élément a été retiré du suivi.')
    failure = new ApiClientError('Intitulé refusé.', { status: 422, fieldErrors: { 'items.0.title': ['Vérifiez cet intitulé.'] } })
    fireEvent.submit(form); await userEvent.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    expect(await screen.findByText('Vérifiez cet intitulé.')).toBeVisible(); await waitFor(() => expect(title).toHaveFocus())
    const write = fake.request.mock.calls.find(([, options]) => options?.method === 'PATCH')!
    expect(write[1].body.items).toHaveLength(1); expect(write[1].body.items[0].id).toBe('77777777-7777-4777-8777-777777777777')
  })

})
