import { useEffect, useState } from 'react'
import type { ActiveOrganization } from '../api/tenancy'
import { getRealtimeClient, registerMatchAuthorization } from './echo'

type Change = { event_id: string; organization_id: string; match_id: string; changed_at: string }

export function useMatchRealtime(active: ActiveOrganization, matchId: string, enabled: boolean) {
  const [refresh, setRefresh] = useState(0)
  const [changedAt, setChangedAt] = useState<number | null>(null)
  const [connected, setConnected] = useState(false)
  const organizationId = active.context?.organization_id
  const actorId = active.context?.actor_user_id
  useEffect(() => {
    if (!enabled || !organizationId) return
    let alive = true
    let pending: ReturnType<typeof setTimeout> | undefined
    let lastChange: number | null = null
    const seen = new Set<string>()
    const reread = () => {
      if (pending || document.visibilityState === 'hidden') return
      pending = setTimeout(() => {
        pending = undefined
        if (alive) {
          setChangedAt(lastChange)
          lastChange = null
          setRefresh((value) => value + 1)
        }
      }, 250)
    }
    const name = `tenants.${organizationId}.matches.${matchId}`
    const unregister = registerMatchAuthorization(name, active.revision ?? undefined)
    const client = active.confirmed ? getRealtimeClient() : null
    const connection = client?.connector.pusher.connection
    const disconnected = () => { if (alive) setConnected(false) }
    connection?.bind('disconnected', disconnected)
    connection?.bind('unavailable', disconnected)
    const channel = client?.private(name)
    channel?.listen('.match.score.changed', (event: Partial<Change> | null) => {
      if (!alive || !event || event.organization_id !== organizationId || event.match_id !== matchId || typeof event.event_id !== 'string'
        || event.event_id.length > 100 || typeof event.changed_at !== 'string' || seen.has(event.event_id) || !Number.isFinite(Date.parse(event.changed_at))) return
      if (seen.size >= 200) seen.clear()
      seen.add(event.event_id)
      lastChange = Date.parse(event.changed_at)
      reread()
    }).subscribed(() => { if (alive) { setConnected(true); reread() } }).error(() => { if (alive) setConnected(false) })
    // Recover dropped events and permissions even when Reverb is unavailable.
    const interval = setInterval(reread, 30_000)
    const visible = () => { if (document.visibilityState !== 'hidden') reread() }
    window.addEventListener('online', reread)
    document.addEventListener('visibilitychange', visible)
    return () => {
      alive = false
      clearTimeout(pending)
      clearInterval(interval)
      window.removeEventListener('online', reread)
      document.removeEventListener('visibilitychange', visible)
      connection?.unbind('disconnected', disconnected)
      connection?.unbind('unavailable', disconnected)
      channel?.stopListening('.match.score.changed')
      client?.leave(name)
      unregister()
      setConnected(false)
    }
  }, [organizationId, actorId, active.revision, active.confirmed, matchId, enabled])
  return { refresh, changedAt, connected }
}
