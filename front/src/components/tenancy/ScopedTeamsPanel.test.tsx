import { act, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, expect, it, vi } from 'vitest'
import { tenancyRequest, type ActiveOrganization } from '../../api/tenancy'
import { ApiClientError } from '../../api/errors'
import { ScopedTeamsPanel } from './ScopedTeamsPanel'

vi.mock('../../api/tenancy', () => ({ tenancyRequest: vi.fn() }))
const request = vi.mocked(tenancyRequest)
const active: ActiveOrganization = { context: { organization_id: 'alpha', name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'], permissions: ['organization.view'], is_owner: false, actor_user_id: 42, membership_id: 'member' }, revision: 'revision', confirmed: true }
const page = (id: string, current = 1) => ({ data: [{ id, label: 'Équipe ' + id }], meta: { current_page: current, last_page: 2, total: 2 } })
beforeEach(() => { request.mockReset() })

it('consulte uniquement les équipes explicitement autorisées sans permission globale ni commande de roster', async () => {
  request.mockImplementation(async (path) => path.includes('my-team-scopes') ? page('A') : { data: { id: 'A', name: 'Équipe A', game_name: 'VALORANT', season: '2026', public_description: 'Description publique' } })
  const user = userEvent.setup()
  render(<ScopedTeamsPanel active={active} disabled={false} onInvalid={vi.fn()} />)
  expect(request).not.toHaveBeenCalled()
  await user.click(screen.getByRole('button', { name: 'Mes équipes autorisées' }))
  await user.click(await screen.findByRole('button', { name: 'Équipe A' }))
  expect(await screen.findByRole('heading', { name: 'Équipe A' })).toBeVisible()
  expect(screen.getByText('Description publique')).toBeVisible()
  expect(request).toHaveBeenLastCalledWith('tenants/alpha/teams/A', expect.objectContaining({ revision: 'revision', signal: expect.any(AbortSignal) }))
  expect(request.mock.calls.every(([path]) => path.includes('my-team-scopes') || path === 'tenants/alpha/teams/A')).toBe(true)
  expect(active.context!.permissions).toEqual(['organization.view'])
  expect(screen.queryByRole('button', { name: /créer|modifier|effectif/i })).not.toBeInTheDocument()
})

it('annule le détail à la pagination et ignore une réponse devenue obsolète', async () => {
  let finish!: (value: unknown) => void
  request.mockImplementation(async (path) => path.includes('my-team-scopes') ? page(path.endsWith('page=2') ? 'B' : 'A', path.endsWith('page=2') ? 2 : 1) : new Promise((resolve) => { finish = resolve }))
  const user = userEvent.setup()
  render(<ScopedTeamsPanel active={active} disabled={false} onInvalid={vi.fn()} />)
  await user.click(screen.getByRole('button', { name: 'Mes équipes autorisées' }))
  await user.click(await screen.findByRole('button', { name: 'Équipe A' }))
  await waitFor(() => expect(request).toHaveBeenCalledTimes(2))
  const signal = request.mock.calls[1][1]!.signal!
  await user.click(screen.getByRole('button', { name: /suivante/i }))
  expect(await screen.findByRole('button', { name: 'Équipe B' })).toBeVisible()
  await act(async () => { finish({ data: { id: 'A', name: 'Ancienne équipe' } }) })
  expect(signal.aborted).toBe(true)
  expect(screen.queryByRole('heading')).not.toBeInTheDocument()
})

it('relit les droits à chaque ouverture et masque les équipes révoquées', async () => {
  request.mockResolvedValue(page('A'))
  const user = userEvent.setup()
  render(<ScopedTeamsPanel active={active} disabled={false} onInvalid={vi.fn()} />)
  const toggle = screen.getByRole('button', { name: 'Mes équipes autorisées' })
  await user.click(toggle)
  await screen.findByRole('button', { name: 'Équipe A' })
  await user.click(toggle)
  request.mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, total: 0 } })
  await user.click(toggle)
  expect(await screen.findByText('Aucun accès équipe limité actif.')).toBeVisible()
  expect(screen.queryByRole('button', { name: 'Équipe A' })).not.toBeInTheDocument()
})

it('signale la perte du contexte et ne montre aucun détail refusé', async () => {
  const onInvalid = vi.fn()
  request.mockImplementation(async (path) => {
    if (path.includes('my-team-scopes')) return page('A')
    throw new ApiClientError('Accès révoqué', { status: 403 })
  })
  const user = userEvent.setup()
  render(<ScopedTeamsPanel active={active} disabled={false} onInvalid={onInvalid} />)
  await user.click(screen.getByRole('button', { name: 'Mes équipes autorisées' }))
  await user.click(await screen.findByRole('button', { name: 'Équipe A' }))
  expect(await screen.findByText('Accès révoqué')).toBeVisible()
  expect(onInvalid).toHaveBeenCalledOnce()
  expect(screen.queryByRole('heading')).not.toBeInTheDocument()
})
