import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ActiveOrganization } from '../../api/tenancy'
import type { MatchRecord, MatchNotebookEntry, NotebookAudience } from '../../api/matches'
import { ApiClientError } from '../../api/errors'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { MatchNotebook } from './MatchNotebook'

const { request, invalid } = vi.hoisted(() => ({ request: vi.fn(), invalid: vi.fn() }))
vi.mock('../../api/tenancy', async (original) => ({ ...await original<typeof import('../../api/tenancy')>(), tenancyRequest: request }))
const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222', version = '33333333-3333-4333-8333-333333333333'
const a = '44444444-4444-4444-8444-444444444444', b = '55555555-5555-4555-8555-555555555555'
const active: ActiveOrganization = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'],
  permissions: ['sports.read', 'sports.write'], actor_user_id: 42, is_owner: false, membership_id: id }, revision: version, confirmed: false }
const record: MatchRecord = { id, version: id, title: 'Scrim', kind: 'scrim', format: 'bo3', game_id: id, game_key: 'pilot', game_name: 'Jeu pilote',
  status: 'in_progress', visibility: 'private', starts_at: '2026-10-19T10:00:00Z', ends_at: '2026-10-19T12:00:00Z', origin_timezone: 'UTC',
  starts_at_origin: '2026-10-19T10:00:00+00:00', ends_at_origin: '2026-10-19T12:00:00+00:00', responsible_person_id: a, responsible_label: 'Alex', editable: false, available_transitions: ['awaiting_validation'],
  participants: [{ id: a, kind: 'person', source_id: a, reference_version: version, slot: 1, label: 'Alex' }, { id: b, kind: 'person', source_id: b, reference_version: version, slot: 2, label: 'Robin' }] }

const root = 'tenants/' + org + '/matches', path = root + '/' + id + '/notebook'
const entry: MatchNotebookEntry = { entry_id: a, version, number: 1, kind: 'note', audience: 'internal', title: 'Note confidentielle', body: '<script>Texte protégé</script>',
  vod_url: null, timestamp_seconds: null, state: null, due_at: null, actor_user_id: 42, occurred_at: '2026-10-19T11:00:00Z' }
