import { calendarDate } from '../../api/calendar'
import type { ActiveOrganization } from '../../api/tenancy'
import type { MatchRecord, MatchLiveScore } from '../../api/matches'
import { matchText as text } from '../../api/matches'
import { useMatchRealtime } from '../../realtime/useMatchRealtime'
import { useEffect, useState } from 'react'
import { tenancyRequest } from '../../api/tenancy'
import { apiError, shouldReloadContext } from '../people/peopleFormValues'
import { Alert } from '../feedback/Alert'
import { Button } from '../Button'

type Props = { active: ActiveOrganization; item: MatchRecord; root: string; onInvalid: () => void; onClose: () => void }
export function MatchLiveScores({ active, item, root, onInvalid, onClose }: Props) {
  const [snapshot, setSnapshot] = useState<MatchLiveScore | null>(null)
  const [error, setError] = useState<ReturnType<typeof apiError> | null>(null)
  const [loading, setLoading] = useState(true)
  const [latency, setLatency] = useState<number | null>(null)
  const { refresh, changedAt, connected } = useMatchRealtime(active, item.id, !error || !shouldReloadContext(error))
  const revision = active.revision ?? undefined
  useEffect(() => {
    const controller = new AbortController()
    const load = async () => {
      setLoading(true); setSnapshot(null); setError(null)
      await tenancyRequest<{ data: MatchLiveScore }>(root + '/' + item.id + '/live', { revision, signal: controller.signal })
        .then((response) => { if (!controller.signal.aborted) { setSnapshot(response.data); setLatency(changedAt === null ? null : Math.max(0, Date.now() - changedAt)) } })
        .catch((failure: unknown) => {
          if (controller.signal.aborted) return
          const next = apiError(failure); setError(next)
          if (shouldReloadContext(next)) onInvalid()
        }).finally(() => { if (!controller.signal.aborted) setLoading(false) })
    }
    void load()
    return () => controller.abort()
  }, [root, item.id, revision, refresh, changedAt, onInvalid])
  const card = !error && !loading ? snapshot : null
  return <section aria-label={text('live.title')} className="grid min-w-0 gap-3 rounded-xl border border-cyan-400/30 p-3">
    <h3 className="font-bold [overflow-wrap:anywhere]">{text('live.title')} · {item.title}</h3>
    <p role="status">{text(error && shouldReloadContext(error) ? 'live.denied' : connected ? 'live.connected' : 'live.fallback')}</p>
    {card && <p>{text('status.' + card.status)}{' · ' + text(card.status === 'validated' ? 'live.validated' : 'live.provisional')}</p>}
    <Button onClick={onClose}>{text('live.close')}</Button>
    {loading && <p role="status">{text('loading')}</p>}
    {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
    {card?.recalculation_pending && <p role="status">{text('corrections.pending')}</p>}
    {card?.outcome && <p>{text('results.outcome.' + card.outcome)} · {card.winner_ids.map((id) => item.participants.find((participant) => participant.id === id)?.label ?? text('live.participant')).join(', ')}</p>}
    {card?.series && <ul>{card.series.standings.map((row) => <li key={row.participant_id} className="[overflow-wrap:anywhere]">{item.participants.find((participant) => participant.id === row.participant_id)?.label ?? text('live.participant')} : {row.score}</li>)}</ul>}
    {card && !card.series && !card.recalculation_pending && <p>{text('live.empty')}</p>}
    {card && <p className="text-sm text-slate-400">{text('live.observed')} {calendarDate(card.observed_at, active.context!.timezone)}{latency !== null ? ` · ${text('live.latency')} : ${latency} ms (${text('live.clocks')}).` : ''}</p>}
  </section>
}
