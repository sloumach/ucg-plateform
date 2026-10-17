import { useState } from 'react'
import type { ActiveOrganization } from '../../api/tenancy'
import { calendarKinds, calendarText as text, calendarDate as date, safeCalendarUrl, type CalendarActivity, type CalendarPage } from '../../api/calendar'
import { defaultCalendarWindow } from '../../api/calendarTime'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { AsyncState } from '../feedback/AsyncState'
import { SelectField } from '../people/peopleForms'
import { usePeopleMutation } from '../people/usePeopleMutation'
import { usePartnershipList } from '../partnerships/usePartnershipList'
import { CalendarForm } from './CalendarForm'
import { CalendarResourcesPanel } from './CalendarResourcesPanel'
import { bookingText } from '../../api/calendarConflicts'
import { CalendarConflictHistory } from './CalendarConflictHistory'
import { CalendarInvitationsPanel } from './CalendarInvitationsPanel'
type Props = { active: ActiveOrganization; disabled: boolean; onInvalid: () => void }
export function CalendarPanel({ active, disabled, onInvalid }: Props) {
  const [open, setOpen] = useState(false), [page, setPage] = useState(1), [refresh, setRefresh] = useState(0)
  const [filters, setFilters] = useState(() => ({ ...defaultCalendarWindow(), timezone: '', kind: '', management: false, archived: false }))
  const [editor, setEditor] = useState<CalendarActivity | 'new' | null>(null)
  const [history, setHistory] = useState<string | null>(null)
  const permissions = active.context?.permissions ?? [], canRead = permissions.includes('calendar.read'), canWrite = permissions.includes('calendar.write')
  const root = 'tenants/' + active.context!.organization_id + '/calendar'
  const params = new URLSearchParams({ from_date: filters.from, to_date: filters.to, page: String(page), per_page: '10',
    ...(filters.timezone ? { timezone: filters.timezone } : {}), ...(filters.kind ? { kind: filters.kind } : {}),
    ...(filters.management ? { management: '1', ...(filters.archived ? { archived: '1' } : {}) } : {}) })
  const { list, loading, error } = usePartnershipList<CalendarActivity, CalendarPage>(root + '/activities?' + params, active, open && canRead, onInvalid, refresh)
  const mutation = usePeopleMutation<CalendarActivity>(active, () => { setEditor(null); setRefresh((n) => n + 1) }, onInvalid)
  const blocked = disabled || mutation.blocked, timezone = list?.calendar.display_timezone ?? (filters.timezone || active.context!.timezone)
  if (!canRead) return null
  return <section aria-label={text('title')} className="grid min-w-0 gap-4 rounded-2xl border border-white/10 p-4">
    <h2 className="text-xl font-bold">{text('title')}</h2><p className="text-sm text-slate-400">{text('hint')}</p>
    <Button disabled={blocked} onClick={() => { setOpen(!open); setEditor(null); setHistory(null) }}>{text(open ? 'close' : 'open')}</Button>
    {open && <>
      {mutation.feedback}
      <CalendarInvitationsPanel {...{ active, root, onInvalid }} disabled={blocked} />
      {canWrite && <CalendarResourcesPanel {...{ active, root, onInvalid }} disabled={blocked} />}
      <form aria-label={text('filter')} className="grid min-w-0 gap-3 sm:grid-cols-2" onSubmit={(event) => {
        event.preventDefault(); const values = new FormData(event.currentTarget)
        setFilters({ from: String(values.get('from_date') ?? ''), to: String(values.get('to_date') ?? ''), timezone: String(values.get('timezone') ?? '').trim(),
          kind: String(values.get('kind') ?? ''), management: canWrite && values.get('management') === '1', archived: values.has('archived') })
        setPage(1); setEditor(null)
      }}>
        <FormField name="from_date" label={text('fromDate')} type="date" defaultValue={filters.from} required disabled={blocked} error={error?.fieldErrors.from_date?.[0]} />
        <FormField name="to_date" label={text('toDate')} type="date" defaultValue={filters.to} required disabled={blocked} error={error?.fieldErrors.to_date?.[0]} />
        <FormField name="timezone" label={text('displayTimezone')} defaultValue={filters.timezone} placeholder={list?.calendar.viewer_timezone ?? active.context!.timezone} disabled={blocked} error={error?.fieldErrors.timezone?.[0]} />
        <SelectField name="kind" title={text('kind')} defaultValue="" disabled={blocked}><option value="">{text('allKinds')}</option>{calendarKinds.map((key) => <option key={key} value={key}>{text('kind.' + key)}</option>)}</SelectField>
        {canWrite && <><SelectField name="management" title={text('mode')} defaultValue="0" disabled={blocked}><option value="0">{text('myCalendar')}</option><option value="1">{text('management')}</option></SelectField>
          <FormField name="archived" label={text('archivedFilter')} type="checkbox" disabled={blocked} /></>}
        <p className="text-sm text-slate-400 sm:col-span-2">{text('windowHint')}</p>
        <div className="flex flex-wrap gap-3 sm:col-span-2"><Button type="submit" disabled={blocked}>{text('filterAction')}</Button><Button disabled={blocked} onClick={() => setRefresh((n) => n + 1)}>{text('refresh')}</Button></div>
      </form>
      <p className="text-sm text-cyan-200 [overflow-wrap:anywhere]">{text('displayedIn')} : {timezone}</p>
      {filters.management && <p className="text-sm text-amber-200">{text('managementHint')}</p>}
      {canWrite && <Button disabled={blocked} onClick={() => setEditor('new')}>{text('new')}</Button>}
      {loading && <AsyncState variant="loading" title={text('loading')} />}
      {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
      {list?.data.length === 0 && <p>{text('empty')}</p>}
      <ol className="grid min-w-0 gap-3">{list?.data.map((item) => <li key={item.id} className="grid min-w-0 gap-2 rounded-xl border border-slate-700 p-3">
        <h3 className="font-bold [overflow-wrap:anywhere]">{item.title}</h3>
        <p>{text('kind.' + item.kind)} · {text('visibility.' + item.visibility)}</p>
        <p className="[overflow-wrap:anywhere]">{date(item.starts_at, timezone)} → {date(item.ends_at, timezone)}</p>
        <p className="text-sm text-slate-400 [overflow-wrap:anywhere]">{text('origin')} : {item.origin_timezone} · {date(item.starts_at, item.origin_timezone)} → {date(item.ends_at, item.origin_timezone)}</p>
        {item.team_id && <p>{text('team')} : {item.team_label ?? text('currentTeam')}</p>}
        {item.description && <p className="whitespace-pre-wrap [overflow-wrap:anywhere]">{item.description}</p>}
        {item.location && <p className="[overflow-wrap:anywhere]">{text('location')} : {item.location}</p>}
        {safeCalendarUrl(item.url) && <a className="w-fit text-cyan-300 underline" href={safeCalendarUrl(item.url)!} target="_blank" rel="noopener noreferrer" referrerPolicy="no-referrer">{text('openLink')}</a>}
        <p className="text-sm text-slate-400">{text('participants')} : {item.participants.length}</p>
        {item.participants.length > 0 && <ul className="flex min-w-0 flex-wrap gap-2">{item.participants.map((person) => <li key={person.id} className="rounded-lg bg-slate-800 px-2 py-1 text-sm [overflow-wrap:anywhere]">{person.label ?? text('unavailablePerson')}</li>)}</ul>}
        {(item.resources ?? []).length > 0 && <p className="[overflow-wrap:anywhere]">{bookingText('resources')} : {item.resources!.map((resource) => resource.label ?? bookingText('unknownResource')).join(', ')}</p>}
        {item.archived_at && <p>{text('archived')}</p>}
        {canWrite && permissions.includes('calendar.invitations.manage') && <CalendarInvitationsPanel key={item.id + item.version} {...{ active, root, onInvalid }} activity={item} disabled={blocked} />}
        {canWrite && permissions.includes('availability.read') && <Button disabled={blocked} onClick={() => setHistory(item.id)}>{bookingText('history')}</Button>}
        {canWrite && !item.archived_at && <div className="flex flex-wrap gap-3"><Button disabled={blocked} onClick={() => setEditor(item)}>{text('edit')}</Button>
          <form onSubmit={(event) => mutation.propose(event, text('archive'), root + '/activities/' + item.id, { version: item.version, archive: true }, true)}><Button type="submit" disabled={blocked}>{text('archive')}</Button></form>
        </div>}
      </li>)}</ol>
      {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={blocked || loading} onPageChange={(next) => { setPage(next); setEditor(null) }} />}
      {editor && <CalendarForm key={editor === 'new' ? 'new' : editor.id + editor.version} {...{ active, root, onInvalid }} item={editor === 'new' ? null : editor}
        defaultTimezone={list?.calendar.viewer_timezone ?? active.context!.timezone} disabled={blocked} errors={mutation.errors(root + '/activities' + (editor === 'new' ? '' : '/' + editor.id))} onCancel={() => setEditor(null)}
        onSubmit={(event, values) => mutation.propose(event, text('save'), root + '/activities' + (editor === 'new' ? '' : '/' + editor.id), { ...values, ...(editor === 'new' ? {} : { version: editor.version }) }, editor !== 'new')} />}
      {history && <CalendarConflictHistory key={history} {...{ active, timezone, onInvalid }} path={root + '/activities/' + history} disabled={blocked} onClose={() => setHistory(null)} />}
      <p className="text-sm text-slate-400">{text('limits')}</p>
    </>}
  </section>
}
