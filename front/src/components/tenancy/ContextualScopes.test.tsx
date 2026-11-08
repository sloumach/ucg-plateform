import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { tenancyRequest, type ActiveOrganization } from '../../api/tenancy'
import { ApiClientError } from '../../api/errors'
import { ScopeSourcePicker } from './ScopeSourcePicker'
import { ContextualModulesPanel } from './ContextualModulesPanel'
import { NotificationProvider } from '../feedback/NotificationProvider'

vi.mock('../../api/tenancy', () => ({ tenancyRequest: vi.fn() }))
vi.mock('../academy/AcademyPanel', () => ({ AcademyPanel: ({ active, limited }: { active: ActiveOrganization; limited?: boolean }) => <div data-permissions={active.context!.permissions.join(',')}>Académie {limited ? 'limitée' : 'globale'}</div> }))
vi.mock('../calendar/CalendarPanel', () => ({ CalendarPanel: ({ active, limited }: { active: ActiveOrganization; limited?: boolean }) => <div data-permissions={active.context!.permissions.join(',')}>Calendrier {limited ? 'limité' : 'global'}</div> }))
vi.mock('../teams/OrganizationGamesPanel', () => ({ OrganizationGamesPanel: () => <div>Jeux limités</div> }))
const request = vi.mocked(tenancyRequest)
const org = '11111111-1111-4111-8111-111111111111'
const active: ActiveOrganization = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr',
  roles: ['member'], permissions: ['organization.view'], is_owner: false, actor_user_id: 42, membership_id: 'member' }, revision: 'revision', confirmed: true }
const page = (data: { id: string; label: string }[], current = 1) => ({ data, meta: { current_page: current, last_page: 2, total: 2 } })

describe('Sélection des périmètres IAM', () => {
  beforeEach(() => { request.mockReset() })
  it.each(['team', 'game', 'cohort', 'event'] as const)('charge le référentiel %s avec la révision du tenant et conserve le choix entre pages', async (type) => {
    const user = userEvent.setup()
    request.mockImplementation(async (path) => page(path.endsWith('page=1') ? [{ id: 'one', label: 'Objet A' }] : [{ id: 'two', label: 'Objet B' }], path.endsWith('page=1') ? 1 : 2))
    render(<ScopeSourcePicker active={active} type={type} disabled={false} />)
    await screen.findByRole('option', { name: 'Objet A' })
    await user.selectOptions(screen.getByRole('combobox'), 'one')
    await user.click(screen.getByRole('button', { name: /suivante/i }))
    await screen.findByRole('option', { name: 'Objet B' })
    expect(screen.getByRole('combobox')).toHaveValue('one')
    expect(request).toHaveBeenCalledWith('tenants/' + org + '/authorization-scopes/' + type + '?per_page=10&page=2', expect.objectContaining({ revision: 'revision', signal: expect.any(AbortSignal) }))
  })
  it('annule la lecture et rejette une réponse arrivée après le changement de périmètre', async () => {
    let resolveOld!: (value: unknown) => void
    request.mockImplementationOnce(() => new Promise((resolve) => { resolveOld = resolve }))
      .mockResolvedValueOnce(page([{ id: 'event', label: 'Événement courant' }]))
    const view = render(<ScopeSourcePicker key="game" active={active} type="game" disabled={false} />)
    await waitFor(() => expect(request).toHaveBeenCalledTimes(1))
    const signal = request.mock.calls[0][1]!.signal!
    view.rerender(<ScopeSourcePicker key="event" active={active} type="event" disabled={false} />)
    await screen.findByRole('option', { name: 'Événement courant' })
    resolveOld(page([{ id: 'old', label: 'Ancien jeu' }]))
    await waitFor(() => expect(signal.aborted).toBe(true))
    expect(screen.queryByRole('option', { name: 'Ancien jeu' })).not.toBeInTheDocument()
    expect(screen.getByRole('combobox')).toHaveValue('')
  })
  it('désactive la sélection lorsque les droits source sont refusés', async () => {
    request.mockRejectedValue(new ApiClientError('Droits source retirés', { status: 403 }))
    render(<ScopeSourcePicker active={active} type="cohort" disabled={false} />)
    expect(await screen.findByText('Droits source retirés')).toBeVisible()
    expect(screen.getByRole('combobox')).toBeDisabled()
  })
})

describe('Espace des accès limités', () => {
  beforeEach(() => { request.mockReset() })
  it('demande les droits frais à l’ouverture et garde le contexte global intact', async () => {
    request.mockResolvedValue({ data: { permissions: ['academy.read', 'academy.manage'] } })
    const user = userEvent.setup()
    render(<NotificationProvider><ContextualModulesPanel active={active} disabled={false} onInvalid={vi.fn()} /></NotificationProvider>)
    expect(request).not.toHaveBeenCalled()
    await user.click(screen.getByRole('button', { name: 'Mes accès limités' }))
    expect(await screen.findByText('Académie limitée')).toBeVisible()
    expect(screen.queryByText('Calendrier limité')).not.toBeInTheDocument()
    expect(active.context!.permissions).toEqual(['organization.view'])
    await user.click(screen.getByRole('button', { name: 'Mes accès limités' }))
    request.mockResolvedValue({ data: { permissions: [] } })
    await user.click(screen.getByRole('button', { name: 'Mes accès limités' }))
    expect(await screen.findByText('Aucun droit limité supplémentaire actif.')).toBeVisible()
    expect(screen.queryByText('Académie limitée')).not.toBeInTheDocument()
  })
  it('ne rend aucun module si le contexte est devenu inaccessible', async () => {
    request.mockRejectedValue(new ApiClientError('Accès révoqué', { status: 403 }))
    const user = userEvent.setup()
    render(<NotificationProvider><ContextualModulesPanel active={active} disabled={false} onInvalid={vi.fn()} /></NotificationProvider>)
    await user.click(screen.getByRole('button', { name: 'Mes accès limités' }))
    expect(await screen.findByText('Accès révoqué')).toBeVisible()
    expect(screen.queryByText('Académie limitée')).not.toBeInTheDocument()
  })
  it('isole les permissions limitées de chaque module sans ouvrir ses référentiels voisins', async () => {
    request.mockResolvedValue({ data: { permissions: ['academy.read', 'academy.manage', 'calendar.read', 'calendar.write'] } })
    const user = userEvent.setup()
    render(<NotificationProvider><ContextualModulesPanel active={active} disabled={false} onInvalid={vi.fn()} /></NotificationProvider>)
    await user.click(screen.getByRole('button', { name: 'Mes accès limités' }))
    const academy = await screen.findByText('Académie limitée')
    const calendar = await screen.findByText('Calendrier limité')
    expect(academy).toHaveAttribute('data-permissions', 'organization.view,academy.read,academy.manage')
    expect(calendar).toHaveAttribute('data-permissions', 'organization.view,calendar.read,calendar.write')
  })

})
