import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import type { ActiveOrganization } from '../../api/tenancy'
import type { MatchRecord, MatchScorecard } from '../../api/matches'
import { ApiClientError } from '../../api/errors'
import { MatchLiveScores } from './MatchLiveScores'
const fake = vi.hoisted(() => ({ request: vi.fn(), invalid: vi.fn(), refresh: 0 }))
vi.mock('../../api/tenancy', async (original) => ({ ...await original<typeof import('../../api/tenancy')>(), tenancyRequest: fake.request }))
vi.mock('../../realtime/useMatchRealtime', () => ({ useMatchRealtime: () => ({ refresh: fake.refresh, changedAt: null, connected: false }) }))
const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222', version = '33333333-3333-4333-8333-333333333333'
const a = '44444444-4444-4444-8444-444444444444', b = '55555555-5555-4555-8555-555555555555'
const active: ActiveOrganization = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'],
  permissions: ['sports.read', 'sports.write'], actor_user_id: 42, is_owner: false, membership_id: id }, revision: version, confirmed: true }
const record: MatchRecord = { id, version: id, title: 'Scrim', kind: 'scrim', format: 'bo3', game_id: id, game_key: 'pilot', game_name: 'Jeu pilote',
  status: 'in_progress', visibility: 'private', starts_at: '2026-10-19T10:00:00Z', ends_at: '2026-10-19T12:00:00Z', origin_timezone: 'UTC',
  starts_at_origin: '2026-10-19T10:00:00+00:00', ends_at_origin: '2026-10-19T12:00:00+00:00', responsible_person_id: a, responsible_label: 'Alex', editable: false, available_transitions: ['awaiting_validation'],
  participants: [{ id: a, kind: 'person', source_id: a, reference_version: version, slot: 1, label: 'Alex' }, { id: b, kind: 'person', source_id: b, reference_version: version, slot: 2, label: 'Robin' }] }
const card: MatchScorecard = { match_version: version, rules: { format: 'bo3', game_limit: 3, allow_draw: false }, series: { complete: false, played: 1, draw: false, winner_ids: [], standings: [{ participant_id: a, score: 1 }, { participant_id: b, score: 0 }] } }

const root = 'tenants/' + org + '/matches'
function snapshot(pending = false) { return { data: { ...card, recalculation_pending: pending, series: pending ? null : card.series, status: pending ? 'disputed' : 'validated', outcome: null, winner_ids: [], observed_at: '2026-10-18T10:00:00Z' } } }
function view() { return <MatchLiveScores active={active} item={record} root={root} onInvalid={fake.invalid} onClose={() => {}} /> }
beforeEach(() => { vi.resetAllMocks(); fake.refresh = 0; fake.request.mockResolvedValue(snapshot()) })
describe('vue des scores en direct', () => {
  it('relit uniquement la projection autorisée et distingue le résultat validé', async () => {
    const rendered = render(view())
    await screen.findByText('Alex : 1')
    expect(screen.getByText(/Résultat validé/)).toBeInTheDocument()
    expect(fake.request).toHaveBeenCalledWith(root + '/' + id + '/live', expect.objectContaining({ revision: version }))
    expect(rendered.container.querySelector('form')).toBeNull()
  })
  it('retire les anciens totaux quand une correction est en attente', async () => {
    const rendered = render(view()); await screen.findByText('Alex : 1')
    fake.request.mockResolvedValue(snapshot(true)); fake.refresh++
    rendered.rerender(view())
    await screen.findByText(/Recalcul en attente/)
    expect(screen.queryByText('Alex : 1')).toBeNull()
    expect(screen.getByText(/Scores provisoires/)).toBeInTheDocument()
  })
  it('efface les scores et redemande le contexte après révocation des droits', async () => {
    const rendered = render(view()); await screen.findByText('Alex : 1')
    fake.request.mockRejectedValue(new ApiClientError('Accès refusé.', { status: 403 }))
    fake.refresh++; rendered.rerender(view())
    await waitFor(() => expect(fake.invalid).toHaveBeenCalledOnce())
    expect(screen.queryByText('Alex : 1')).toBeNull()
    expect(screen.getByText(/Lecture interrompue/)).toBeInTheDocument()
  })
  it('échappe les libellés et annule la lecture lors de la fermeture', async () => {
    const rendered = render(<MatchLiveScores active={active} item={{ ...record, title: '<script>privé</script>' }} root={root} onInvalid={fake.invalid} onClose={() => {}} />)
    await screen.findByText('Alex : 1')
    expect(rendered.container.querySelector('script')).toBeNull()
    const signal = fake.request.mock.calls[0][1].signal as AbortSignal
    rendered.unmount(); expect(signal.aborted).toBe(true)
  })
})