const selection = { ...active, context: { ...active.context!, permissions: ['sports.read', 'sports.write', 'sports.notes.read', 'sports.notes.write', 'sports.strategies.read', 'sports.strategies.write', 'sports.publish'] } }
function page(data = [entry], writable: NotebookAudience[] = ['internal', 'strategy', 'shared'], n = 1) { return { data, writable_audiences: writable, meta: { current_page: n, last_page: 2, total: 2 } } }
function show() { return render(<NotificationProvider><MatchNotebook active={selection} item={record} root={root} management={false} disabled={false} onInvalid={invalid} onClose={() => {}} /></NotificationProvider>) }
beforeEach(() => {
  vi.resetAllMocks()
  request.mockImplementation(async (target, options) => {
    if (target === 'active-organization/confirmation') return { data: { ...selection, confirmed: true } }
    if (options?.method) return { data: entry }
    return page([entry], ['internal', 'strategy', 'shared'], target.includes('page=2') ? 2 : 1)
  })
})
describe('préparation, VOD et débrief', () => {
  it('échappe les notes et respecte les espaces modifiables du serveur', async () => {
    request.mockResolvedValue(page([entry], []))
    const view = show(); await screen.findByText(entry.body)
    expect(view.container.querySelector('script,img,iframe,a')).toBeNull()
    expect(screen.queryByRole('button', { name: 'Ajouter un contenu' })).toBeNull()
    expect(screen.queryByRole('button', { name: 'Modifier ce contenu' })).toBeNull()
    expect(request.mock.calls.some(([, options]) => options?.method)).toBe(false)
  })
  it('crée une note interne par défaut après confirmation du tenant', async () => {
    show(); const user = userEvent.setup(); await user.click(await screen.findByRole('button', { name: 'Ajouter un contenu' }))
    const form = screen.getByRole('form', { name: 'Enregistrer le contenu' })
    expect(within(form).getByLabelText('Espace du contenu')).toHaveValue('internal')
    await user.type(within(form).getByLabelText('Titre du contenu'), 'Objectif collectif')
    await user.type(within(form).getByLabelText('Notes ou annotation'), 'Améliorer la communication.')
    fireEvent.submit(form); expect(request.mock.calls.some(([, options]) => options?.method)).toBe(false)
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    expect(request).toHaveBeenCalledWith(path, expect.objectContaining({ method: 'POST', body: { kind: 'note', audience: 'internal', title: 'Objectif collectif', body: 'Améliorer la communication.' } }))
  })
  it('annote une VOD partageable avec horodatage et confirmation de partage', async () => {
    show(); const user = userEvent.setup(); await user.click(await screen.findByRole('button', { name: 'Ajouter un contenu' }))
    const form = screen.getByRole('form', { name: 'Enregistrer le contenu' }), f = within(form)
    await user.selectOptions(f.getByLabelText('Type de contenu'), 'vod'); await user.selectOptions(f.getByLabelText('Espace du contenu'), 'shared')
    await user.type(f.getByLabelText('Titre du contenu'), 'Analyse à revoir'); await user.type(f.getByLabelText('Notes ou annotation'), 'Observer le déplacement.')
    await user.type(f.getByLabelText('Lien VOD HTTPS'), 'https://example.com/vod/42'); await user.type(f.getByLabelText('Horodatage en secondes'), '123')
    await user.click(f.getByLabelText('Je confirme le partage de ce contenu avec tous les lecteurs autorisés du match.'))
    fireEvent.submit(form); await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    expect(request).toHaveBeenCalledWith(path, expect.objectContaining({ body: expect.objectContaining({ kind: 'vod', audience: 'shared', vod_url: 'https://example.com/vod/42', timestamp_seconds: 123, confirm_sharing: true }) }))
  })
  it('marque une action terminée sans changer son espace ou son type', async () => {
    request.mockImplementation(async (_target, options) => options?.method ? { data: entry } : page([{ ...entry, kind: 'action', state: 'open', due_at: '2026-10-22T12:00:00+00:00' }]))
    show(); const user = userEvent.setup(); await user.click(await screen.findByRole('button', { name: 'Modifier ce contenu' }))
    const form = screen.getByRole('form', { name: 'Enregistrer le contenu' }), f = within(form)
    expect(f.queryByLabelText('Espace du contenu')).toBeNull(); expect(f.queryByLabelText('Type de contenu')).toBeNull()
    await user.selectOptions(f.getByLabelText('État du suivi'), 'done')
    fireEvent.submit(form); await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    expect(request).toHaveBeenCalledWith(path + '/' + a, expect.objectContaining({ method: 'PATCH', body: { version, title: entry.title, body: entry.body, state: 'done', due_at: '2026-10-22T12:00:00+00:00' } }))
  })
  it('filtre les espaces et consulte les anciennes versions avec pagination', async () => {
    show(); const user = userEvent.setup(); await screen.findByText(entry.body)
    const filters = within(screen.getByRole('form', { name: 'Filtrer la préparation et le débrief' }))
    await user.selectOptions(filters.getByLabelText('Espace du contenu'), 'strategy'); await user.click(filters.getByRole('button', { name: 'Filtrer les contenus' }))
    await screen.findByText(entry.body)
    expect(request).toHaveBeenCalledWith(expect.stringContaining('audience=strategy'), expect.anything())
    await user.click(screen.getByRole('button', { name: 'Versions du contenu' }))
    const history = await screen.findByRole('region', { name: 'Versions du contenu' })
    await within(history).findByText(entry.body); await user.click(within(history).getByRole('button', { name: 'Page suivante' }))
    expect(request).toHaveBeenCalledWith(path + '/' + a + '/history?page=2&per_page=10', expect.anything())
  })
  it('refuse les liens actifs dangereux et protège le référent des VOD HTTPS', async () => {
    request.mockResolvedValue(page([{ ...entry, kind: 'vod', vod_url: 'javascript:alert(1)' }], []))
    const view = show(); await screen.findByText(entry.body); expect(view.container.querySelector('a,iframe')).toBeNull(); view.unmount()
    request.mockResolvedValue(page([{ ...entry, kind: 'vod', vod_url: 'https://example.com/vod/42' }], []))
    show(); const link = await screen.findByRole('link', { name: 'Ouvrir la VOD externe' })
    expect(link).toHaveAttribute('rel', 'noopener noreferrer'); expect(link).toHaveAttribute('referrerpolicy', 'no-referrer')
  })
  it('conserve la saisie après 422 et ne rejoue pas après un conflit de version', async () => {
    let stale = false
    request.mockImplementation(async (target, options) => {
      if (target === 'active-organization/confirmation') return { data: { ...selection, confirmed: true } }
      if (options?.method) throw new ApiClientError(stale ? 'Le contenu a changé.' : 'Notes invalides.', { status: stale ? 409 : 422, fieldErrors: stale ? {} : { body: ['Notes invalides.'] } })
      return page()
    })
    show(); const user = userEvent.setup(); await user.click(await screen.findByRole('button', { name: 'Modifier ce contenu' }))
    const form = screen.getByRole('form', { name: 'Enregistrer le contenu' })
    fireEvent.submit(form); await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' })); await within(form).findByText('Notes invalides.')
    expect(within(form).getByLabelText('Notes ou annotation')).toHaveValue(entry.body)
    stale = true; fireEvent.submit(form); await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' })); await screen.findAllByText('Le contenu a changé.')
    expect(invalid).toHaveBeenCalledOnce(); expect(request.mock.calls.filter(([target, options]) => target === path + '/' + a && options?.method)).toHaveLength(2)
  })
})
