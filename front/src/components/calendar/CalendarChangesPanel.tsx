import { useState } from 'react'
import type { ActiveOrganization } from '../../api/tenancy'
import { calendarDate } from '../../api/calendar'
import { changeText as text, type CalendarChange, type CalendarChangeNotice, type CalendarChangePage } from '../../api/calendarChanges'
import { Button } from '../Button'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { usePartnershipList } from '../partnerships/usePartnershipList'

type Props = { active: ActiveOrganization; root: string; disabled: boolean; onInvalid: () => void; activity?: string; timezone?: string; onClose?: () => void }
export function CalendarChangesPanel({ active, root, disabled, onInvalid, activity, timezone, onClose }: Props) {
  const [open, setOpen] = useState(Boolean(activity)), [page, setPage] = useState(1), [refresh, setRefresh] = useState(0)
  const permissions = active.context?.permissions ?? []
  const allowed = permissions.includes('calendar.read') && (!activity || permissions.includes('calendar.write'))
  const path = root + (activity ? '/activities/' + activity : '') + '/changes?page=' + page + '&per_page=10'
  const { list, loading, error } = usePartnershipList<CalendarChange | CalendarChangeNotice, CalendarChangePage>(path, active, open && allowed, onInvalid, refresh)
  const zone = timezone ?? active.context!.timezone
  if (!allowed) return null
  return <section aria-label={text(activity ? 'history' : 'inbox')} className="grid min-w-0 gap-3 rounded-xl border border-slate-700 p-3">
    <h3 className="font-bold">{text(activity ? 'history' : 'inbox')}</h3>
    <Button disabled={disabled} onClick={() => { if (open && onClose) onClose(); else setOpen(!open) }}>{text(open ? 'close' : 'open')}</Button>
    {open && <>
      <p className="text-sm text-slate-400">{text(activity ? 'historyHint' : 'inboxHint')}</p>
      <Button disabled={disabled || loading} onClick={() => setRefresh((n) => n + 1)}>{text('refresh')}</Button>
      {loading && <p role="status">{text('loading')}</p>}
      {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
      {list?.calendar_changes?.delivery_enabled === false && <p className="text-sm text-amber-200">{text('disabled')}</p>}
      {list?.data.length === 0 && <p>{text('empty')}</p>}
      <ol className="grid min-w-0 gap-3">{list?.data.map((row) => {
        const change = 'change' in row ? row.change : row
        return <li key={row.id} className="grid min-w-0 gap-2 rounded-lg border border-white/10 p-3">
          <h4 className="font-bold [overflow-wrap:anywhere]">{text(change.kind)} · {change.title}</h4>
          <p className="text-sm text-slate-400">{text('occurred')} : {calendarDate(change.occurred_at, zone)}</p>
          <p className="[overflow-wrap:anywhere]">{text('before')} : {calendarDate(change.before.starts_at, zone)} → {calendarDate(change.before.ends_at, zone)} ({change.before.origin_timezone})</p>
          {change.kind === 'rescheduled' && <p className="[overflow-wrap:anywhere]">{text('after')} : {calendarDate(change.after.starts_at, zone)} → {calendarDate(change.after.ends_at, zone)} ({change.after.origin_timezone})</p>}
          <p className="whitespace-pre-wrap [overflow-wrap:anywhere]">{text('reason')} : {change.reason}</p>
          {'change' in row && !row.current && <p className="text-sm text-amber-200">{text('historical')}</p>}
          {change.recipients_count !== undefined && <p className="text-sm text-slate-400">{text('recipients')} : {change.recipients_count} · {text('delivered')} : {change.delivered_count ?? 0} · {text('blocked')} : {change.blocked_count ?? 0}</p>}
        </li>
      })}</ol>
      {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={disabled || loading} onPageChange={setPage} />}
    </>}
  </section>
}