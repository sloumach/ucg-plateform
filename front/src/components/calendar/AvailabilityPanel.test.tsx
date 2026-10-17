import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ActiveOrganization } from '../../api/tenancy'
import type { Availability } from '../../api/availability'
import { ApiClientError } from '../../api/errors'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { AvailabilityPanel } from './AvailabilityPanel'
const { request, invalid } = vi.hoisted(() => ({ request: vi.fn(), invalid: vi.fn() }))
vi.mock('../../api/tenancy', async (original) => ({ ...await original<typeof import('../../api/tenancy')>(), tenancyRequest: request }))
const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222', version = '33333333-3333-4333-8333-333333333333'
const person = '44444444-4444-4444-8444-444444444444', other = '55555555-5555-4555-8555-555555555555', root = 'tenants/' + org + '/calendar'
const active: ActiveOrganization = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', language: 'fr', timezone: 'UTC', roles: ['member'],
  permissions: ['availability.self'], actor_user_id: 42, is_owner: false, membership_id: id }, revision: version, confirmed: false }
const base: Availability = { id, person_id: person, person_label: 'Camille', status: 'available', comment: 'Commentaire initial', starts_at: '2026-10-18T08:00:00+00:00', ends_at: '2026-10-18T09:00:00+00:00',
  origin_timezone: 'Europe/Paris', starts_at_origin: '2026-10-18T10:00:00+02:00', ends_at_origin: '2026-10-18T11:00:00+02:00', cancelled_at: null, version, number: 1, can_edit: true, can_cancel: true, can_history: true }
