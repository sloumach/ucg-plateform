import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ActiveOrganization } from '../../api/tenancy'
import type { ExternalCompetition } from '../../api/externalCompetitions'
import type { CompetitionOutcome, OutcomeStream } from '../../api/competitionOutcomes'
import { awardInput, awardMinor } from '../../api/competitionOutcomes'
import { ApiClientError } from '../../api/errors'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { CompetitionOutcomePanel } from './CompetitionOutcomePanel'
const fake = vi.hoisted(() => ({ request: vi.fn(), invalid: vi.fn() }))
vi.mock('../../api/tenancy', async (original) => ({ ...await original<typeof import('../../api/tenancy')>(), tenancyRequest: fake.request }))
const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222', version = '33333333-3333-4333-8333-333333333333'
const person = '44444444-4444-4444-8444-444444444444', itemId = '55555555-5555-4555-8555-555555555555'
const active: ActiveOrganization = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'], permissions: ['sports.read', 'sports.competitions.read', 'sports.competitions.write', 'financial.read', 'financial.write'], actor_user_id: 42, is_owner: false, membership_id: id }, revision: version, confirmed: false }
const competition: ExternalCompetition = { id, reference: 'LEAGUE', game_id: id, game_key: 'pilot', game_name: 'Jeu', name: 'Compétition privée', organizer: 'Organisateur', region: 'Europe', season: '2026', website_url: null, contacts: [], status: 'active', version, created_at: '2026-10-18T10:00:00Z', updated_at: '2026-10-18T10:00:00Z' }

