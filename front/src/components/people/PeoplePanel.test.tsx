import { act, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { tenancyRequest, type ActiveOrganization } from '../../api/tenancy'
import { ApiClientError } from '../../api/errors'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { PeoplePanel } from './PeoplePanel'

vi.mock('../../api/tenancy', () => ({ tenancyRequest: vi.fn() }))
const request = vi.mocked(tenancyRequest)
const org = '11111111-1111-4111-8111-111111111111'
const revision = '22222222-2222-4222-8222-222222222222'
const person = { id: '33333333-3333-4333-8333-333333333333', version: revision, pseudonym: 'Camille', language: 'fr', timezone: 'UTC', status: 'active', biography_public: '<script>private()</script>', first_name: 'SECRET', biography_internal: 'INTERNAL' }
const profile = { id: '44444444-4444-4444-8444-444444444444', version: revision, game_key: 'valorant', platform_key: 'pc', region_key: 'eu', rank: 'Gold', game_role: 'Duelist', observed_at: '2026-01-01T12:00:05+00:00', freshness: 'stale', status: 'active', external_accounts_count: 1 }
const account = { id: '55555555-5555-4555-8555-555555555555', version: revision, provider_key: 'riot', external_id: 'StableID', display_name: 'Alias', status: 'active', observed_at: '2026-01-01T12:00:05+00:00', freshness: 'stale' }
const catalogue = { games: [{ key: 'valorant', name: 'VALORANT', regions: [{ key: 'eu', name: 'Europe' }], platforms: [{ key: 'pc', name: 'PC', providers: [{ key: 'riot', name: 'Riot' }] }] }] }
const page = (data: unknown[], current = 1, last = 1) => ({ data, meta: { current_page: current, last_page: last, total: data.length, request_id: 'request' } })
function active(permissions = ['sports.read', 'sports.write', 'administrative.read', 'administrative.write'], id = org): ActiveOrganization {
  return { context: { organization_id: id, name: id === org ? 'Alpha' : 'Beta', slug: 'test', timezone: 'UTC', language: 'fr', roles: ['owner'], permissions: ['organization.view', ...permissions], is_owner: true, actor_user_id: 42, membership_id: null }, revision, confirmed: false }
}
function show(permissions?: string[]) {
  const invalid = vi.fn()
  const result = render(<NotificationProvider><PeoplePanel active={active(permissions)} disabled={false} onInvalid={invalid} /></NotificationProvider>)
  return { ...result, invalid }
}
async function open(user: ReturnType<typeof userEvent.setup>) {
  await user.click(await screen.findByRole('button', { name: 'Ouvrir la fiche Camille' }))
  await screen.findByRole('button', { name: 'Fermer la fiche' })
}
async function editPerson(user: ReturnType<typeof userEvent.setup>) {
  await open(user)
  await user.click(screen.getByRole('button', { name: 'Modifier la fiche' }))
  return screen.getByRole('form', { name: 'Enregistrer la fiche' })
}
describe('Fiches et recherche de personnes', () => {
  beforeEach(() => {
    request.mockReset()
    request.mockImplementation(async (path, options) => {
      if (options?.method) return { data: person, notification: { type: 'success', message: 'Enregistré.' } }
      if (path.endsWith('/departure')) return { data: { departed: false, person_version: revision } }
      if (path.includes('/departure-items')) return { ...page([]), meta: { ...page([]).meta, pending_items_count: 0 } }
      if (path.includes('/periods')) return { ...page([]), meta: { ...page([]).meta, unavailable_now: false } }
      if (path.includes('game-profile-catalogue')) return { data: catalogue }
      if (path.includes('external-accounts')) return page([account])
      if (path.includes('game-profiles')) return page([profile])
      if (path.includes('people?')) return page([person])
      return { data: person }
    })
  })
  it('un propriétaire sans permissions métier ne voit ni liste ni commande', async () => {
    show([])
    await act(async () => {})
    expect(screen.queryByRole('heading', { name: 'Personnes' })).not.toBeInTheDocument()
    expect(request).not.toHaveBeenCalled()
  })
  it('un lecteur administratif ne recherche pas les données sportives et ne charge pas les profils', async () => {
    show(['administrative.read'])
    await screen.findByRole('button', { name: 'Ouvrir la fiche Camille' })
    expect(screen.queryByLabelText('Début du pseudonyme')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Ajouter une personne' })).not.toBeInTheDocument()
    expect(request.mock.calls.some(([path]) => path.includes('catalogue') || path.includes('game-profiles'))).toBe(false)
    expect(screen.getByLabelText('Compte de connexion lié')).toBeVisible()
  })
  it('un auteur sans lecture peut créer sans charger de liste ni de données privées', async () => {
    const user = userEvent.setup()
    show(['sports.write'])
    expect(screen.getByText('Vous ne disposez pas du droit de consulter les fiches.')).toBeVisible()
    await user.click(screen.getByRole('button', { name: 'Ajouter une personne' }))
    expect(screen.queryByLabelText('Prénom')).not.toBeInTheDocument()
    await user.type(screen.getByLabelText('Pseudonyme'), 'New')
    await user.click(screen.getByRole('button', { name: 'Enregistrer la fiche' }))
    expect(request).not.toHaveBeenCalled()
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/' + org + '/people', expect.objectContaining({ method: 'POST', body: { pseudonym: 'New', language: 'fr', timezone: 'UTC', status: 'candidate', biography_public: null }, revision })))
  })
  it('recherche et pagination utilisent des paramètres encodés, puis repartent en page un', async () => {
    const user = userEvent.setup()
    request.mockImplementation(async (path) => path.includes('catalogue') ? { data: catalogue } : page([person], new URLSearchParams(path.split('?')[1]).get('page') === '2' ? 2 : 1, 2))
    show(['sports.read'])
    await user.click(await screen.findByRole('button', { name: 'Page 2' }))
    await waitFor(() => expect(request.mock.calls.some(([path]) => path.includes('page=2'))).toBe(true))
    await user.type(screen.getByLabelText('Début du pseudonyme'), 'Ca%_')
    await user.click(screen.getByRole('button', { name: 'Appliquer les filtres' }))
    await waitFor(() => expect(request.mock.calls.some(([path]) => path.includes('page=1') && path.includes('q=Ca%25_'))).toBe(true))
  })
  it('annuler une confirmation conserve la saisie et retourne le focus sans mutation', async () => {
    const user = userEvent.setup()
    show()
    const form = await editPerson(user)
    await user.clear(within(form).getByLabelText('Pseudonyme'))
    await user.type(within(form).getByLabelText('Pseudonyme'), 'Changed')
    await user.click(within(form).getByRole('button', { name: 'Enregistrer la fiche' }))
    const confirmation = screen.getByRole('region', { name: 'Confirmer l’action' })
    expect(confirmation).toHaveFocus()
    await user.click(within(confirmation).getByRole('button', { name: 'Annuler' }))
    expect(within(form).getByLabelText('Pseudonyme')).toHaveValue('Changed')
    expect(within(form).getByRole('button', { name: 'Enregistrer la fiche' })).toHaveFocus()
    expect(request.mock.calls.some(([, options]) => options?.method)).toBe(false)
  })
  it('une modification sportive omet les champs administratifs intacts et utilise la version', async () => {
    const user = userEvent.setup()
    show()
    const form = await editPerson(user)
    await user.clear(within(form).getByLabelText('Pseudonyme'))
    await user.type(within(form).getByLabelText('Pseudonyme'), 'Changed')
    await user.click(within(form).getByRole('button', { name: 'Enregistrer la fiche' }))
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/' + org + '/people/' + person.id, expect.objectContaining({ method: 'PATCH', body: { pseudonym: 'Changed', version: revision }, revision })))
    const mutations = request.mock.calls.filter(([, options]) => options?.method)
    expect(mutations[0][0]).toBe('active-organization/confirmation')
  })
  it('une erreur 422 conserve le brouillon et cible le champ invalide', async () => {
    const user = userEvent.setup()
    const base = request.getMockImplementation()!
    request.mockImplementation(async (path, options) => {
      if (options?.method === 'PATCH') throw new ApiClientError('Validation refusée.', { status: 422, fieldErrors: { pseudonym: ['Pseudonyme invalide.'] } })
      return base(path, options)
    })
    show()
    const form = await editPerson(user)
    await user.clear(within(form).getByLabelText('Pseudonyme'))
    await user.type(within(form).getByLabelText('Pseudonyme'), 'Changed')
    await user.click(within(form).getByRole('button', { name: 'Enregistrer la fiche' }))
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    expect(await screen.findByText('Pseudonyme invalide.')).toBeVisible()
    expect(within(form).getByLabelText('Pseudonyme')).toHaveValue('Changed')
    expect(within(form).getByLabelText('Pseudonyme')).toHaveFocus()
  })
  it('une version obsolète invalide le contexte sans rejouer automatiquement la mutation', async () => {
    const user = userEvent.setup()
    const base = request.getMockImplementation()!
    request.mockImplementation(async (path, options) => {
      if (options?.method === 'PATCH') throw new ApiClientError('Fiche obsolète.', { status: 409, code: 'PERSON_VERSION_STALE' })
      return base(path, options)
    })
    const { invalid } = show()
    const form = await editPerson(user)
    await user.type(within(form).getByLabelText('Pseudonyme'), 'X')
    await user.click(within(form).getByRole('button', { name: 'Enregistrer la fiche' }))
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(invalid).toHaveBeenCalledTimes(1))
    expect(request.mock.calls.filter(([, options]) => options?.method === 'PATCH')).toHaveLength(1)
  })
  it('la présentation libre est affichée comme texte et les vues cachent les champs absents', async () => {
    const user = userEvent.setup()
    show()
    await open(user)
    expect(screen.getByText('<script>private()</script>')).toBeVisible()
    expect(document.querySelector('script')).toBeNull()
    const detail = screen.getByRole('region', { name: 'Modifier la fiche' })
    await user.selectOptions(within(detail).getByLabelText('Vue de la liste'), 'sports')
    expect(within(detail).queryByText('SECRET')).not.toBeInTheDocument()
    expect(within(detail).queryByText('INTERNAL')).not.toBeInTheDocument()
  })
  it('modifier le rang ne réaffirme pas une ancienne observation', async () => {
    const user = userEvent.setup()
    show()
    await open(user)
    await user.click(await screen.findByRole('button', { name: /Modifier le profil de jeu/ }))
    const form = screen.getByRole('form', { name: 'Enregistrer le profil de jeu' })
    await user.clear(within(form).getByLabelText('Rang déclaré'))
    await user.type(within(form).getByLabelText('Rang déclaré'), 'Platinum')
    await user.click(within(form).getByRole('button', { name: 'Enregistrer le profil de jeu' }))
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/' + org + '/people/' + person.id + '/game-profiles/' + profile.id, expect.objectContaining({ method: 'PATCH', body: { rank: 'Platinum', version: revision } })))
  })
  it('un compte conserve identifiant et fournisseur immuables lors du changement d’alias', async () => {
    const user = userEvent.setup()
    show()
    await open(user)
    await user.click(await screen.findByRole('button', { name: /Afficher les comptes externes/ }))
    await user.click(await screen.findByRole('button', { name: /Modifier le compte externe/ }))
    const form = screen.getByRole('form', { name: 'Enregistrer le compte externe' })
    expect(within(form).getByLabelText('Identifiant externe stable (facultatif)')).toHaveAttribute('readonly')
    await user.clear(within(form).getByLabelText('Alias du compte externe'))
    await user.type(within(form).getByLabelText('Alias du compte externe'), 'New Alias')
    await user.click(within(form).getByRole('button', { name: 'Enregistrer le compte externe' }))
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/' + org + '/people/' + person.id + '/game-profiles/' + profile.id + '/external-accounts/' + account.id, expect.objectContaining({ method: 'PATCH', body: { display_name: 'New Alias', version: revision } })))
  })
  it.each([{ games: [] }, { games: [{ ...catalogue.games[0], regions: [] }] }])('une référence ou région retirée reste visible et peut être archivée sans remettre son rang', async (withdrawnCatalogue) => {
    const user = userEvent.setup()
    const base = request.getMockImplementation()!
    request.mockImplementation(async (path, options) => path.includes('catalogue') ? { data: withdrawnCatalogue } : base(path, options))
    show()
    await open(user)
    await user.click(await screen.findByRole('button', { name: /Modifier le profil de jeu/ }))
    const form = screen.getByRole('form', { name: 'Enregistrer le profil de jeu' })
    expect(within(form).queryByLabelText('Rang déclaré')).not.toBeInTheDocument()
    await user.click(within(form).getByRole('button', { name: 'Enregistrer le profil de jeu' }))
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/' + org + '/people/' + person.id + '/game-profiles/' + profile.id, expect.objectContaining({ body: { status: 'archived', version: revision } })))
  })
  it('un changement de tenant pendant la confirmation empêche l’envoi du PATCH', async () => {
    const user = userEvent.setup()
    let finish!: (value: unknown) => void
    const base = request.getMockImplementation()!
    request.mockImplementation(async (path, options) => path === 'active-organization/confirmation' ? new Promise((resolve) => { finish = resolve }) : base(path, options))
    const { unmount } = show()
    const form = await editPerson(user)
    await user.type(within(form).getByLabelText('Pseudonyme'), 'X')
    await user.click(within(form).getByRole('button', { name: 'Enregistrer la fiche' }))
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    unmount()
    await act(async () => { finish({ data: null }) })
    expect(request.mock.calls.some(([, options]) => options?.method === 'PATCH')).toBe(false)
  })
  it('une réponse tardive d’Alpha ne réaffiche rien après un changement d’organisation', async () => {
    let finish!: (value: unknown) => void
    const invalid = vi.fn()
    request.mockImplementation(async (path) => {
      if (path.includes('catalogue')) return { data: catalogue }
      if (path.includes(org)) return new Promise((resolve) => { finish = resolve })
      return page([])
    })
    const result = render(<NotificationProvider><PeoplePanel key={org} active={active()} disabled={false} onInvalid={invalid} /></NotificationProvider>)
    await waitFor(() => expect(finish).toBeTypeOf('function'))
    result.rerender(<NotificationProvider><PeoplePanel key="beta" active={active([], 'beta')} disabled={false} onInvalid={invalid} /></NotificationProvider>)
    await act(async () => { finish(page([person])) })
    expect(screen.queryByText('Camille')).not.toBeInTheDocument()
    expect(screen.queryByText('SECRET')).not.toBeInTheDocument()
  })
  it('crée un profil avec le catalogue et une observation datée explicite', async () => {
    const user = userEvent.setup()
    show()
    await open(user)
    await user.click(await screen.findByRole('button', { name: 'Ajouter un profil de jeu' }))
    const form = screen.getByRole('form', { name: 'Enregistrer le profil de jeu' })
    await user.selectOptions(within(form).getByLabelText('Jeu'), 'valorant')
    await user.selectOptions(within(form).getByLabelText('Plateforme'), 'pc')
    await user.selectOptions(within(form).getByLabelText('Zone de jeu'), 'eu')
    await user.type(within(form).getByLabelText('Rang déclaré'), 'Gold')
    const date = within(form).getByLabelText('Date de l’observation manuelle')
    await user.type(date, '2026-01-01T12:00:05')
    await user.click(within(form).getByRole('button', { name: 'Enregistrer le profil de jeu' }))
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    const expectedDate = new Date('2026-01-01T12:00:05').toISOString().replace('.000Z', '+00:00')
    await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/' + org + '/people/' + person.id + '/game-profiles', expect.objectContaining({ method: 'POST', body: { game_key: 'valorant', platform_key: 'pc', region_key: 'eu', rank: 'Gold', game_role: null, observed_at: expectedDate, status: 'active' } })))
  })
  it('crée un compte sans identifiant stable avec un alias et le fournisseur disponible', async () => {
    const user = userEvent.setup()
    show()
    await open(user)
    await user.click(await screen.findByRole('button', { name: /Afficher les comptes externes/ }))
    await user.click(await screen.findByRole('button', { name: 'Ajouter un compte externe' }))
    const form = screen.getByRole('form', { name: 'Enregistrer le compte externe' })
    await user.selectOptions(within(form).getByLabelText('Fournisseur du compte'), 'riot')
    await user.type(within(form).getByLabelText('Alias du compte externe'), 'Alias#EU')
    await user.click(within(form).getByRole('button', { name: 'Enregistrer le compte externe' }))
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    await waitFor(() => expect(request).toHaveBeenCalledWith('tenants/' + org + '/people/' + person.id + '/game-profiles/' + profile.id + '/external-accounts', expect.objectContaining({ method: 'POST', body: { provider_key: 'riot', external_id: null, display_name: 'Alias#EU', observed_at: null, status: 'active' } })))
  })
  it('un lecteur sportif ne rend aucune identité privée même si la réponse contient des champs supplémentaires', async () => {
    const user = userEvent.setup()
    show(['sports.read'])
    await open(user)
    expect(screen.queryByText('SECRET')).not.toBeInTheDocument()
    expect(screen.queryByText('INTERNAL')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Modifier le profil de jeu/ })).not.toBeInTheDocument()
  })
  it('une configuration de catalogue invalide affiche l’erreur sans rechargements automatiques en boucle', async () => {
    const base = request.getMockImplementation()!
    request.mockImplementation(async (path, options) => {
      if (path.includes('catalogue')) throw new ApiClientError('Catalogue invalide.', { status: 409, code: 'GAME_CATALOGUE_INVALID' })
      return base(path, options)
    })
    const { invalid } = show(['sports.read'])
    expect(await screen.findByText('Catalogue invalide.')).toBeVisible()
    expect(invalid).not.toHaveBeenCalled()
    expect(request.mock.calls.filter(([path]) => path.includes('catalogue'))).toHaveLength(1)
  })
})
