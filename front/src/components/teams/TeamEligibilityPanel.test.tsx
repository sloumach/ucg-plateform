import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, expect, it, vi } from 'vitest'
import { tenancyRequest, type ActiveOrganization } from '../../api/tenancy'
import type { Team } from '../../api/teams'
import type { TeamLineup } from '../../api/teamLineups'
import type { EligibilityRuleset, EligibilityReport } from '../../api/teamEligibility'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { LineupEligibilityPanel, TeamEligibilityRulesPanel } from './TeamEligibilityPanel'

vi.mock('../../api/tenancy', () => ({ tenancyRequest: vi.fn() }))
const request = vi.mocked(tenancyRequest)
const id = '11111111-1111-4111-8111-111111111111', revision = '22222222-2222-4222-8222-222222222222'
const active: ActiveOrganization = { context: { organization_id: id, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: [], permissions: ['sports.read', 'teams.manage'], is_owner: false, actor_user_id: 42, membership_id: id }, revision, confirmed: false }
const team: Team = { id, game_id: id, game_key: 'valorant', game_name: 'VALORANT', game_available: true, slug: 'pilote', season: '2026', name: 'Pilote', kind: 'first', custom_category: null, short_name: null, public_description: null, color: null, status: 'active', version: revision, publication_status: 'private' }
const lineup: TeamLineup = { id, team_id: id, game_id: id, purpose: 'match', label: 'Coupe', effective_at: '2026-10-13T11:00:00+00:00', status: 'draft', version: revision, team_name: 'Pilote', game_name: 'VALORANT', game_key: 'valorant', season: '2026', created_by: 42, finalized_by: null, finalized_at: null, entries: [] }
const rule: EligibilityRuleset = { id, version: revision, game_id: id, name: '<script>RULE_BAD</script>', status: 'active', min_starters: 1, max_starters: 5, max_substitutes: 2, platform_key: 'pc', allowed_regions: ['eu'], allowed_ranks: ['Diamond'], min_age: 18, max_age: null, observation_max_days: 30, lock_at: null }
const report: EligibilityReport = { id, ruleset_id: id, ruleset_version: revision, lineup_version: revision, stage: 'preview', status: 'incomplete', evaluated_at: lineup.effective_at, actor_user_id: 42, checks: [{ code: 'profile_missing', status: 'unknown', person_id: null, message: '<script>REPORT_BAD</script>' }] }
const invalid = vi.fn(), close = vi.fn(), bound = vi.fn()
function page<T>(data: T[]) { return { data, meta: { current_page: 1, last_page: 1, total: data.length } } }
beforeEach(() => {
  vi.resetAllMocks()
  request.mockImplementation(async (path, options) => {
    if (path === 'active-organization/confirmation') return { data: { ...active, confirmed: true } }
    if (options?.method) return { data: options.method === 'PATCH' ? rule : report }
    if (path.endsWith('/games/' + id)) return { data: { effective_options: { platform: [{ key: 'pc', name: 'PC' }], region: [{ key: 'eu', name: 'Europe' }] } } }
    return path.includes('/eligibility?') ? page([report]) : page([rule])
  })
})
it('confirme la liaison avec la version exacte et recharge la composition', async () => {
  request.mockImplementation(async (path, options) => {
    if (options?.method && path.includes('ruleset-binding')) return { data: { ...lineup, ruleset_id: id, ruleset_version: revision } }
    if (options?.method) return { data: active }
    return path.includes('/eligibility?') ? page([]) : page([rule])
  })
  const user = userEvent.setup()
  render(<NotificationProvider><LineupEligibilityPanel active={active} team={team} lineup={lineup} disabled={false} onInvalid={invalid} onClose={close} onBound={bound} /></NotificationProvider>)
  await user.selectOptions(await screen.findByLabelText('Choisir un règlement actif'), id)
  await user.click(screen.getByRole('button', { name: 'Lier le règlement' }))
  expect(request.mock.calls.filter(([, options]) => options?.method)).toHaveLength(0)
  await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
  await waitFor(() => expect(bound).toHaveBeenCalledOnce())
  expect(request).toHaveBeenCalledWith(expect.stringContaining('/ruleset-binding'), expect.objectContaining({ method: 'POST', revision, body: { version: revision, ruleset_id: id, ruleset_version: revision } }))
})
it('affiche les explications échappées et ne permet aucun contrôle supplémentaire après figement', async () => {
  render(<NotificationProvider><LineupEligibilityPanel active={active} team={team} lineup={{ ...lineup, status: 'finalized', ruleset_id: id, ruleset_name: rule.name }} disabled={false} onInvalid={invalid} onClose={close} onBound={bound} /></NotificationProvider>)
  expect(await screen.findByText('Donnée manquante ou non exploitable · <script>REPORT_BAD</script>')).toBeInTheDocument()
  expect(document.querySelector('script')).toBeNull()
  expect(screen.queryByRole('button', { name: 'Enregistrer une pré-évaluation' })).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Lier le règlement' })).not.toBeInTheDocument()
})
it('enregistre un règlement complet après confirmation avec les critères explicitement saisis', async () => {
  const user = userEvent.setup()
  render(<NotificationProvider><TeamEligibilityRulesPanel active={active} team={team} disabled={false} onInvalid={invalid} onClose={close} /></NotificationProvider>)
  await screen.findByText('<script>RULE_BAD</script> · Actif')
  await user.click(screen.getByRole('button', { name: 'Créer le règlement' }))
  await user.type(screen.getByLabelText('Nom du contexte de compétition'), 'Coupe 2027')
  await user.selectOptions(screen.getByLabelText('Plateforme du profil contrôlé'), 'pc')
  await user.click(screen.getByRole('checkbox', { name: 'Europe' }))
  await user.type(screen.getByLabelText('Rangs déclarés autorisés, un par ligne (vide : tous)'), 'Diamond\nImmortal')
  await user.type(screen.getByLabelText('Âge minimum (facultatif)'), '18')
  await user.click(within(screen.getByRole('form', { name: 'Créer le règlement' })).getByRole('button', { name: 'Créer le règlement' }))
  expect(request.mock.calls.filter(([, options]) => options?.method)).toHaveLength(0)
  await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
  await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/' + id + '/eligibility-rulesets', expect.objectContaining({ method: 'POST', body: {
    game_id: id, name: 'Coupe 2027', min_starters: 1, max_starters: 5, max_substitutes: 2, min_age: 18, max_age: null,
    platform_key: 'pc', allowed_regions: ['eu'], allowed_ranks: ['Diamond', 'Immortal'], observation_max_days: 30, lock_at: null,
  } })))
})
