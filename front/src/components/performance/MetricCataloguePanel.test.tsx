import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { MetricCataloguePanel } from './MetricCataloguePanel'
import type { MetricDefinition } from '../../api/metricCatalogue'
import { ApiClientError } from '../../api/errors'
const fake = vi.hoisted(() => ({ request: vi.fn() }))
vi.mock('../../api/tenancy', async (original) => ({ ...await original<typeof import('../../api/tenancy')>(), tenancyRequest: fake.request }))
const game = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222', version = '33333333-3333-4333-8333-333333333333', gamesVersion = '44444444-4444-4444-8444-444444444444', secondGame = '55555555-5555-4555-8555-555555555555'
const row: MetricDefinition = { id, game_id: game, code: 'KD_RATIO', version, number: 1, scope: { game_id: game, game_key: 'pilot', game_name: 'Jeu pilote', mode_id: null, mode_name: null, role_id: null, role_name: null, release_id: null, release_name: null }, mode_id: null, role_id: null, release_id: null, title: '<img>Ratio global</img>', definition: 'Ratio des composantes observées.', unit: 'ratio', aggregation: 'ratio', aggregation_level: 'game', formula: '<script>sum(kills)/sum(deaths)</script>', zero_denominator: 'unavailable', measurement_kind: 'objective', availability: 'source_required', availability_note: 'Fournisseur et accès à confirmer.', status: 'active', reason: 'Définition initiale contrôlée.', occurred_at: '2026-10-19T10:00:00Z' }
let canManage: boolean, current: MetricDefinition[], failure: ApiClientError | null
function show() { return render(<NotificationProvider><MetricCataloguePanel /></NotificationProvider>) }
async function open() { await userEvent.click(screen.getByRole('button', { name: 'Ouvrir le dictionnaire' })); await screen.findByText(row.title) }
function page<T>(data: T[]) { return { data, meta: { current_page: 1, last_page: 1, total: data.length } } }
beforeEach(() => {
  vi.resetAllMocks(); canManage = true; current = [row]; failure = null
  fake.request.mockImplementation(async (path, options) => {
    if (options?.method) { if (failure) throw failure; return { data: row } }
    if (path.includes('game-catalogue?')) { const params = new URLSearchParams(path.split('?')[1]); return page(params.get('kind') === 'game' ? [{ id: game, name: 'Jeu pilote', active: true }, { id: secondGame, name: 'Autre jeu', active: true }] : [{ id, name: 'Contexte pilote', active: true }]) }
    if (path.includes('/history?')) return page([{ ...row, reason: 'Ancienne formule conservée.' }])
    return { ...page(current), meta: { ...page(current).meta, can_manage: canManage, catalogue_version: version, game_catalogue_version: gamesVersion } }
  })
})
describe('dictionnaire global des métriques', () => {
  it('charge à la demande et échappe les formules documentaires sans proposer la saisie de valeurs', async () => {
    const { container } = show(); expect(fake.request).not.toHaveBeenCalled(); await open(); expect(screen.getByText(row.formula, { exact: false })).toBeVisible(); expect(container.querySelector('script,img')).toBeNull(); expect(screen.queryByRole('form')).toBeNull(); expect(fake.request.mock.calls.some(([p]) => p.includes('/history'))).toBe(false)
  })
  it('réserve les modifications au gestionnaire global déclaré par le serveur', async () => {
    canManage = false; show(); await open(); expect(screen.queryByRole('button', { name: 'Créer une métrique' })).toBeNull(); expect(screen.queryByRole('button', { name: 'Modifier cette définition' })).toBeNull(); await userEvent.click(screen.getByRole('button', { name: 'Historique des définitions' })); expect(await screen.findByText(/Ancienne formule conservée/)).toBeVisible()
  })
  it('exige la confirmation globale et transmet les trois versions sans identité tenant', async () => {
    show(); await open(); await userEvent.click(screen.getByRole('button', { name: 'Modifier cette définition' })); const form = screen.getByRole('form', { name: 'Enregistrer cette définition' }); await userEvent.selectOptions(within(form).getByLabelText('Niveau de la mesure'), 'period'); fireEvent.change(within(form).getByLabelText('Formule documentée'), { target: { value: 'Nouvelle formule documentée.' } }); await userEvent.type(within(form).getByLabelText('Motif du changement'), 'Correction selon la définition publiée.'); fireEvent.submit(form)
    const confirmation = screen.getByRole('region', { name: 'Confirmer la définition globale' }); expect(within(confirmation).getByRole('button', { name: 'Confirmer la définition globale' })).toBeDisabled(); expect(fake.request.mock.calls.filter(([, o]) => o?.method)).toHaveLength(0)
    await userEvent.click(within(confirmation).getByRole('checkbox')); await userEvent.click(within(confirmation).getByRole('button', { name: 'Confirmer la définition globale' })); await waitFor(() => expect(screen.queryByRole('form')).toBeNull())
    const call = fake.request.mock.calls.find(([, o]) => o?.method)!
    expect(call[0]).toBe('metric-catalogue/' + id); expect(call[1].body).toMatchObject({ version, catalogue_version: version, game_catalogue_version: gamesVersion, confirm_global: true, aggregation_level: 'period', formula: 'Nouvelle formule documentée.', zero_denominator: 'unavailable' }); expect(call[1].body).not.toHaveProperty('organization_id'); expect(call[1].body).not.toHaveProperty('code'); expect(call[1].body).not.toHaveProperty('game_id')
  })
  it('impose un comportement explicite du ratio et le retire pour une somme', async () => {
    show(); await open(); await userEvent.click(screen.getByRole('button', { name: 'Modifier cette définition' })); const form = screen.getByRole('form'); await userEvent.selectOptions(within(form).getByLabelText('Agrégation'), 'sum'); expect(within(form).getByLabelText('Si le dénominateur vaut zéro')).toHaveValue('not_applicable'); await userEvent.selectOptions(within(form).getByLabelText('Agrégation'), 'ratio'); expect(within(form).getByLabelText('Si le dénominateur vaut zéro')).toHaveValue('unavailable'); expect(within(within(form).getByLabelText('Si le dénominateur vaut zéro')).queryByRole('option', { name: 'Non applicable' })).toBeNull()
  })
  it('réinitialise les références de contexte quand le jeu change à la création', async () => {
    show(); await open(); await userEvent.click(screen.getByRole('button', { name: 'Créer une métrique' })); const form = screen.getByRole('form'); await within(form).findByRole('option', { name: 'Jeu pilote' }); await userEvent.selectOptions(within(form).getByLabelText('Jeu'), game); await within(form).findByLabelText('Mode'); await waitFor(() => expect(within(form).getByLabelText('Mode')).not.toBeDisabled()); await userEvent.selectOptions(within(form).getByLabelText('Mode'), id); await userEvent.selectOptions(within(form).getByLabelText('Jeu'), secondGame); expect(within(form).getByLabelText('Mode')).toHaveValue('')
  })
  it('conserve la saisie et cible le champ refusé après une erreur 422', async () => {
    show(); await open(); await userEvent.click(screen.getByRole('button', { name: 'Modifier cette définition' })); const form = screen.getByRole('form'); await userEvent.type(within(form).getByLabelText('Motif du changement'), 'Précision de la règle de calcul.'); failure = new ApiClientError('Formule refusée.', { status: 422, fieldErrors: { formula: ['Expliquez cette formule.'] } }); fireEvent.submit(form); await userEvent.click(screen.getByRole('checkbox')); await userEvent.click(screen.getByRole('button', { name: 'Confirmer la définition globale' })); expect(await screen.findByText('Expliquez cette formule.')).toBeVisible(); await waitFor(() => expect(within(form).getByLabelText('Formule documentée')).toHaveFocus()); expect(within(form).getByLabelText('Motif du changement')).toHaveValue('Précision de la règle de calcul.')
  })
  it('ne rejoue pas 409 et retire les contrôles de gestion après révocation', async () => {
    show(); await open(); await userEvent.click(screen.getByRole('button', { name: 'Modifier cette définition' })); const form = screen.getByRole('form'); await userEvent.type(within(form).getByLabelText('Motif du changement'), 'Précision soumise à vérification.'); failure = new ApiClientError('Dictionnaire modifié.', { status: 409 }); fireEvent.submit(form); await userEvent.click(screen.getByRole('checkbox')); canManage = false; await userEvent.click(screen.getByRole('button', { name: 'Confirmer la définition globale' })); await waitFor(() => expect(screen.queryByRole('form')).toBeNull()); expect(fake.request.mock.calls.filter(([, o]) => o?.method)).toHaveLength(1); await waitFor(() => expect(screen.queryByRole('button', { name: 'Créer une métrique' })).toBeNull())
  })
})