let rows: Availability[], meta: { viewer_person_id: string | null; viewer_timezone: string; display_timezone: string; can_self: boolean; can_manage: boolean }
function page<T>(data: T[]) { return { data, meta: { current_page: 1, last_page: 1, total: data.length } } }
function show(selection = active) { return render(<NotificationProvider><AvailabilityPanel active={selection} disabled={false} onInvalid={invalid} /></NotificationProvider>) }
async function open(user: ReturnType<typeof userEvent.setup>) {
  await user.click(screen.getByRole('button', { name: 'Ouvrir les disponibilités' }))
  await screen.findByRole('heading', { name: new RegExp(rows[0]?.person_label ?? 'Disponibilités et absences') })
  await waitFor(() => expect(screen.queryByText('Chargement des disponibilités…')).not.toBeInTheDocument())
}
beforeEach(() => {
  vi.resetAllMocks(); rows = [{ ...base }]; meta = { viewer_person_id: person, viewer_timezone: 'Asia/Tokyo', display_timezone: 'Asia/Tokyo', can_self: true, can_manage: false }
  request.mockImplementation(async (path, options) => {
    if (path === 'active-organization/confirmation') return { data: { ...active, confirmed: true } }
    if (options?.method) return { data: { ...base, ...options.body } }
    if (path.includes('/availability-people?')) return page([{ id: other, label: '<script>Autre personne</script>' }])
    if (path.includes('/history?')) return page([{ ...base, action: 'created', created_at: '2026-10-17T10:00:00+00:00' }])
    return { ...page(rows), availability: { ...meta, from_utc: '2026-10-17T00:00:00+00:00', to_utc: '2026-11-17T00:00:00+00:00' } }
  })
})
describe('disponibilités et absences', () => {
  it('ne charge rien avant ouverture ou sans droits dédiés', () => {
    const { unmount } = show(); expect(request).not.toHaveBeenCalled(); unmount()
    show({ ...active, context: { ...active.context!, permissions: ['calendar.read', 'sports.write'] } })
    expect(screen.queryByRole('heading', { name: 'Disponibilités et absences' })).not.toBeInTheDocument(); expect(request).not.toHaveBeenCalled()
  })
  it('crée sa disponibilité dans le fuseau préféré après confirmation du tenant sans motif imposé', async () => {
    const user = userEvent.setup(); show(); await open(user); await user.click(screen.getByRole('button', { name: 'Nouveau créneau' }))
    const form = within(screen.getByRole('form', { name: 'Enregistrer le créneau' }))
    expect(form.getByLabelText('Fuseau IANA d’origine')).toHaveValue('Asia/Tokyo')
    expect(form.queryByLabelText('Personne concernée')).not.toBeInTheDocument()
    await user.selectOptions(form.getByLabelText('Disponibilité'), 'unavailable')
    await user.type(form.getByLabelText('Début dans le fuseau d’origine'), '2026-10-18T10:00')
    await user.type(form.getByLabelText('Fin dans le fuseau d’origine'), '2026-10-18T11:00')
    await user.click(form.getByRole('button', { name: 'Enregistrer le créneau' }))
    expect(request.mock.calls.some(([, options]) => options?.method === 'POST')).toBe(false)
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith(root + '/availabilities', expect.objectContaining({ method: 'POST', revision: version,
      body: { status: 'unavailable', comment: null, starts_at: '2026-10-18T10:00:00+09:00', ends_at: '2026-10-18T11:00:00+09:00', origin_timezone: 'Asia/Tokyo' } })))
  })
  it('préserve les horaires lors d’une modification limitée au statut ou au commentaire', async () => {
    const user = userEvent.setup(); show(); await open(user); await user.click(screen.getByRole('button', { name: 'Modifier le créneau' }))
    const form = within(screen.getByRole('form', { name: 'Enregistrer le créneau' }))
    await user.selectOptions(form.getByLabelText('Disponibilité'), 'uncertain')
    await user.click(form.getByRole('button', { name: 'Enregistrer le créneau' })); await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith(root + '/availabilities/' + id, expect.objectContaining({ method: 'PATCH', body: { version, status: 'uncertain' } })))
  })
  it('exige le choix explicite d’une heure répétée et refuse une heure inexistante', async () => {
    meta.viewer_timezone = 'Europe/Paris'; const user = userEvent.setup(); show(); await open(user); await user.click(screen.getByRole('button', { name: 'Nouveau créneau' }))
    const form = within(screen.getByRole('form', { name: 'Enregistrer le créneau' }))
    await user.type(form.getByLabelText('Début dans le fuseau d’origine'), '2026-03-29T02:30')
    await user.type(form.getByLabelText('Fin dans le fuseau d’origine'), '2026-03-29T03:30')
    expect(form.getByRole('button', { name: 'Enregistrer le créneau' })).toBeDisabled()
    expect(form.getByRole('alert')).toHaveTextContent('Heure inexistante')
    await user.clear(form.getByLabelText('Début dans le fuseau d’origine')); await user.type(form.getByLabelText('Début dans le fuseau d’origine'), '2026-10-25T02:30')
    await user.clear(form.getByLabelText('Fin dans le fuseau d’origine')); await user.type(form.getByLabelText('Fin dans le fuseau d’origine'), '2026-10-25T03:30')
    expect(form.getByRole('button', { name: 'Enregistrer le créneau' })).toBeDisabled()
    await user.selectOptions(form.getByLabelText('Occurrence du début'), '2026-10-25T02:30:00+01:00')
    await user.click(form.getByRole('button', { name: 'Enregistrer le créneau' })); await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith(root + '/availabilities', expect.objectContaining({ body: expect.objectContaining({ starts_at: '2026-10-25T02:30:00+01:00' }) })))
  })
  it('réserve la lecture collective aux habilitations et respecte les capacités de chaque ligne', async () => {
    meta = { ...meta, can_self: false, can_manage: false }; rows = [{ ...base, comment: undefined, can_edit: false, can_cancel: false, can_history: false }]
    const user = userEvent.setup(); show({ ...active, context: { ...active.context!, permissions: ['availability.read'] } }); await open(user)
    expect(screen.getByLabelText('Périmètre des disponibilités')).toHaveValue('1')
    expect(screen.queryByRole('button', { name: 'Nouveau créneau' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Voir l’historique' })).not.toBeInTheDocument()
    expect(screen.queryByText('Commentaire initial')).not.toBeInTheDocument()
    expect(request.mock.calls.some(([path]) => path.includes('collective=1'))).toBe(true)
  })
  it('permet au gestionnaire de choisir une autre personne sans modifier son identité ensuite', async () => {
    meta.can_manage = true
    const user = userEvent.setup(); show({ ...active, context: { ...active.context!, permissions: ['availability.self', 'availability.read', 'availability.manage'] } }); await open(user)
    await user.click(screen.getByRole('button', { name: 'Nouveau créneau' }))
    const form = within(screen.getByRole('form', { name: 'Enregistrer le créneau' }))
    await user.click(form.getByLabelText('Déclarer pour moi'))
    await user.selectOptions(await form.findByLabelText('Personne concernée'), other)
    await user.type(form.getByLabelText('Début dans le fuseau d’origine'), '2026-10-18T10:00')
    await user.type(form.getByLabelText('Fin dans le fuseau d’origine'), '2026-10-18T11:00')
    await user.click(form.getByRole('button', { name: 'Enregistrer le créneau' })); await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith(root + '/availabilities', expect.objectContaining({ body: expect.objectContaining({ person_id: other }) })))
  })
  it('affiche l’historique avec textes échappés et annule uniquement après confirmation', async () => {
    rows = [{ ...base, person_label: '<script>Personne</script>', comment: '<img src=x>' }]
    const user = userEvent.setup(); const { container } = show(); await open(user)
    expect(container.querySelector('script,img')).toBeNull()
    expect(screen.getByText('<img src=x>')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Voir l’historique' }))
    await within(await screen.findByRole('region', { name: 'Historique du créneau' })).findByText('Commentaire initial')
    await user.click(screen.getByRole('button', { name: 'Annuler le créneau' }))
    expect(request.mock.calls.some(([, options]) => options?.method === 'PATCH')).toBe(false)
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith(root + '/availabilities/' + id, expect.objectContaining({ body: { version, cancel: true } })))
  })
  it('ne rejoue pas une mutation en conflit et invalide le contexte', async () => {
    const handler = request.getMockImplementation()!
    request.mockImplementation((path, options) => options?.method === 'PATCH' ? Promise.reject(new ApiClientError('Créneau chevauchant', { status: 409, code: 'AVAILABILITY_OVERLAP' })) : handler(path, options))
    const user = userEvent.setup(); show(); await open(user)
    await user.click(screen.getByRole('button', { name: 'Annuler le créneau' })); await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(invalid).toHaveBeenCalledOnce())
    expect(request.mock.calls.filter(([, options]) => options?.method === 'PATCH')).toHaveLength(1)
    expect(within(screen.getByRole('region', { name: 'Disponibilités et absences' })).getByRole('alert')).toHaveTextContent('Créneau chevauchant')
  })
  it('bloque la saisie personnelle sans personne liée et annule les lectures au démontage', async () => {
    meta.viewer_person_id = null; rows = []
    const user = userEvent.setup(); const { unmount } = show(); await open(user)
    expect(screen.getByText(/La saisie personnelle nécessite/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Nouveau créneau' })).not.toBeInTheDocument()
    const signal = request.mock.calls[0][1].signal as AbortSignal; unmount(); expect(signal.aborted).toBe(true)
  })
})
