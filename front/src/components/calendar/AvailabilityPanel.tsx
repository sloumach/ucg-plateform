import { useState } from 'react'
import type { ActiveOrganization } from '../../api/tenancy'
import { availabilityText as text, availabilityStatuses, type Availability, type AvailabilityPage } from '../../api/availability'
import { calendarDate as date, calendarText as calendar } from '../../api/calendar'
import { defaultCalendarWindow } from '../../api/calendarTime'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { SelectField } from '../people/peopleForms'
import { usePeopleMutation } from '../people/usePeopleMutation'
import { usePartnershipList } from '../partnerships/usePartnershipList'
import { AvailabilityForm } from './AvailabilityForm'
import { AvailabilityHistory } from './AvailabilityHistory'
import { AvailabilityPersonSelect } from './AvailabilityPersonSelect'

type Props = { active: ActiveOrganization; disabled: boolean; onInvalid: () => void }
export function AvailabilityPanel({ active, disabled, onInvalid }: Props) {
  const permissions = active.context?.permissions ?? [], canSelf = permissions.includes('availability.self'), canRead = permissions.includes('availability.read')
  const [open, setOpen] = useState(false), [page, setPage] = useState(1), [refresh, setRefresh] = useState(0)
  const [filters, setFilters] = useState(() => ({ ...defaultCalendarWindow(), timezone: '', status: '', collective: !canSelf && canRead, cancelled: false, person: '' }))
  const [person, setPerson] = useState(''), [collective, setCollective] = useState(!canSelf && canRead)
  const [editor, setEditor] = useState<Availability | 'new' | null>(null), [history, setHistory] = useState<string | null>(null)
  const root = 'tenants/' + active.context!.organization_id + '/calendar'
  const params = new URLSearchParams({ from_date: filters.from, to_date: filters.to, page: String(page), per_page: '10',
    ...(filters.timezone ? { timezone: filters.timezone } : {}), ...(filters.status ? { status: filters.status } : {}),
    ...(filters.collective ? { collective: '1', ...(filters.person ? { person_id: filters.person } : {}) } : {}), ...(filters.cancelled ? { cancelled: '1' } : {}) })
  const { list, loading, error } = usePartnershipList<Availability, AvailabilityPage>(root + '/availabilities?' + params, active, open && (canSelf || canRead), onInvalid, refresh)
  const mutation = usePeopleMutation<Availability>(active, () => { setEditor(null); setHistory(null); setRefresh((n) => n + 1) }, onInvalid)
  const blocked = disabled || mutation.blocked, meta = list?.availability, timezone = meta?.display_timezone ?? (filters.timezone || active.context!.timezone)
  const canCreate = meta && (meta.can_manage || (meta.can_self && meta.viewer_person_id !== null))
  if (!canSelf && !canRead) return null
  return <section aria-label={text('title')} className="grid min-w-0 gap-4 rounded-2xl border border-white/10 p-4">
    <h2 className="text-xl font-bold">{text('title')}</h2><p className="text-sm text-slate-400">{text('hint')}</p>
    <Button disabled={blocked} onClick={() => { setOpen(!open); setEditor(null); setHistory(null) }}>{text(open ? 'close' : 'open')}</Button>
    {open && <>
      {mutation.feedback}
      <form aria-label={text('filter')} className="grid min-w-0 gap-3 sm:grid-cols-2" onSubmit={(event) => {
        event.preventDefault(); const values = new FormData(event.currentTarget)
        setFilters({ from: String(values.get('from_date') ?? ''), to: String(values.get('to_date') ?? ''), timezone: String(values.get('timezone') ?? '').trim(),
          status: String(values.get('status') ?? ''), collective, person: collective ? person : '', cancelled: values.has('cancelled') })
        setPage(1); setEditor(null); setHistory(null)
      }}>
        <FormField name="from_date" label={calendar('fromDate')} type="date" defaultValue={filters.from} required disabled={blocked} error={error?.fieldErrors.from_date?.[0]} />
        <FormField name="to_date" label={calendar('toDate')} type="date" defaultValue={filters.to} required disabled={blocked} error={error?.fieldErrors.to_date?.[0]} />
        <FormField name="timezone" label={calendar('displayTimezone')} defaultValue={filters.timezone} placeholder={meta?.viewer_timezone ?? active.context!.timezone} disabled={blocked} error={error?.fieldErrors.timezone?.[0]} />
        <SelectField name="status" title={text('status')} defaultValue="" disabled={blocked}><option value="">{text('allStatuses')}</option>{availabilityStatuses.map((status) => <option key={status} value={status}>{text('status.' + status)}</option>)}</SelectField>
        {canRead && <SelectField name="collective" title={text('mode')} value={collective ? '1' : '0'} disabled={blocked} onChange={(value) => setCollective(value === '1')}>
          {canSelf && <option value="0">{text('mine')}</option>}<option value="1">{text('collective')}</option>
        </SelectField>}
        {collective && canRead && <AvailabilityPersonSelect {...{ active, root, onInvalid }} disabled={blocked} value={person} onChange={setPerson} error={error?.fieldErrors.person_id?.[0]} />}
        <FormField name="cancelled" label={text('cancelledFilter')} type="checkbox" disabled={blocked} />
        <p className="text-sm text-slate-400 sm:col-span-2">{calendar('windowHint')}</p>
        <div className="flex flex-wrap gap-3 sm:col-span-2"><Button type="submit" disabled={blocked}>{calendar('filterAction')}</Button><Button disabled={blocked} onClick={() => setRefresh((n) => n + 1)}>{text('refresh')}</Button></div>
      </form>
      <p className="text-sm text-cyan-200 [overflow-wrap:anywhere]">{calendar('displayedIn')} : {timezone}</p>
      {meta?.can_self && !meta.viewer_person_id && <p className="text-sm text-amber-200">{text('missingPerson')}</p>}
      {canCreate && <Button disabled={blocked || loading} onClick={() => { setEditor('new'); setHistory(null) }}>{text('new')}</Button>}
      {loading && <p role="status">{text('loading')}</p>}
      {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
      {list?.data.length === 0 && <p>{text('empty')}</p>}
      <ol className="grid min-w-0 gap-3">{list?.data.map((item) => <li key={item.id} className="grid min-w-0 gap-2 rounded-xl border border-slate-700 p-3">
        <h3 className="font-bold [overflow-wrap:anywhere]">{item.person_label ?? text('unknownPerson')} · {text('status.' + item.status)}</h3>
        <p className="[overflow-wrap:anywhere]">{date(item.starts_at, timezone)} → {date(item.ends_at, timezone)}</p>
        <p className="text-sm text-slate-400 [overflow-wrap:anywhere]">{text('origin')} : {item.origin_timezone} · {date(item.starts_at, item.origin_timezone)} → {date(item.ends_at, item.origin_timezone)}</p>
        {item.comment && <p className="whitespace-pre-wrap [overflow-wrap:anywhere]">{item.comment}</p>}
        {item.cancelled_at && <p>{text('cancelled')}</p>}
        <div className="flex flex-wrap gap-3">
          {item.can_edit && <Button disabled={blocked || loading} onClick={() => { setEditor(item); setHistory(null) }}>{text('edit')}</Button>}
          {item.can_cancel && <form onSubmit={(event) => mutation.propose(event, text('cancel'), root + '/availabilities/' + item.id, { version: item.version, cancel: true }, true)}><Button type="submit" disabled={blocked || loading}>{text('cancel')}</Button></form>}
          {item.can_history && <Button disabled={blocked || loading} onClick={() => { setHistory(item.id); setEditor(null) }}>{text('history')}</Button>}
        </div>
      </li>)}</ol>
      {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={blocked || loading} onPageChange={(next) => { setPage(next); setEditor(null); setHistory(null) }} />}
      {history && <AvailabilityHistory key={history} {...{ active, timezone, onInvalid }} disabled={blocked} path={root + '/availabilities/' + history} onClose={() => setHistory(null)} />}
      {editor && meta && <AvailabilityForm key={editor === 'new' ? 'new' : editor.id + editor.version} {...{ active, root, onInvalid }}
        item={editor === 'new' ? null : editor} disabled={blocked} viewerId={meta.viewer_person_id} defaultTimezone={meta.viewer_timezone} canSelf={meta.can_self} canManage={meta.can_manage}
        errors={mutation.errors(root + '/availabilities' + (editor === 'new' ? '' : '/' + editor.id))} onCancel={() => setEditor(null)}
        onSubmit={(event, values) => mutation.propose(event, text('save'), root + '/availabilities' + (editor === 'new' ? '' : '/' + editor.id), { ...values, ...(editor === 'new' ? {} : { version: editor.version }) }, editor !== 'new')} />}
      <p className="text-sm text-slate-400">{text('limits')}</p>
    </>}
  </section>
}
