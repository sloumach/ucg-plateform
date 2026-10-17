import { calendarDate as date } from '../../api/calendar'
import { bookingText as text, type CalendarConflictReport, type CalendarBookingResource } from '../../api/calendarConflicts'
import type { CalendarReference } from '../../api/calendar'
export function CalendarConflictResult({ report, timezone, people, resources }: { report: CalendarConflictReport; timezone: string; people: CalendarReference[]; resources: CalendarBookingResource[] }) {
  return <section aria-label={text('report')} className="grid min-w-0 gap-3 rounded-xl border border-amber-300/30 p-3">
    <h3 className="font-bold">{text('report')}</h3>
    {!report.availability_checked && <p className="text-sm text-amber-200">{text('availabilityUnchecked')}</p>}
    {report.truncated && <p role="alert">{text('truncated')}</p>}
    {report.conflicts.length === 0 ? <p>{text('clear')}</p> : <p>{text('found')} : {report.conflicts.length}{report.truncated ? '+' : ''}</p>}
    <ol className="grid min-w-0 gap-3">{report.conflicts.map((conflict) => <li key={conflict.type + conflict.id} className="grid min-w-0 gap-1 border-b border-slate-700 pb-2">
      <p className="font-semibold [overflow-wrap:anywhere]">{conflict.title ?? text(conflict.type)}</p>
      <p className="[overflow-wrap:anywhere]">{date(conflict.starts_at, timezone)} → {date(conflict.ends_at, timezone)}</p>
      <p>{conflict.causes.map((cause) => text('cause.' + cause)).join(' · ')}</p>
      {conflict.person_ids.map((id) => <p key={id} className="[overflow-wrap:anywhere]">{people.find((person) => person.id === id)?.label ?? text('registeredPerson')}</p>)}
      {conflict.resource_ids.map((id) => <p key={id} className="[overflow-wrap:anywhere]">{resources.find((resource) => resource.id === id)?.label ?? text('unknownResource')}</p>)}
    </li>)}</ol>
    <p className="text-sm text-slate-400">{text('reviewHint')}</p>
    {report.conflicts.length > 0 && !report.can_override && <p role="alert">{text('overrideDenied')}</p>}
  </section>
}
