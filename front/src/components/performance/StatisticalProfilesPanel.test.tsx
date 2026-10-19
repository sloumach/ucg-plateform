import { beforeEach, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, within, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ActiveOrganization } from '../../api/tenancy'
import type { MetricDefinition } from '../../api/metricCatalogue'
import type { StatisticalCalculationResult } from '../../api/statisticalCalculations'
import type { ProfileGroup, StatisticalProfileResponse } from '../../api/statisticalProfiles'
import { ApiClientError } from '../../api/errors'
import { StatisticalProfilesPanel } from './StatisticalProfilesPanel'

const fake = vi.hoisted(() => ({ request: vi.fn(), invalid: vi.fn() }))
vi.mock('../../api/tenancy', async (original) => ({ ...await original<typeof import('../../api/tenancy')>(), tenancyRequest: fake.request }))
const org = '11111111-1111-4111-8111-111111111111', metricId = '22222222-2222-4222-8222-222222222222', version = '33333333-3333-4333-8333-333333333333', person = '44444444-4444-4444-8444-444444444444', second = '55555555-5555-4555-8555-555555555555'
const active: ActiveOrganization = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'], permissions: ['sports.read', 'sports.statistics.read'], actor_user_id: 42, is_owner: false, membership_id: metricId }, revision: version, confirmed: false }
const metric: MetricDefinition = { id: metricId, game_id: metricId, code: 'POINTS', version, number: 1, mode_id: null, role_id: null, release_id: null, scope: { game_id: metricId, game_key: 'pilot', game_name: 'Jeu pilote', mode_id: null, mode_name: null, role_id: null, role_name: null, release_id: null, release_name: null }, title: '<img>Points</img>', definition: 'Définition', unit: 'points', aggregation: 'sum', aggregation_level: 'period', formula: 'Somme documentée', zero_denominator: 'not_applicable', measurement_kind: 'objective', availability: 'manual', availability_note: 'Saisie vérifiée', status: 'active', reason: 'Définition contrôlée', occurred_at: '2026-10-19T10:00:00Z' }
const result: StatisticalCalculationResult = { engine_version: 'stat-004.v1', precision: 6, rounding: 'half_up', availability: 'available', value: '0', sample_size: 3, source_count: 1, included_count: 1, missing_count: 0, voided_count: 0, incomplete: false, numerator: null, denominator: null, reason: null }
const group: ProfileGroup = { key: 'context', context: { source_kind: 'training', source_label: '<script>Source privée</script>', season: '2026', map: null, release_id: version }, context_labels: { release_id: 'Patch pilote' }, result, issue: null, source_count: 1, period_start: '2026-10-01', period_end: '2026-10-01', period_count: 1, coverage_version: 'periods', latest_observed_on: '2026-10-19', last_updated_at: '2026-10-19T10:00:00Z' }
const page = <T,>(data: T[]) => ({ data, meta: { current_page: 1, last_page: 1, per_page: 10, total: data.length } })
const base: StatisticalProfileResponse = {
  metric, filters: { from: '2026-10-01', to: '2026-10-18' }, generated_at: '2026-10-19T10:00:00Z', engine_version: 'stat-004.v1', live: true,
  profiles: [{ person_id: person, person_label: '<img>Joueur</img>', source_count: 1, latest_observed_on: '2026-10-19', last_updated_at: group.last_updated_at, groups: page([group]), timeline: page([]) }], comparison: null,
}
const root = 'tenants/' + org + '/statistical-profiles'
let response: StatisticalProfileResponse, failure: ApiClientError | null, definitions: MetricDefinition[]
beforeEach(() => {
  vi.resetAllMocks(); response = structuredClone(base); failure = null; definitions = [metric]
  fake.request.mockImplementation(async (path: string) => {
    if (path.startsWith(root + '/people')) return page([{ id: person, label: '<img>Joueur</img>' }, { id: second, label: 'Second joueur' }])
    if (path.includes('/history?')) return page(definitions)
    if (path.startsWith('metric-catalogue')) return page([metric])
    if (path.startsWith('game-catalogue')) return page([])
    if (failure) throw failure
    return { data: response }
  })
})
function show(selection = active) { return render(<StatisticalProfilesPanel active={selection} disabled={false} onInvalid={fake.invalid} />) }
async function filters(compare = false) {
  await userEvent.click(screen.getByRole('button', { name: 'Ouvrir les profils statistiques' }))
  const form = screen.getByRole('form', { name: 'Filtres des profils statistiques' })
  await within(form).findByRole('option', { name: 'Jeu pilote · POINTS' })
  await userEvent.selectOptions(within(form).getByLabelText('Jeu et métrique'), metricId)
  await userEvent.selectOptions(within(form).getByLabelText('Premier joueur'), person)
  if (compare) await userEvent.selectOptions(within(form).getByLabelText('Second joueur à comparer'), second)
  fireEvent.change(within(form).getByLabelText('Début de la fenêtre'), { target: { value: '2026-10-01' } })
  fireEvent.change(within(form).getByLabelText('Fin de la fenêtre'), { target: { value: '2026-10-18' } })
  return form
}
async function consult() { fireEvent.submit(await filters()); return screen.findByRole('region', { name: 'Résultats des profils statistiques' }) }
it('is lazy and requires explicit statistics reading permissions', async () => {
  const view = show(); expect(fake.request).not.toHaveBeenCalled(); view.unmount()
  show({ ...active, context: { ...active.context!, permissions: ['sports.read'] } }); expect(screen.queryByRole('button', { name: 'Ouvrir les profils statistiques' })).toBeNull(); expect(fake.request).not.toHaveBeenCalled()
})
it('consults as a reader with exact filters and versions without tenant confirmation or a mutation', async () => {
  show(); const form = await filters(true)
  fireEvent.change(within(form).getByLabelText('Saison'), { target: { value: '2026' } })
  fireEvent.change(within(form).getByLabelText('Source exacte'), { target: { value: '<script>Source privée</script>' } })
  fireEvent.submit(form); await screen.findByRole('region', { name: 'Résultats des profils statistiques' })
  const [path, options] = fake.request.mock.calls.find(([path]) => path.startsWith(root + '?'))!
  const params = new URLSearchParams(path.split('?')[1])
  expect(params.getAll('people[]')).toEqual([person, second]); expect(params.get('metric_version')).toBe(version); expect(params.get('season')).toBe('2026'); expect(params.get('source_label')).toBe('<script>Source privée</script>')
  expect(options.revision).toBe(version); expect(fake.request.mock.calls.some(([, options]) => options?.method)).toBe(false)
})
it('renders zero, source, sample and update dates and escapes private content', async () => {
  const view = show(); const results = await consult()
  expect(within(results).getByText('Résultat : 0')).toBeVisible(); expect(within(results).getByText('Patch pilote')).toBeVisible()
  expect(within(results).getByText(/Taille d’échantillon : 3/)).toBeVisible(); expect(within(results).getAllByText(/Dernière mise à jour/).length).toBeGreaterThan(0)
  expect(view.container.querySelector('script,img,iframe')).toBeNull()
})
it('can consult a historical definition without silently selecting the current version', async () => {
  definitions = [{ ...metric, version: second, number: 2 }, metric]
  show(); const form = await filters()
  await waitFor(() => expect(within(form).getByLabelText('Version de la définition')).not.toBeDisabled())
  await userEvent.selectOptions(within(form).getByLabelText('Version de la définition'), second)
  fireEvent.submit(form); await screen.findByRole('region', { name: 'Résultats des profils statistiques' })
  const [path] = fake.request.mock.calls.find(([path]) => path.startsWith(root + '?'))!
  expect(new URLSearchParams(path.split('?')[1]).get('metric_version')).toBe(second)
})
it('shows the compatible difference with a warning about unequal samples', async () => {
  response.profiles.push({ ...response.profiles[0], person_id: second, person_label: 'Second joueur' })
  response.comparison = page([{ key: 'context', context: group.context, context_labels: group.context_labels, left: group, right: { ...group, result: { ...result, sample_size: 9 } }, comparable: true, reason: null, difference: '-2', unequal_samples: true }])
  show(); fireEvent.submit(await filters(true))
  const region = await screen.findByRole('region', { name: 'Comparaison contextualisée' })
  expect(within(region).getByText(/Écart.*-2/)).toBeVisible(); expect(within(region).getByText(/Tailles d’échantillon différentes/)).toBeVisible()
})
it('never displays a difference for incompatible periods even if a payload contains a stale difference', async () => {
  response.profiles.push({ ...response.profiles[0], person_id: second, person_label: 'Second joueur' })
  response.comparison = page([{ key: 'context', context: group.context, context_labels: group.context_labels, left: group, right: group, comparable: false, reason: 'PERIOD_MISMATCH', difference: '99999', unequal_samples: false }])
  show(); fireEvent.submit(await filters(true))
  const region = await screen.findByRole('region', { name: 'Comparaison contextualisée' })
  expect(within(region).getByText(/les périodes couvertes diffèrent/)).toBeVisible(); expect(within(region).queryByText(/99999/)).toBeNull()
})
it('hides the previous result when a filter changes and refreshes only on explicit consultation', async () => {
  show(); await consult(); const previous = fake.request.mock.calls.filter(([path]) => path.startsWith(root + '?')).length
  fireEvent.change(screen.getByLabelText('Saison'), { target: { value: '2025' } })
  expect(screen.queryByRole('region', { name: 'Résultats des profils statistiques' })).toBeNull(); expect(fake.request.mock.calls.filter(([path]) => path.startsWith(root + '?'))).toHaveLength(previous)
  fireEvent.submit(screen.getByRole('form')); await screen.findByRole('region', { name: 'Résultats des profils statistiques' })
  await userEvent.click(screen.getByRole('button', { name: 'Actualiser ces profils' })); await waitFor(() => expect(fake.request.mock.calls.filter(([path]) => path.startsWith(root + '?'))).toHaveLength(previous + 2))
})
it('keeps the form and does not replay an oversized selection rejected with 409', async () => {
  failure = new ApiClientError('Réduisez la période.', { status: 409, code: 'STAT_PROFILE_LIMIT' })
  show(); fireEvent.submit(await filters()); expect(await screen.findByText('Réduisez la période.')).toBeVisible()
  expect(fake.invalid).not.toHaveBeenCalled(); expect(fake.request.mock.calls.filter(([path]) => path.startsWith(root + '?'))).toHaveLength(1); expect(screen.getByLabelText('Début de la fenêtre')).toHaveValue('2026-10-01')
})
it('invalidates revoked rights and resets all private results on a tenant switch', async () => {
  const view = show(); await consult(); failure = new ApiClientError('Accès retiré.', { status: 403 })
  await userEvent.click(screen.getByRole('button', { name: 'Actualiser ces profils' })); await waitFor(() => expect(fake.invalid).toHaveBeenCalledOnce())
  expect(screen.queryByRole('region', { name: 'Résultats des profils statistiques' })).toBeNull()
  view.rerender(<StatisticalProfilesPanel active={{ ...active, context: { ...active.context!, organization_id: second } }} disabled={false} onInvalid={fake.invalid} />)
  expect(screen.getByRole('button', { name: 'Ouvrir les profils statistiques' })).toBeVisible(); expect(screen.queryByRole('form')).toBeNull()
})
