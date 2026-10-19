import { beforeEach, expect, it, vi } from 'vitest'
import { render, screen, within, waitFor, fireEvent } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ActiveOrganization } from '../../api/tenancy'
import type { MetricDefinition } from '../../api/metricCatalogue'
import type { StatisticalObservation } from '../../api/observations'
import type { StatisticalCalculation, StatisticalCalculationResult } from '../../api/statisticalCalculations'
import { ApiClientError } from '../../api/errors'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { StatisticalCalculationsPanel } from './StatisticalCalculationsPanel'
const fake = vi.hoisted(() => ({ request: vi.fn(), invalid: vi.fn(), clear: vi.fn() }))
vi.mock('../../api/tenancy', async (original) => ({ ...await original<typeof import('../../api/tenancy')>(), tenancyRequest: fake.request }))
const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222', version = '33333333-3333-4333-8333-333333333333', person = '44444444-4444-4444-8444-444444444444'
const active: ActiveOrganization = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'], permissions: ['sports.read', 'sports.write', 'sports.statistics.read', 'sports.statistics.write'], actor_user_id: 42, is_owner: false, membership_id: id }, revision: version, confirmed: false }
const metric: MetricDefinition = { id, game_id: id, code: 'POINTS', version, number: 1, mode_id: null, role_id: null, release_id: null, scope: { game_id: id, game_key: 'pilot', game_name: 'Jeu pilote', mode_id: null, mode_name: null, role_id: null, role_name: null, release_id: null, release_name: null }, title: '<img>Points</img>', definition: 'Définition', unit: 'points', aggregation: 'sum', aggregation_level: 'period', formula: '<script>source</script>', zero_denominator: 'not_applicable', measurement_kind: 'objective', availability: 'manual', availability_note: 'Saisie vérifiée', status: 'active', reason: 'Définition contrôlée', occurred_at: '2026-10-19T10:00:00Z' }
const observation: StatisticalObservation = { id, version, number: 1, metric_id: id, metric_version: version, game_id: id, person_id: person, person_label: 'Joueur pilote', match_id: null, match_game_id: null, source_kind: 'training', acquired_via: 'manual', origin_reference: null, source_label: '<script>Source privée</script>', period_start: '2026-10-01', period_end: '2026-10-01', observed_on: '2026-10-19', sample_size: 3, context: { metric, game_context: metric.scope, region: null, season: null, map: null, opponent: null, round_number: null, session_label: null }, availability: 'available', value: '0', missing_reason: null, notes: null, status: 'active', reason: 'Source vérifiée.', occurred_at: '2026-10-19T10:00:00Z' }
const result: StatisticalCalculationResult = { engine_version: 'stat-004.v1', precision: 6, rounding: 'half_up', availability: 'available', value: '0', sample_size: 3, source_count: 1, included_count: 1, missing_count: 0, voided_count: 0, incomplete: false, numerator: null, denominator: null, reason: null }
const base: StatisticalCalculation = { id, version, metric, status: 'completed', source_count: 1, cohort: { person_id: person, source_kind: 'training', source_label: '<script>Source privée</script>' }, failure_code: null, result, result_version: version, number: 1, can_recalculate: true, sources: { data: [id], meta: { current_page: 1, last_page: 1, per_page: 10, total: 1 } } }
const root = 'tenants/' + org + '/statistical-calculations'
let row: StatisticalCalculation, failure: ApiClientError | null
beforeEach(() => {
  vi.resetAllMocks(); row = { ...base }; failure = null
  fake.request.mockImplementation(async (path: string, options?: { method?: string }) => {
    if (path === 'active-organization/confirmation') return { data: { ...active, confirmed: true } }
    if (options?.method === 'POST') { if (failure) throw failure; return { data: row } }
    if (path.includes('/history')) return { data: [{ id, number: 1, result, engine_version: 'stat-004.v1', created_at: '2026-10-19T10:00:00Z' }], meta: { current_page: 1, last_page: 1, total: 1 } }
    if (path.startsWith(root + '/')) return { data: row }
    return { data: path.startsWith('metric-catalogue') ? [metric] : [row], meta: { current_page: 1, last_page: 1, total: 1 } }
  })
})
function show(selection = active, selected = [observation]) { return render(<NotificationProvider><StatisticalCalculationsPanel active={selection} selected={selected} disabled={false} onInvalid={fake.invalid} onClear={fake.clear} /></NotificationProvider>) }
async function open() { await userEvent.click(screen.getByRole('button', { name: 'Ouvrir les calculs statistiques' })); await screen.findByRole('button', { name: 'Voir ce calcul · 22222222' }) }
async function view() { await open(); await userEvent.click(screen.getByRole('button', { name: 'Voir ce calcul · 22222222' })); return screen.findByRole('region', { name: 'Détail du calcul statistique' }) }
it('loads only on demand and stays hidden without explicit reading rights', async () => {
  const rendered = show(); expect(fake.request).not.toHaveBeenCalled(); rendered.unmount()
  show({ ...active, context: { ...active.context!, permissions: ['sports.read'] } }); expect(screen.queryByRole('button', { name: 'Ouvrir les calculs statistiques' })).toBeNull(); expect(fake.request).not.toHaveBeenCalled()
})
it('confirms the tenant and submits only selected ids and exact target version', async () => {
  show(); await open(); const form = screen.getByRole('form', { name: 'Demander le calcul de cet échantillon' })
  await userEvent.selectOptions(within(form).getByLabelText('Métrique cible et version'), id)
  fireEvent.submit(form); expect(fake.request.mock.calls.some(([, options]) => options?.method === 'POST')).toBe(false)
  await userEvent.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
  await waitFor(() => expect(fake.request).toHaveBeenCalledWith(root, expect.objectContaining({ method: 'POST', revision: version, body: { metric_id: id, metric_version: version, observations: [id], confirm: true } })))
  expect(fake.request.mock.calls.filter(([, options]) => options?.method === 'POST').map(([path]) => path)).toEqual(['active-organization/confirmation', root])
})
it('preserves zero as a value and escapes private text', async () => {
  const rendered = show(); const detail = await view(); expect(await within(detail).findByText('Résultat : 0')).toBeVisible(); expect(rendered.container.querySelector('script,img,iframe')).toBeNull()
})
it('does not present a stale result as current but allows explicit historical inspection', async () => {
  row = { ...base, status: 'stale', result: null }
  show(); const detail = await view(); expect(await within(detail).findByText(/Aucun résultat actuel validé/)).toBeVisible(); expect(within(detail).queryByText('Résultat : 0')).toBeNull()
  await userEvent.click(within(detail).getByRole('button', { name: 'Historique des calculs' })); const history = await within(detail).findByRole('region', { name: 'Historique des calculs' }); expect(await within(history).findByText('Résultat : 0')).toBeVisible()
})
it('does not replay a stale recalculation rejected with 409', async () => {
  failure = new ApiClientError('Calcul périmé.', { status: 409 }); show({ ...active, confirmed: true }); const detail = await view()
  await userEvent.click(await within(detail).findByRole('button', { name: 'Confirmer un recalcul' })); await userEvent.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
  await waitFor(() => expect(fake.invalid).toHaveBeenCalledOnce()); expect(fake.request.mock.calls.filter(([, options]) => options?.method === 'POST')).toHaveLength(1)
})
it('prevents writes for readers and creation without any selected observations', async () => {
  const rendered = show({ ...active, context: { ...active.context!, permissions: ['sports.read', 'sports.statistics.read'] } }); const detail = await view(); expect(within(detail).queryByRole('button', { name: 'Confirmer un recalcul' })).toBeNull(); expect(screen.queryByRole('form', { name: 'Demander le calcul de cet échantillon' })).toBeNull(); rendered.unmount()
  show(active, []); await open(); expect(screen.getByRole('button', { name: 'Demander le calcul de cet échantillon' })).toBeDisabled()
})