const root = 'tenants/' + org + '/external-competitions'
const sporting: CompetitionOutcome = { version, number: 1, stream: 'sport', payload: { source: '<script>Rapport privé</script>', source_url: 'https://league.example.test/results', observed_on: '2026-10-18', notes: 'Suivi interne', place: 2, field_size: 16 }, matches: [{ reference_id: person, match: null }], reason: 'Rapport conservé avec sa source.', occurred_at: '2026-10-18T10:00:00Z' }
const awards: CompetitionOutcome = { ...sporting, stream: 'awards', matches: [], payload: { source: 'Rapport financier privé', source_url: null, observed_on: '2026-10-18', notes: null, currency: 'EUR', announced_minor: 100000, received_minor: null, received_on: null } }
let current: CompetitionOutcome | null, failure: ApiClientError | null
function page<T>(data: T[]) { return { data, meta: { current_page: 1, last_page: 1, total: data.length } } }
function show(stream: OutcomeStream = 'sport', selection = active, item = competition) { return render(<NotificationProvider><CompetitionOutcomePanel active={selection} item={item} root={root} stream={stream} disabled={false} onInvalid={fake.invalid} onClose={() => {}} /></NotificationProvider>) }
beforeEach(() => {
  vi.resetAllMocks(); current = sporting; failure = null
  fake.request.mockImplementation(async (target, options) => {
    if (target === 'active-organization/confirmation') return { data: { ...active, confirmed: true } }
    if (options?.method === 'PATCH') { if (failure) throw failure; current = { ...current!, number: 2 }; return { data: current } }
    if (target.includes('/choices?')) return page([{ id: itemId, version, title: '<img>Match accessible</img>', status: 'proposed' }])
    if (target.includes('/history?')) return page(current ? [current] : [])
    if (target.endsWith('/result')) return { data: { reference_id: person, approved: false, status: 'disputed', outcome: null, series: null, observed_at: sporting.occurred_at } }
    return { data: current }
  })
})
describe('suivi sportif et financier externe', () => {
  it('masque les matchs restreints, échappe la source et charge l’historique à la demande', async () => {
    const { container } = show(); await screen.findByText(sporting.payload.source, { exact: false }); expect(screen.getByText('Match à accès restreint')).toBeVisible(); expect(container.querySelector('script,img')).toBeNull()
    expect(fake.request.mock.calls.some(([p]) => p.includes('/awards') || p.includes('/history'))).toBe(false)
    await userEvent.click(screen.getByRole('button', { name: 'Historique de ce suivi' })); expect(await screen.findByRole('region', { name: 'Historique de ce suivi' })).toBeVisible()
  })
  it('ne consulte jamais les finances sans lecture explicite', () => {
    show('awards', { ...active, context: { ...active.context!, permissions: ['sports.read', 'sports.competitions.read', 'sports.competitions.write', 'financial.write'] } }); expect(fake.request).not.toHaveBeenCalled(); expect(screen.queryByRole('region')).toBeNull()
  })
  it('préserve les références opaques et ajoute uniquement un match choisi avec sa version', async () => {
    show(); await screen.findByText('Match à accès restreint'); await userEvent.click(screen.getByRole('button', { name: 'Modifier ce suivi' }))
    const form = screen.getByRole('form', { name: 'Enregistrer ce suivi' }); await within(form).findByRole('option', { name: '<img>Match accessible</img>' })
    await userEvent.selectOptions(within(form).getByLabelText('Choisir un match accessible'), itemId); await userEvent.click(within(form).getByRole('button', { name: 'Rattacher ce match' }))
    await userEvent.type(within(form).getByLabelText('Motif du changement'), 'Mise à jour sourcée du classement.'); fireEvent.submit(form)
    expect(fake.request.mock.calls.some(([, o]) => o?.method === 'PATCH')).toBe(false); await userEvent.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(screen.queryByRole('form')).toBeNull())
    const body = fake.request.mock.calls.find(([, o]) => o?.method === 'PATCH')![1].body
    expect(body).toMatchObject({ competition_version: version, expected_version: version, matches: [{ reference_id: person, match_id: null, match_version: null }, { reference_id: null, match_id: itemId, match_version: version }] }); expect(body).not.toHaveProperty('received_minor')
  })
  it('convertit exactement une réception décimale et conserve zéro et la devise', async () => {
    current = awards; show('awards'); await screen.findByText('Dotation annoncée : 1000,00 EUR'); await userEvent.click(screen.getByRole('button', { name: 'Modifier ce suivi' }))
    const form = screen.getByRole('form', { name: 'Enregistrer ce suivi' }); fireEvent.change(within(form).getByLabelText('Dotation annoncée'), { target: { value: '0' } }); fireEvent.change(within(form).getByLabelText('Montant réellement reçu'), { target: { value: '123,45' } }); fireEvent.change(within(form).getByLabelText('Date de réception constatée'), { target: { value: '2026-10-18' } }); await userEvent.type(within(form).getByLabelText('Motif du changement'), 'Réception vérifiée dans le rapport.'); fireEvent.submit(form); await userEvent.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(fake.request.mock.calls.find(([, o]) => o?.method === 'PATCH')).toBeDefined()); expect(fake.request.mock.calls.find(([, o]) => o?.method === 'PATCH')![1].body).toMatchObject({ currency: 'EUR', announced_minor: 0, received_minor: 12345, received_on: '2026-10-18' })
    expect(fake.request.mock.calls.some(([p]) => p.includes('/choices'))).toBe(false)
  })
  it('efface les montants lors du changement de devise et refuse les décimales JPY', async () => {
    current = awards; show('awards'); await screen.findByText('Dotation annoncée : 1000,00 EUR'); await userEvent.click(screen.getByRole('button', { name: 'Modifier ce suivi' })); const form = screen.getByRole('form')
    await userEvent.selectOptions(within(form).getByLabelText('Devise'), 'JPY'); expect(within(form).getByLabelText('Dotation annoncée')).toHaveValue(''); fireEvent.change(within(form).getByLabelText('Dotation annoncée'), { target: { value: '1.2' } }); fireEvent.submit(form)
    expect(await screen.findByText(/Indiquez un montant positif/)).toBeVisible(); expect(within(form).getByLabelText('Dotation annoncée')).toHaveFocus(); expect(fake.request.mock.calls.some(([, o]) => o?.method === 'PATCH')).toBe(false)
  })
  it('conserve les champs après 422 et ne rejoue pas 409', async () => {
    show(); await screen.findByText(sporting.payload.source, { exact: false }); await userEvent.click(screen.getByRole('button', { name: 'Modifier ce suivi' })); const form = screen.getByRole('form'); await userEvent.type(within(form).getByLabelText('Motif du changement'), 'Rapport vérifié par le manager.')
    failure = new ApiClientError('Date refusée.', { status: 422, fieldErrors: { observed_on: ['Vérifiez cette date.'] } }); fireEvent.submit(form); await userEvent.click(screen.getByRole('button', { name: 'Confirmer et continuer' })); expect(await screen.findByText('Vérifiez cette date.')).toBeVisible(); await waitFor(() => expect(within(form).getByLabelText('Date du constat')).toHaveFocus())
    failure = new ApiClientError('Version périmée.', { status: 409 }); fireEvent.submit(form); await userEvent.click(screen.getByRole('button', { name: 'Confirmer et continuer' })); await waitFor(() => expect(fake.invalid).toHaveBeenCalledOnce()); expect(fake.request.mock.calls.filter(([, o]) => o?.method === 'PATCH')).toHaveLength(2)
  })
  it('limite archives et lecteurs à la consultation', async () => {
    const view = show('sport', active, { ...competition, status: 'archived' }); await screen.findByText(sporting.payload.source, { exact: false }); expect(screen.queryByRole('button', { name: 'Modifier ce suivi' })).toBeNull(); view.unmount()
    show('sport', { ...active, context: { ...active.context!, permissions: ['sports.read', 'sports.competitions.read'] } }); await screen.findByText(sporting.payload.source, { exact: false }); expect(screen.queryByRole('button', { name: 'Modifier ce suivi' })).toBeNull()
  })
  it('consulte un résultat actuel à la demande et supprime les résultats non approuvés', async () => {
    current = { ...sporting, matches: [{ reference_id: person, match: { id: itemId, version, title: 'Match visible', status: 'disputed' } }] }; show(); await screen.findByText('Match visible'); expect(fake.request.mock.calls.some(([p]) => p.endsWith('/result'))).toBe(false); await userEvent.click(screen.getByRole('button', { name: 'Consulter le résultat actuel' })); expect(await screen.findByText(/Aucun résultat actuellement approuvé/)).toBeVisible()
  })
  it('ignore une réponse tardive après démontage', async () => {
    let resolve!: (v: unknown) => void; fake.request.mockImplementation(() => new Promise((r) => { resolve = r })); const view = show(); view.unmount(); resolve({ data: sporting }); await Promise.resolve(); expect(screen.queryByText(sporting.payload.source)).toBeNull()
  })
})
describe('montants exacts', () => {
  it('préserve centimes, grands montants et absence', () => { expect(awardMinor('0,29', 'EUR')).toBe(29); expect(awardMinor('10000000000', 'EUR')).toBe(1000000000000); expect(awardMinor('', 'EUR')).toBeNull(); expect(awardInput(29, 'EUR')).toBe('0,29'); expect(awardMinor('123', 'JPY')).toBe(123) })
  it.each(['-1', '1e3', '0.001', '1 000', 'Infinity', '10000000000.01'])('refuse %s', (v) => expect(() => awardMinor(v, 'EUR')).toThrow())
})
