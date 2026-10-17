import { useState } from 'react'
import type { ActiveOrganization } from '../../api/tenancy'
import { availabilityText as text, type AvailabilityRevision } from '../../api/availability'
import { calendarDate as date } from '../../api/calendar'
import { usePartnershipList } from '../partnerships/usePartnershipList'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { Button } from '../Button'

export function AvailabilityHistory({ active, path, timezone, disabled, onInvalid, onClose }: {
  active: ActiveOrganization; path: string; timezone: string; disabled: boolean; onInvalid: () => void; onClose: () => void
}) {
  const [page, setPage] = useState(1)
  const { list, loading, error } = usePartnershipList<AvailabilityRevision>(path + '/history?page=' + page + '&per_page=10', active, true, onInvalid)
  return <section aria-label={text('historyTitle')} className="grid min-w-0 gap-3 rounded-xl border border-slate-700 p-3">
    <h3 className="font-bold">{text('historyTitle')}</h3>
    {loading && <p role="status">{text('loading')}</p>}
    {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
    <ol className="grid min-w-0 gap-3">{list?.data.map((item) => <li key={item.id} className="grid min-w-0 gap-1 border-b border-slate-700 pb-3">
      <p>{text('revision')} {item.number} · {text('action.' + item.action)} · {date(item.created_at, timezone)}</p>
      <p>{text('status.' + item.status)} · {date(item.starts_at, timezone)} → {date(item.ends_at, timezone)}</p>
      <p className="text-sm text-slate-400 [overflow-wrap:anywhere]">{text('origin')} : {item.origin_timezone} · {date(item.starts_at, item.origin_timezone)} → {date(item.ends_at, item.origin_timezone)}</p>
      {item.comment && <p className="whitespace-pre-wrap [overflow-wrap:anywhere]">{item.comment}</p>}
    </li>)}</ol>
    {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={disabled || loading} onPageChange={setPage} />}
    <Button disabled={disabled} onClick={onClose}>{text('closeHistory')}</Button>
  </section>
}
