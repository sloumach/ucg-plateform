import { useState } from 'react'
import type { ActiveOrganization } from '../../api/tenancy'
import { bookingText as text, type CalendarConflictOverride } from '../../api/calendarConflicts'
import { calendarDate as date } from '../../api/calendar'
import { usePartnershipList } from '../partnerships/usePartnershipList'
import { Pagination } from '../data/Pagination'
import { Button } from '../Button'
import { Alert } from '../feedback/Alert'
export function CalendarConflictHistory({ active, path, timezone, disabled, onInvalid, onClose }: {
  active: ActiveOrganization; path: string; timezone: string; disabled: boolean; onInvalid: () => void; onClose: () => void
}) {
  const [page, setPage] = useState(1)
  const { list, loading, error } = usePartnershipList<CalendarConflictOverride>(path + '/conflict-overrides?page=' + page + '&per_page=10', active, true, onInvalid)
  return <section aria-label={text('historyTitle')} className="grid min-w-0 gap-3 rounded-xl border border-slate-700 p-3">
    <h3 className="font-bold">{text('historyTitle')}</h3>
    {loading && <p role="status">{text('loading')}</p>}{error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
    {list?.data.length === 0 && <p>{text('emptyHistory')}</p>}
    <ol className="grid min-w-0 gap-3">{list?.data.map((item) => <li key={item.id} className="grid min-w-0 gap-1 border-b border-slate-700 pb-2">
      <p>{date(item.created_at, timezone)} · {text('found')} : {item.conflict_count}</p><p className="whitespace-pre-wrap [overflow-wrap:anywhere]">{item.reason}</p>
    </li>)}</ol>
    {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={disabled || loading} onPageChange={setPage} />}
    <Button disabled={disabled} onClick={onClose}>{text('closeHistory')}</Button>
  </section>
}
