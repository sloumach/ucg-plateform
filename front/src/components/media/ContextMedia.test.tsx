import { beforeEach, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ActiveOrganization } from '../../api/tenancy'
import { ContextMedia } from './ContextMedia'
const { request } = vi.hoisted(() => ({ request: vi.fn() }))
vi.mock('../../api/tenancy', async (original) => ({ ...await original<typeof import('../../api/tenancy')>(), tenancyRequest: request }))
const id = '11111111-1111-4111-8111-111111111111'
const active: ActiveOrganization = { context: { organization_id: id, name: 'Alpha', slug: 'alpha', language: 'fr', timezone: 'UTC', roles: ['member'], permissions: ['media.read', 'calendar.read', 'sports.read'], actor_user_id: 42, is_owner: false, membership_id: id }, revision: id, confirmed: false }
beforeEach(() => { request.mockReset(); request.mockResolvedValue({ data: [{ id, title: '<script>VOD</script>', kind: 'vod', status: 'ended', status_observed_at: '2026-11-07T10:00:00Z', effective_visibility: 'organization', url: 'https://www.youtube.com/watch?v=abcdefghijk' }], meta: { current_page: 1, last_page: 1, total: 1 } }) })
it.each(['event', 'match'] as const)('charge les médias du contexte %s à l’ouverture et échappe les textes', async (type) => {
  const user = userEvent.setup(), invalid = vi.fn()
  const { container } = render(<ContextMedia active={active} type={type} id={id} disabled={false} onInvalid={invalid} />)
  expect(request).not.toHaveBeenCalled()
  await user.click(screen.getByRole('button', { name: 'Voir les médias associés' }))
  await screen.findByText('<script>VOD</script>')
  expect(request).toHaveBeenCalledWith('tenants/' + id + '/media?context_type=' + type + '&context_id=' + id + '&per_page=10&page=1', expect.objectContaining({ revision: id }))
  expect(container.querySelector('script,iframe,img')).toBeNull()
  expect(screen.getByRole('link', { name: 'Ouvrir chez le fournisseur' })).toHaveAttribute('referrerpolicy', 'no-referrer')
})
it('ne propose aucune lecture sans les deux permissions nécessaires', () => {
  render(<ContextMedia active={{ ...active, context: { ...active.context!, permissions: ['media.read'] } }} type="event" id={id} disabled={false} onInvalid={vi.fn()} />)
  expect(screen.queryByRole('button')).not.toBeInTheDocument()
  expect(request).not.toHaveBeenCalled()
})
