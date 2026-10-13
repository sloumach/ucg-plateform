import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { tenancyRequest } from '../../api/tenancy'
import { ApiClientError } from '../../api/errors'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { GameCataloguePanel } from './GameCataloguePanel'
import type { CatalogueEntry } from '../../api/gameCatalogue'

vi.mock('../../api/tenancy', () => ({ tenancyRequest: vi.fn() }))
const request = vi.mocked(tenancyRequest)
const revision = '22222222-2222-4222-8222-222222222222'
const game: CatalogueEntry = { id: '33333333-3333-4333-8333-333333333333', kind: 'game', key: 'valorant', name: 'VALORANT', active: true,
  game_id: null, definition: { platforms: { pc: ['riot'] }, regions: ['eu'] }, version: revision }
const reference = (kind: CatalogueEntry['kind'], key: string, name: string): CatalogueEntry => ({ ...game, id: kind + '-' + key, kind, key, name, definition: null })
let canManage = true, mutations = 0
function show() { return render(<NotificationProvider><GameCataloguePanel /></NotificationProvider>) }
beforeEach(() => {
  vi.resetAllMocks(); canManage = true; mutations = 0
  request.mockImplementation(async (path, options) => {
    if (options?.method) { mutations++; return { data: game, meta: { request_id: 'saved' } } }
    const kind = new URL('http://example.test/' + path).searchParams.get('kind')
    const data = kind === 'game' ? [game] : kind === 'platform' ? [reference('platform', 'pc', 'PC')] :
      kind === 'provider' ? [reference('provider', 'riot', 'Riot Games')] : kind === 'region' ? [reference('region', 'eu', 'Europe')] : []
    return { data, meta: { request_id: 'read', current_page: 1, last_page: 1, total: data.length, catalogue_version: revision, can_manage: canManage } }
  })
})
describe('catalogue commun', () => {
  it('ne charge le catalogue qu’à son ouverture', async () => {
    const user = userEvent.setup(); show()
    expect(request).not.toHaveBeenCalled()
    await user.click(screen.getByRole('button', { name: 'Catalogue commun des jeux' }))
    expect(await screen.findByText('VALORANT')).toBeVisible()
  })
  it('enregistre une nouvelle référence après confirmation globale explicite et accessible', async () => {
    const user = userEvent.setup(); show()
    await user.click(screen.getByRole('button', { name: 'Catalogue commun des jeux' }))
    await user.click(await screen.findByRole('button', { name: 'Ajouter une référence' }))
    const form = screen.getByRole('form', { name: 'Enregistrer la référence' })
    await user.type(within(form).getByLabelText('Clé stable'), 'new-game')
    await user.type(within(form).getByLabelText('Nom'), 'Nouveau jeu')
    await user.click(within(form).getByLabelText('PC'))
    await user.click(within(form).getByLabelText('Riot Games'))
    await user.click(within(form).getByLabelText('Europe'))
    await user.click(within(form).getByRole('button', { name: 'Enregistrer la référence' }))
    const confirmation = screen.getByRole('region', { name: 'Confirmer le catalogue commun' })
    expect(confirmation).toHaveFocus()
    expect(mutations).toBe(0)
    expect(within(confirmation).getByRole('button', { name: 'Appliquer au catalogue commun' })).toBeDisabled()
    await user.click(within(confirmation).getByLabelText(/Je confirme la modification du catalogue commun/))
    await user.click(within(confirmation).getByRole('button', { name: 'Appliquer au catalogue commun' }))
    await waitFor(() => expect(mutations).toBe(1))
    expect(request).toHaveBeenCalledWith('game-catalogue', expect.objectContaining({ method: 'POST', body: {
      kind: 'game', key: 'new-game', name: 'Nouveau jeu', active: true,
      definition: { platforms: { pc: ['riot'] }, regions: ['eu'] }, catalogue_version: revision, confirm_global: true,
    } }))
    expect(request.mock.calls.some(([, options]) => options?.revision)).toBe(false)
  })
  it('n’expose aucun formulaire d’écriture au lecteur', async () => {
    canManage = false
    const user = userEvent.setup(); show()
    await user.click(screen.getByRole('button', { name: 'Catalogue commun des jeux' }))
    await screen.findByText('VALORANT')
    expect(screen.queryByRole('button', { name: 'Ajouter une référence' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Modifier' })).not.toBeInTheDocument()
  })
  it('conserve le formulaire après une erreur 422 et affiche le champ localisé', async () => {
    const user = userEvent.setup(); show()
    await user.click(screen.getByRole('button', { name: 'Catalogue commun des jeux' }))
    await user.click(await screen.findByRole('button', { name: 'Modifier' }))
    const form = screen.getByRole('form', { name: 'Enregistrer la référence' })
    const name = within(form).getByLabelText('Nom')
    await user.clear(name); await user.type(name, 'Nom conservé')
    request.mockImplementationOnce(async () => { throw new ApiClientError('Saisie invalide', { status: 422, fieldErrors: { name: ['Nom refusé.'] }, requestId: 'invalid-global' }) })
    await user.click(within(form).getByRole('button', { name: 'Enregistrer la référence' }))
    await user.click(screen.getByLabelText(/Je confirme la modification du catalogue commun/))
    await user.click(screen.getByRole('button', { name: 'Appliquer au catalogue commun' }))
    expect(await screen.findByText('Nom refusé.')).toBeVisible()
    expect(name).toHaveValue('Nom conservé')
    expect(screen.getByLabelText('Nom')).toHaveFocus()
    expect(screen.queryByLabelText('Clé stable')).not.toBeInTheDocument()
  })
  it('annule une confirmation sans aucune écriture', async () => {
    const user = userEvent.setup(); show()
    await user.click(screen.getByRole('button', { name: 'Catalogue commun des jeux' }))
    await user.click(await screen.findByRole('button', { name: 'Modifier' }))
    const form = screen.getByRole('form', { name: 'Enregistrer la référence' })
    await user.click(within(form).getByRole('button', { name: 'Enregistrer la référence' }))
    await user.click(within(screen.getByRole('region', { name: 'Confirmer le catalogue commun' })).getByRole('button', { name: 'Annuler' }))
    expect(mutations).toBe(0)
    expect(within(form).getByRole('button', { name: 'Enregistrer la référence' })).toHaveFocus()
  })
  it('demande un jeu avant de lire les cartes et envoie un filtre explicite', async () => {
    const user = userEvent.setup(); show()
    await user.click(screen.getByRole('button', { name: 'Catalogue commun des jeux' }))
    await screen.findByText('VALORANT')
    await user.selectOptions(screen.getByLabelText('Catégorie'), 'map')
    await user.selectOptions(await screen.findByLabelText('Jeu'), game.id)
    await waitFor(() => expect(request).toHaveBeenCalledWith(expect.stringContaining('kind=map&per_page=10&page=1&game_id=' + game.id), expect.anything()))
    expect(mutations).toBe(0)
  })
})
