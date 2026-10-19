import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, within, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ActiveOrganization } from '../../api/tenancy'
import type { MetricDefinition } from '../../api/metricCatalogue'
import type { StatisticalObservation } from '../../api/observations'
import { ApiClientError } from '../../api/errors'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { ObservationPanel } from './ObservationPanel'
const fake = vi.hoisted(() => ({ request: vi.fn(), invalid: vi.fn() }))
vi.mock('../../api/tenancy', async (original) => ({ ...await original<typeof import('../../api/tenancy')>(), tenancyRequest: fake.request }))
const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222', version = '33333333-3333-4333-8333-333333333333', person = '44444444-4444-4444-8444-444444444444'
const active: ActiveOrganization = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'], permissions: ['sports.read', 'sports.write', 'sports.statistics.read', 'sports.statistics.write', 'sports.statistics.correct'], actor_user_id: 42, is_owner: false, membership_id: id }, revision: version, confirmed: false }
const metric: MetricDefinition = { id, game_id: id, code: 'VALUE', version, number: 1, mode_id: null, role_id: null, release_id: null, scope: { game_id: id, game_key: 'pilot', game_name: 'Jeu pilote', mode_id: null, mode_name: null, role_id: null, role_name: null, release_id: null, release_name: null }, title: '<img>Métrique</img>', definition: 'Définition', unit: 'points', aggregation: 'manual', aggregation_level: 'period', formula: '<script>source</script>', zero_denominator: 'not_applicable', measurement_kind: 'objective', availability: 'manual', availability_note: 'Saisie vérifiée', status: 'active', reason: 'Définition contrôlée', occurred_at: '2026-10-19T10:00:00Z' }
const observation: StatisticalObservation = { id, version, number: 1, metric_id: id, metric_version: version, game_id: id, person_id: person, person_label: 'Joueur pilote', match_id: null, match_game_id: null, source_kind: 'training', acquired_via: 'manual', origin_reference: null, source_label: '<script>Grille privée</script>', period_start: '2026-10-01', period_end: '2026-10-18', observed_on: '2026-10-19', sample_size: 3, context: { metric, game_context: metric.scope, region: null, season: null, map: null, opponent: null, round_number: null, session_label: null }, availability: 'available', value: '0', missing_reason: null, notes: 'Note conservée', status: 'active', reason: 'Source vérifiée avant saisie.', occurred_at: '2026-10-19T10:00:00Z' }
function page<T>(data: T[]) { return { data, meta: { current_page: 1, last_page: 1, total: data.length } } }
let records: StatisticalObservation[], failure: ApiClientError | null
function show(selection = active) { return render(<NotificationProvider><ObservationPanel active={selection} disabled={false} onInvalid={fake.invalid} /></NotificationProvider>) }
beforeEach(() => {
  vi.resetAllMocks(); records = [observation]; failure = null
  fake.request.mockImplementation(async (target, options) => {
    if (target === 'active-organization/confirmation') return { data: { ...active, confirmed: true } }
    if (options?.method === 'PATCH' || options?.method === 'POST') { if (failure) throw failure; return { data: { ...observation, ...options.body, number: 2 } } }
    if (target.startsWith('metric-catalogue?')) return page([metric])
    if (target.startsWith('game-catalogue?')) return page([])
    if (target.includes('/choices?kind=people')) return page([{ id: person, label: 'Joueur pilote' }])
    if (target.includes('/choices?')) return page([])
    return page(records)
  })
})
async function open() { await userEvent.click(screen.getByRole('button', { name: 'Ouvrir les observations' })); await screen.findByText(observation.source_label, { exact: false }) }
async function correction() { await open(); await userEvent.click(screen.getByRole('button', { name: 'Corriger cette observation' })); return screen.getByRole('form', { name: 'Corriger cette observation' }) }
async function submit(form: HTMLElement) { await userEvent.type(within(form).getByLabelText('Motif de saisie ou correction'), 'Correction vérifiée sur la source.'); await userEvent.click(within(form).getByRole('button', { name: 'Enregistrer cette observation' })) }
describe('observations privées', () => {
  it('charge à la demande, affiche zéro et échappe les contenus de source et formule', async () => {
    const { container } = show(); expect(fake.request).not.toHaveBeenCalled(); await open(); expect(screen.getByText('Valeur : 0', { exact: false })).toBeVisible(); expect(screen.getByText('Période', { exact: false })).toBeVisible(); expect(container.querySelector('script,img,iframe')).toBeNull(); expect(fake.request.mock.calls.some(([p]) => p.includes('/history'))).toBe(false)
    await userEvent.click(screen.getByRole('button', { name: 'Historique des observations' })); expect(await screen.findByRole('region', { name: 'Historique des observations' })).toBeVisible()
  })
  it('reste masqué sans lecture explicite et interdit la création ou correction au lecteur', async () => {
    const view = show({ ...active, context: { ...active.context!, permissions: ['sports.read'] } }); expect(fake.request).not.toHaveBeenCalled(); view.unmount()
    show({ ...active, context: { ...active.context!, permissions: ['sports.read', 'sports.statistics.read'] } }); await open(); expect(screen.queryByRole('button', { name: 'Saisir une observation' })).toBeNull(); expect(screen.queryByRole('button', { name: 'Corriger cette observation' })).toBeNull()
  })
  it('confirme le tenant et transmet une correction sans réécrire la source ni la métrique', async () => {
    show(); const form = await correction(); await userEvent.clear(within(form).getByLabelText('Valeur')); await userEvent.type(within(form).getByLabelText('Valeur'), '12.125'); await submit(form)
    expect(fake.request.mock.calls.some(([, o]) => o?.method === 'PATCH')).toBe(false); await userEvent.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(fake.request.mock.calls.some(([, o]) => o?.method === 'PATCH')).toBe(true))
    const [path, options] = fake.request.mock.calls.find(([, o]) => o?.method === 'PATCH')!; expect(path).toBe('tenants/' + org + '/observations/' + id); expect(options.revision).toBe(version); expect(options.body).toMatchObject({ version, value: '12.125', availability: 'available', missing_reason: null }); expect(options.body.source_label).toBeUndefined(); expect(options.body.metric_version).toBeUndefined(); expect(fake.request.mock.calls.some(([p]) => p === 'active-organization/confirmation')).toBe(true)
  })
  it('transmet une indisponibilité explicite avec valeur nulle', async () => {
    show(); const form = await correction(); await userEvent.selectOptions(within(form).getByLabelText('Disponibilité de la valeur'), 'unavailable'); await userEvent.type(within(form).getByLabelText('Motif d’indisponibilité'), 'Aucune mesure fiable'); await submit(form); await userEvent.click(screen.getByRole('button', { name: 'Confirmer et continuer' })); await waitFor(() => expect(fake.request.mock.calls.find(([, o]) => o?.method === 'PATCH')?.[1].body).toMatchObject({ availability: 'unavailable', value: null, missing_reason: 'Aucune mesure fiable' }))
  })
  it('garde la saisie après 422 et ne rejoue pas une correction périmée', async () => {
    show(); const form = await correction(); failure = new ApiClientError('Valeur incorrecte', { status: 422, fieldErrors: { value: ['Valeur incorrecte'] } }); await submit(form); await userEvent.click(screen.getByRole('button', { name: 'Confirmer et continuer' })); await waitFor(() => expect(within(form).getByLabelText('Valeur')).toHaveFocus()); expect(within(form).getByLabelText('Motif de saisie ou correction')).toHaveValue('Correction vérifiée sur la source.'); expect(fake.invalid).not.toHaveBeenCalled()
    failure = new ApiClientError('Version périmée', { status: 409, fieldErrors: {} }); fireEvent.submit(form); await userEvent.click(screen.getByRole('button', { name: 'Confirmer et continuer' })); await waitFor(() => expect(fake.invalid).toHaveBeenCalledTimes(1)); expect(fake.request.mock.calls.filter(([, o]) => o?.method === 'PATCH')).toHaveLength(2)
  })
  it('crée avec une version précise et conserve la chaîne décimale', async () => {
    records = []; show(); await userEvent.click(screen.getByRole('button', { name: 'Ouvrir les observations' })); await screen.findByText('Aucune observation pour ces filtres.'); await userEvent.click(screen.getByRole('button', { name: 'Saisir une observation' })); const form = screen.getByRole('form', { name: 'Saisir une observation' }); await screen.findByRole('option', { name: 'Jeu pilote · VALUE · <img>Métrique</img>' }); await userEvent.selectOptions(within(form).getByLabelText('Métrique et version'), id); await userEvent.selectOptions(within(form).getByLabelText('Joueur'), person)
    for (const [label, value] of [['Source et référence documentaire', 'Grille coach'], ['Valeur', '0.000001']]) await userEvent.type(within(form).getByLabelText(label), value)
    for (const label of ['Début de période', 'Fin de période', 'Date d’observation de la source']) fireEvent.change(within(form).getByLabelText(label), { target: { value: '2026-10-19' } })
    await submit(form); await userEvent.click(screen.getByRole('button', { name: 'Confirmer et continuer' })); await waitFor(() => expect(fake.request.mock.calls.find(([p, o]) => p.endsWith('/observations') && o?.method === 'POST')?.[1].body).toMatchObject({ metric_id: id, metric_version: version, person_id: person, value: '0.000001', sample_size: 1, mode_id: null, role_id: null, release_id: null, status: 'active' }))
  })
})
