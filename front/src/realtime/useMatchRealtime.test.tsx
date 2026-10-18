import { act, renderHook } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { ActiveOrganization } from '../api/tenancy'
import { useMatchRealtime } from './useMatchRealtime'

const fake = vi.hoisted(() => ({ listen: vi.fn(), subscribed: vi.fn(), error: vi.fn(), stopListening: vi.fn(), leave: vi.fn(), private: vi.fn(), unregister: vi.fn(), register: vi.fn(), client: vi.fn(), bind: vi.fn(), unbind: vi.fn() }))
vi.mock('./echo', () => ({ getRealtimeClient: fake.client, registerMatchAuthorization: fake.register }))
const active: ActiveOrganization = { context: { organization_id: 'tenant-a', actor_user_id: 42 } as ActiveOrganization['context'], revision: 'revision-a', confirmed: true }
function signal(event_id = 'one', organization_id = 'tenant-a') { return { event_id, organization_id, match_id: 'match-a', changed_at: new Date().toISOString() } }
beforeEach(() => {
  vi.useFakeTimers(); vi.resetAllMocks()
  const channel = { listen: fake.listen, subscribed: fake.subscribed, error: fake.error, stopListening: fake.stopListening }
  fake.listen.mockReturnValue(channel); fake.subscribed.mockReturnValue(channel); fake.error.mockReturnValue(channel)
  fake.private.mockReturnValue(channel); fake.client.mockReturnValue({ private: fake.private, leave: fake.leave, connector: { pusher: { connection: { bind: fake.bind, unbind: fake.unbind } } } }); fake.register.mockReturnValue(fake.unregister)
})
afterEach(() => { vi.useRealTimers() })

describe('abonnement aux scores', () => {
  it('groupe les signaux et ignore doublons, autre tenant et événement malformé', () => {
    const { result } = renderHook(() => useMatchRealtime(active, 'match-a', true))
    const notify = fake.listen.mock.calls[0][1]
    act(() => { notify(null); notify(signal()); notify(signal()); notify(signal('two')); notify(signal('foreign', 'tenant-b')); notify({ ...signal('bad'), changed_at: 'invalid' }); vi.advanceTimersByTime(250) })
    expect(result.current.refresh).toBe(1)
    act(() => { notify(signal()); vi.advanceTimersByTime(250) })
    expect(result.current.refresh).toBe(1)
    expect(fake.register).toHaveBeenCalledWith('tenants.tenant-a.matches.match-a', 'revision-a')
  })
  it('relit après abonnement et utilise le secours périodique sans Reverb', () => {
    fake.client.mockReturnValue(null)
    const { result } = renderHook(() => useMatchRealtime(active, 'match-a', true))
    act(() => { vi.advanceTimersByTime(30_250) })
    expect(result.current.refresh).toBe(1)
    expect(result.current.connected).toBe(false)
  })
  it('désabonne et annule les rappels à la fermeture ou au changement de contexte', () => {
    const { result, unmount, rerender } = renderHook(({ selection }) => useMatchRealtime(selection, 'match-a', true), { initialProps: { selection: active } })
    const oldNotify = fake.listen.mock.calls[0][1]
    rerender({ selection: { ...active, revision: 'revision-b' } })
    expect(fake.leave).toHaveBeenCalledWith('tenants.tenant-a.matches.match-a')
    expect(fake.unregister).toHaveBeenCalledOnce()
    act(() => { oldNotify(signal()); vi.advanceTimersByTime(250) })
    expect(result.current.refresh).toBe(0)
    unmount(); expect(fake.unregister).toHaveBeenCalledTimes(2)
    expect(vi.getTimerCount()).toBe(0)
  })
  it('rattrape les événements après réabonnement et retour en ligne', () => {
    const { result } = renderHook(() => useMatchRealtime(active, 'match-a', true))
    const subscribed = fake.subscribed.mock.calls[0][0]
    act(() => { subscribed(); vi.advanceTimersByTime(250) })
    expect(result.current.connected).toBe(true); expect(result.current.refresh).toBe(1)
    act(() => { subscribed(); window.dispatchEvent(new Event('online')); vi.advanceTimersByTime(250) })
    expect(result.current.refresh).toBe(2)
  })
  it('refuse un abonnement avant confirmation tout en conservant les lectures de secours', () => {
    const { result } = renderHook(() => useMatchRealtime({ ...active, confirmed: false }, 'match-a', true))
    expect(fake.client).not.toHaveBeenCalled()
    act(() => { vi.advanceTimersByTime(30_250) })
    expect(result.current.refresh).toBe(1)
  })
  it('suspend les relectures cachées et rattrape au retour visible', () => {
    const visibility = vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden')
    const { result } = renderHook(() => useMatchRealtime(active, 'match-a', true))
    act(() => { fake.listen.mock.calls[0][1](signal()); vi.advanceTimersByTime(30_250) })
    expect(result.current.refresh).toBe(0)
    visibility.mockReturnValue('visible')
    act(() => { document.dispatchEvent(new Event('visibilitychange')); vi.advanceTimersByTime(250) })
    expect(result.current.refresh).toBe(1)
    visibility.mockRestore()
  })
  it('signale une déconnexion et retire les écouteurs du transport à la fermeture', () => {
    const { result, unmount } = renderHook(() => useMatchRealtime(active, 'match-a', true))
    act(() => fake.subscribed.mock.calls[0][0]())
    expect(result.current.connected).toBe(true)
    act(() => fake.bind.mock.calls.find(([event]) => event === 'disconnected')![1]())
    expect(result.current.connected).toBe(false)
    unmount(); expect(fake.unbind).toHaveBeenCalledTimes(2)
  })

})
