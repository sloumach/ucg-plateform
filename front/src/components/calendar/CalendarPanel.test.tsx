import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ActiveOrganization } from '../../api/tenancy'
import type { CalendarActivity } from '../../api/calendar'
import { ApiClientError } from '../../api/errors'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { CalendarPanel } from './CalendarPanel'
const { request, invalid } = vi.hoisted(() => ({ request: vi.fn(), invalid: vi.fn() }))
vi.mock('../../api/tenancy', async (original) => ({ ...await original<typeof import('../../api/tenancy')>(), tenancyRequest: request }))
const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222', version = '33333333-3333-4333-8333-333333333333'
const person = '44444444-4444-4444-8444-444444444444', team = '55555555-5555-4555-8555-555555555555', root = 'tenants/' + org + '/calendar'
const active: ActiveOrganization = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', language: 'fr', timezone: 'UTC', roles: ['member'],
  permissions: ['calendar.read', 'calendar.write', 'calendar.publish'], actor_user_id: 42, is_owner: false, membership_id: id }, revision: version, confirmed: false }
const base: CalendarActivity = { id, title: 'Activité pilote', description: 'Message interne', kind: 'training', starts_at: '2026-10-18T08:00:00+00:00', ends_at: '2026-10-18T09:00:00+00:00',
  origin_timezone: 'Europe/Paris', starts_at_origin: '2026-10-18T10:00:00+02:00', ends_at_origin: '2026-10-18T11:00:00+02:00',
  team_id: null, team_label: null, participants: [], location: null, url: null, visibility: 'private', archived_at: null, version }
