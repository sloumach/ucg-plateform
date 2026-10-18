import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ActiveOrganization } from '../../api/tenancy'
import type { ExternalCompetition } from '../../api/externalCompetitions'
import { ApiClientError } from '../../api/errors'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { ExternalCompetitionsPanel } from './ExternalCompetitionsPanel'
const fake = vi.hoisted(() => ({ request: vi.fn(), invalid: vi.fn() }))
vi.mock('../../api/tenancy', async (original) => ({ ...await original<typeof import('../../api/tenancy')>(), tenancyRequest: fake.request }))
const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222', version = '33333333-3333-4333-8333-333333333333'
const active: ActiveOrganization = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'], permissions: ['sports.read', 'sports.competitions.read', 'sports.competitions.write'], actor_user_id: 42, is_owner: false, membership_id: id }, revision: version, confirmed: false }
const record: ExternalCompetition = { id, reference: 'LEAGUE_2026', game_id: id, game_key: 'pilot', game_name: 'Jeu pilote', name: '<script>Circuit privé</script>', organizer: 'Organisateur externe', region: 'Europe', season: '2026', website_url: 'https://league.example.test', contacts: [{ name: '<img>Contact</img>', role: 'Manager', email: 'private@example.test', phone: null }], status: 'active', version, created_at: '2026-10-18T10:00:00Z', updated_at: '2026-10-18T10:00:00Z' }
const root = 'tenants/' + org + '/external-competitions'
function page<T>(data: T[]) { return { data, meta: { current_page: 1, last_page: 1, total: data.length } } }
function show(selection = active) { return render(<NotificationProvider><ExternalCompetitionsPanel active={selection} disabled={false} onInvalid={fake.invalid} /></NotificationProvider>) }
beforeEach(() => {
  vi.resetAllMocks()
  fake.request.mockImplementation(async (path, options) => {
    if (path === 'active-organization/confirmation') return { data: { ...active, confirmed: true } }
    if (options?.method) return { data: { ...record, ...options.body } }
    if (path.includes('/games?')) return page([{ id, label: 'Jeu pilote' }])
    if (path.includes('/history?')) return page([{ id, version, number: 1, snapshot: record, changed_fields: ['name'], reason: '<script>Motif historique</script>', actor_user_id: 42, occurred_at: record.created_at }])
    return page([record])
  })
})
describe('suivi des compétitions externes', () => {
  it('réserve les données aux droits dédiés et charge seulement à ouverture', async () => {
    const view = show({ ...active, context: { ...active.context!, permissions: ['sports.read'] } })
    expect(screen.queryByRole('region', { name: 'Compétitions externes' })).toBeNull(); expect(fake.request).not.toHaveBeenCalled(); view.unmount()
    show({ ...active, context: { ...active.context!, permissions: ['sports.read', 'sports.competitions.read'] } })
    expect(fake.request).not.toHaveBeenCalled(); await userEvent.click(screen.getByRole('button', { name: 'Ouvrir les compétitions' }))
    await screen.findByText(record.name)
    expect(screen.queryByRole('button', { name: 'Nouvelle compétition externe' })).toBeNull()
    expect(screen.queryByRole('button', { name: 'Modifier le dossier' })).toBeNull()
  })
  it('échappe les contacts et ouvre la source sans référent', async () => {
    const view = show(); await userEvent.click(screen.getByRole('button', { name: 'Ouvrir les compétitions' })); await screen.findByText(record.name)
    expect(view.container.querySelector('script,img')).toBeNull()
    expect(screen.getByRole('link', { name: 'Ouvrir le site officiel' })).toHaveAttribute('referrerpolicy', 'no-referrer')
  })
  it('confirme le tenant avant création et transmet les contacts structurés sans accorder de partage', async () => {
    show(); const user = userEvent.setup(); await user.click(screen.getByRole('button', { name: 'Ouvrir les compétitions' })); await screen.findByText(record.name)
    await user.click(screen.getByRole('button', { name: 'Nouvelle compétition externe' }))
    const form = await screen.findByRole('form', { name: 'Enregistrer le dossier' }), fields = within(form)
    await fields.findByRole('option', { name: 'Jeu pilote' }); await user.selectOptions(fields.getByLabelText('Jeu de la compétition'), id)
    for (const [label, value] of [['Référence interne', 'league_new'], ['Nom de la compétition', 'Circuit neuf'], ['Organisateur externe', 'Organisateur'], ['Région', 'Europe'], ['Saison', '2027'], ['Motif du changement', 'Participation renseignée après vérification.']]) await user.type(fields.getByLabelText(label), value)
    await user.click(fields.getByRole('button', { name: 'Ajouter un contact' })); await user.type(fields.getByLabelText('Nom du contact'), 'Contact Alpha')
    fireEvent.submit(form)
    expect(fake.request.mock.calls.filter(([, options]) => options?.method === 'POST')).toHaveLength(0)
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    expect(fake.request).toHaveBeenCalledWith(root, expect.objectContaining({ method: 'POST', revision: version, body: { reference: 'LEAGUE_NEW', game_id: id, name: 'Circuit neuf', organizer: 'Organisateur', region: 'Europe', season: '2027', website_url: null, contacts: [{ name: 'Contact Alpha', role: null, email: null, phone: null }], reason: 'Participation renseignée après vérification.' } }))
  })
  it('conserve le formulaire après 422 et ne rejoue pas un conflit de version', async () => {
    show(); const user = userEvent.setup(); await user.click(screen.getByRole('button', { name: 'Ouvrir les compétitions' })); await screen.findByText(record.name)
    await user.click(screen.getByRole('button', { name: 'Modifier le dossier' })); const form = screen.getByRole('form', { name: 'Enregistrer le dossier' })
    await user.clear(within(form).getByLabelText('Nom de la compétition')); await user.type(within(form).getByLabelText('Nom de la compétition'), 'Nom saisi conservé')
    await user.type(within(form).getByLabelText('Motif du changement'), 'Correction justifiée de la compétition.')
    fake.request.mockImplementation(async (path, options) => { if (path === 'active-organization/confirmation') return { data: { ...active, confirmed: true } }; if (options?.method === 'PATCH') throw new ApiClientError('Nom invalide.', { status: 422, fieldErrors: { name: ['Nom invalide.'] } }); return page([record]) })
    fireEvent.submit(form); await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' })); await screen.findAllByText('Nom invalide.')
    expect(within(form).getByLabelText('Nom de la compétition')).toHaveValue('Nom saisi conservé')
    fake.request.mockImplementation(async (path, options) => { if (path === 'active-organization/confirmation') return { data: { ...active, confirmed: true } }; if (options?.method === 'PATCH') throw new ApiClientError('Le dossier a changé.', { status: 409 }); return page([record]) })
    fireEvent.submit(form); await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' })); await screen.findAllByText('Le dossier a changé.')
    expect(fake.invalid).toHaveBeenCalledOnce(); expect(fake.request.mock.calls.filter(([, options]) => options?.method === 'PATCH')).toHaveLength(2)
  })
  it('consulte les versions privées sans écriture et archive séparément les métadonnées', async () => {
    show(); const user = userEvent.setup(); await user.click(screen.getByRole('button', { name: 'Ouvrir les compétitions' })); await screen.findByText(record.name)
    await user.click(screen.getByRole('button', { name: 'Historique du dossier' })); await screen.findByText('<script>Motif historique</script>')
    await user.click(screen.getByRole('button', { name: 'Archiver le dossier' })); const form = screen.getByRole('form', { name: 'Archiver le dossier' })
    await user.type(within(form).getByLabelText('Motif du changement'), 'La saison est clôturée et doit être conservée.')
    fireEvent.submit(form); await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    expect(fake.request).toHaveBeenCalledWith(root + '/' + id, expect.objectContaining({ method: 'PATCH', body: { version, archive: true, reason: 'La saison est clôturée et doit être conservée.' } }))
  })
})