let rows: CalendarActivity[]
function page<T>(data: T[]) { return { data, meta: { current_page: 1, last_page: 1, total: data.length } } }
function show(selection = active) { return render(<NotificationProvider><CalendarPanel active={selection} disabled={false} onInvalid={invalid} /></NotificationProvider>) }
async function open(user: ReturnType<typeof userEvent.setup>) { await user.click(screen.getByRole('button', { name: 'Ouvrir le calendrier' })); await screen.findByRole('heading', { name: rows[0].title }) }
beforeEach(() => {
  vi.resetAllMocks(); rows = [{ ...base }]
  request.mockImplementation(async (path, options) => {
    if (path === 'active-organization/confirmation') return { data: { ...active, confirmed: true } }
    if (options?.method) return { data: { ...base, ...options.body } }
    if (path.includes('/teams?')) return page([{ id: team, label: '<script>Équipe</script>' }])
    if (path.includes('/people?')) return page([{ id: person, label: '<img>Joueur</img>' }])
    return { ...page(rows), calendar: { viewer_timezone: 'Asia/Tokyo', display_timezone: 'Asia/Tokyo', from_utc: '2026-10-16T15:00:00+00:00', to_utc: '2026-11-15T15:00:00+00:00' } }
  })
})
describe('calendrier des activités', () => {
  it('ne charge rien avant ouverture ou sans permission dédiée', () => {
    const { unmount } = show(); expect(request).not.toHaveBeenCalled(); unmount()
    show({ ...active, context: { ...active.context!, permissions: ['sports.read', 'communication.read'] } })
    expect(screen.queryByRole('heading', { name: 'Calendrier des activités' })).not.toBeInTheDocument(); expect(request).not.toHaveBeenCalled()
  })
  it('affiche les fuseaux de consultation et d’origine en échappant tous les textes', async () => {
    rows = [{ ...base, title: '<script>titre</script>', description: '<img src=x>', location: '<iframe>lieu</iframe>', url: 'javascript:alert(1)', participants: [{ id: person, label: '<script>Joueur</script>' }] }]
    const user = userEvent.setup(); const { container } = show(); await open(user)
    expect(screen.getByText('<img src=x>')).toBeInTheDocument()
    expect(screen.getByText(/Fuseau d’origine : Europe\/Paris/)).toBeInTheDocument()
    expect(screen.getByText(/Horaires affichés dans le fuseau : Asia\/Tokyo/)).toBeInTheDocument()
    expect(container.querySelector('script,iframe,img')).toBeNull()
    expect(screen.queryByRole('link', { name: 'Ouvrir le lien de l’activité' })).not.toBeInTheDocument()
  })
  it('crée dans le fuseau préféré avec participants et confirme le tenant avant toute mutation', async () => {
    const user = userEvent.setup(); show(); await open(user); await user.click(screen.getByRole('button', { name: 'Nouvelle activité' }))
    const form = within(screen.getByRole('form', { name: 'Enregistrer l’activité' }))
    expect(form.getByLabelText('Fuseau IANA d’origine')).toHaveValue('Asia/Tokyo')
    await user.type(form.getByLabelText('Titre de l’activité'), 'Séance')
    await user.type(form.getByLabelText('Début dans le fuseau d’origine'), '2026-10-18T10:00')
    await user.type(form.getByLabelText('Fin dans le fuseau d’origine'), '2026-10-18T11:00')
    await form.findByRole('option', { name: '<script>Équipe</script>' })
    await user.selectOptions(form.getByLabelText('Équipe associée'), team)
    await user.click(await form.findByLabelText('<img>Joueur</img>'))
    await user.click(form.getByRole('button', { name: 'Enregistrer l’activité' }))
    expect(request.mock.calls.some(([, options]) => options?.method === 'POST')).toBe(false)
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith(root + '/activities', expect.objectContaining({ method: 'POST', revision: version, body: expect.objectContaining({
      title: 'Séance', starts_at: '2026-10-18T10:00:00+09:00', ends_at: '2026-10-18T11:00:00+09:00', origin_timezone: 'Asia/Tokyo', visibility: 'private', participant_ids: [person], team_id: team,
    }) })))
    expect(request).toHaveBeenCalledWith('active-organization/confirmation', expect.anything())
  })
  it('exige un choix explicite pour une heure répétée avant d’enregistrer', async () => {
    const user = userEvent.setup(); show(); await open(user); await user.click(screen.getByRole('button', { name: 'Nouvelle activité' }))
    const form = within(screen.getByRole('form', { name: 'Enregistrer l’activité' }))
    await user.clear(form.getByLabelText('Fuseau IANA d’origine')); await user.type(form.getByLabelText('Fuseau IANA d’origine'), 'Europe/Paris')
    await user.type(form.getByLabelText('Titre de l’activité'), 'Changement d’heure')
    await user.type(form.getByLabelText('Début dans le fuseau d’origine'), '2026-10-25T02:30')
    await user.type(form.getByLabelText('Fin dans le fuseau d’origine'), '2026-10-25T03:30')
    expect(form.getByRole('button', { name: 'Enregistrer l’activité' })).toBeDisabled()
    await user.selectOptions(form.getByLabelText('Occurrence du début'), '2026-10-25T02:30:00+01:00')
    await user.click(form.getByRole('button', { name: 'Enregistrer l’activité' }))
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith(root + '/activities', expect.objectContaining({ body: expect.objectContaining({ starts_at: '2026-10-25T02:30:00+01:00' }) })))
  })
  it('ne réaffirme pas les horaires et participants inchangés lors du renommage', async () => {
    const user = userEvent.setup(); show(); await open(user); await user.click(screen.getByRole('button', { name: 'Modifier l’activité' }))
    const form = within(screen.getByRole('form', { name: 'Enregistrer l’activité' }))
    await user.clear(form.getByLabelText('Titre de l’activité')); await user.type(form.getByLabelText('Titre de l’activité'), 'Nouveau titre')
    await user.click(form.getByRole('button', { name: 'Enregistrer l’activité' })); await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith(root + '/activities/' + id, expect.objectContaining({ method: 'PATCH', body: { version, title: 'Nouveau titre' } })))
  })
  it('limite la gestion et le partage aux permissions dédiées', async () => {
    const user = userEvent.setup(); const { unmount } = show({ ...active, context: { ...active.context!, permissions: ['calendar.read'] } }); await open(user)
    expect(screen.queryByRole('button', { name: 'Nouvelle activité' })).not.toBeInTheDocument()
    expect(screen.queryByLabelText('Périmètre de consultation')).not.toBeInTheDocument(); unmount()
    show({ ...active, context: { ...active.context!, permissions: ['calendar.read', 'calendar.write'] } }); await open(user)
    await user.click(screen.getByRole('button', { name: 'Nouvelle activité' }))
    expect(within(screen.getByRole('form', { name: 'Enregistrer l’activité' })).getByLabelText('Confidentialité')).toHaveTextContent('Privé')
    expect(within(screen.getByRole('form', { name: 'Enregistrer l’activité' })).queryByRole('option', { name: 'Lecteurs calendrier de l’organisation' })).not.toBeInTheDocument()
  })
  it('applique des bornes de dates et un fuseau explicite dans une requête encodée', async () => {
    const user = userEvent.setup(); show(); await open(user)
    const form = within(screen.getByRole('form', { name: 'Filtres du calendrier' }))
    await user.clear(form.getByLabelText('Fuseau d’affichage (facultatif)')); await user.type(form.getByLabelText('Fuseau d’affichage (facultatif)'), 'Europe/Paris')
    await user.click(form.getByRole('button', { name: 'Afficher la période' }))
    await waitFor(() => expect(request.mock.calls.some(([path]) => path.includes('timezone=Europe%2FParis') && path.includes('from_date=') && path.includes('to_date='))).toBe(true))
  })
  it('invalide le contexte révoqué et annule les lectures au démontage', async () => {
    request.mockRejectedValue(new ApiClientError('Accès révoqué', { status: 403 }))
    const user = userEvent.setup(); const { unmount } = show(); await user.click(screen.getByRole('button', { name: 'Ouvrir le calendrier' }))
    await waitFor(() => expect(invalid).toHaveBeenCalled()); const signal = request.mock.calls[0][1].signal as AbortSignal
    unmount(); expect(signal.aborted).toBe(true)
  })
})
