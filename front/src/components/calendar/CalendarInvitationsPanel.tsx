import { useState } from 'react'
import type { ActiveOrganization } from '../../api/tenancy'
import type { CalendarActivity } from '../../api/calendar'
import { calendarDate } from '../../api/calendar'
import { invitationText as text, type CalendarInvitation, type CalendarInvitationDelivery, type CalendarInvitationPage } from '../../api/calendarInvitations'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { AsyncState } from '../feedback/AsyncState'
import { SelectField } from '../people/peopleForms'
import { usePeopleMutation } from '../people/usePeopleMutation'
import { usePartnershipList } from '../partnerships/usePartnershipList'

type Props = { active: ActiveOrganization; root: string; disabled: boolean; onInvalid: () => void; activity?: CalendarActivity }
export function CalendarInvitationsPanel({ active, root, disabled, onInvalid, activity }: Props) {
  const [open, setOpen] = useState(false), [page, setPage] = useState(1), [refresh, setRefresh] = useState(0), [history, setHistory] = useState<string | null>(null)
  const permissions = active.context?.permissions ?? []
  const canManage = permissions.includes('calendar.write') && permissions.includes('calendar.invitations.manage')
  const canRespond = permissions.includes('calendar.invitations.respond')
  const path = activity ? root + '/activities/' + activity.id + '/invitations' : root + '/invitations'
  const mutation = usePeopleMutation(active, () => { setPage(1); setRefresh((value) => value + 1) }, onInvalid)
  const { list, loading, error } = usePartnershipList<CalendarInvitation, CalendarInvitationPage>(path + '?page=' + page + '&per_page=10', active, open && permissions.includes('calendar.read') && (!activity || canManage), onInvalid, refresh)
  const blocked = disabled || mutation.blocked, timezone = active.context!.timezone
  if (!permissions.includes('calendar.read') || (activity && !canManage)) return null
  const currentIssued = list?.data.some((item) => item.activity_version === activity?.version)
  return <section aria-label={activity ? text('management') : text('inbox')} className="grid min-w-0 gap-3 rounded-xl border border-slate-700 p-3">
    <h3 className="font-bold">{activity ? text('management') : text('inbox')}</h3>
    <Button disabled={blocked} onClick={() => { setOpen(!open); setHistory(null) }}>{text(open ? 'close' : 'open')}</Button>
    {open && <>
      <p className="text-sm text-slate-400">{text('hint')}</p>
      {mutation.feedback}
      {list?.calendar_invitations?.delivery_enabled === false && <Alert tone="warning" title={text('deliveryDisabled')} />}
      <Button disabled={blocked || loading} onClick={() => setRefresh((value) => value + 1)}>{text('refresh')}</Button>
      {loading && <AsyncState variant="loading" title={text('loading')} />}
      {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
      {activity && page === 1 && list && !loading && !error && !currentIssued && !activity.archived_at && <form aria-label={text('issue')} className="grid min-w-0 gap-3 sm:grid-cols-2" onSubmit={(event) => {
        const data = new FormData(event.currentTarget), deadline = String(data.get('response_deadline') ?? '')
        const offsets = ['offset1', 'offset2', 'offset3'].map((name) => String(data.get(name) ?? '')).filter(Boolean).map(Number)
        mutation.propose(event, text('issue'), path, { activity_version: activity.version,
          response_deadline: deadline.length === 16 ? deadline + ':00+00:00' : deadline + '+00:00', reminder_offsets: offsets, confirm_invitations: data.has('confirm_invitations') })
      }}>
        <p className="text-sm text-slate-300 sm:col-span-2">{text('issueHint')}</p>
        <FormField name="response_deadline" type="datetime-local" label={text('deadlineUtc')} required disabled={blocked}
          defaultValue={new Date(Date.parse(activity.starts_at) - 3600000).toISOString().slice(0, 16)} error={mutation.errors(path).response_deadline?.[0]} />
        {[1, 2, 3].map((index) => <FormField key={index} name={'offset' + index} label={text('offset') + ' ' + index} type="number" min={1} max={10080} step={1}
          defaultValue={index === 1 ? 60 : undefined} disabled={blocked} />)}
        <p className="text-sm text-slate-400 sm:col-span-2">{text('offsetHint')}</p>
        {Object.entries(mutation.errors(path)).filter(([key]) => key !== 'response_deadline').map(([key, messages]) => <p key={key} role="alert" className="text-sm text-red-200 sm:col-span-2">{messages[0]}</p>)}
        <FormField name="confirm_invitations" type="checkbox" label={text('confirm')} required disabled={blocked} />
        <Button type="submit" disabled={blocked || activity.participants.length === 0}>{text('issue')}</Button>
      </form>}
      {currentIssued && activity && <p className="text-sm text-slate-400">{text('issuedHint')}</p>}
      {list?.data.length === 0 && <p>{text('empty')}</p>}
      <ol className="grid min-w-0 gap-3">{list?.data.map((item) => <li key={item.id} className="grid min-w-0 gap-2 rounded-xl border border-white/10 p-3">
        <h4 className="font-bold [overflow-wrap:anywhere]">{item.title}</h4>
        {activity && <p className="[overflow-wrap:anywhere]">{item.person_label ?? text('unknownPerson')}</p>}
        <p className="[overflow-wrap:anywhere]">{text('activityTime')} : {calendarDate(item.starts_at, timezone)}</p>
        <p className="[overflow-wrap:anywhere]">{text('deadline')} : {calendarDate(item.response_deadline, timezone)}</p>
        <p>{text('response')} : {text(item.response)}</p>
        <p>{!item.current ? text('obsolete') : item.expired ? text('expired') : item.blocked_reason ? text(item.blocked_reason) : item.sent_at ? text('delivered') : text('queued')}</p>
        <p>{text('deliveries')} : {item.delivery_count}</p>
        {item.can_respond && canRespond && <form aria-label={text('answer')} className="grid min-w-0 gap-3 sm:grid-cols-2" onSubmit={(event) => {
          const data = new FormData(event.currentTarget)
          mutation.propose(event, text('answer'), root + '/invitations/' + item.id, { version: item.version, response: data.get('response') }, true)
        }}>
          <SelectField name="response" title={text('response')} defaultValue={item.response} disabled={blocked}>
            {(['pending', 'present', 'absent'] as const).map((value) => <option key={value} value={value}>{text(value)}</option>)}
          </SelectField>
          <Button type="submit" disabled={blocked}>{text('answer')}</Button>
        </form>}
        <Button disabled={blocked} onClick={() => setHistory(history === item.id ? null : item.id)}>{text('history')}</Button>
        {history === item.id && <InvitationHistory key={item.id} {...{ active, root, timezone, onInvalid }} id={item.id} disabled={blocked} />}
      </li>)}</ol>
      {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={blocked || loading} onPageChange={(value) => { setPage(value); setHistory(null) }} />}
    </>}
  </section>
}
function InvitationHistory({ active, root, timezone, onInvalid, id, disabled }: { active: ActiveOrganization; root: string; timezone: string; onInvalid: () => void; id: string; disabled: boolean }) {
  const [page, setPage] = useState(1)
  const { list, loading, error } = usePartnershipList<CalendarInvitationDelivery>(root + '/invitations/' + id + '/deliveries?page=' + page + '&per_page=10', active, true, onInvalid, 0)
  return <div aria-label={text('history')} className="grid min-w-0 gap-2">
    {loading && <AsyncState variant="loading" title={text('loading')} />}
    {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
    {list?.data.length === 0 && <p>{text('noDelivery')}</p>}
    <ol className="grid min-w-0 gap-2">{list?.data.map((entry) => <li key={entry.id} className="[overflow-wrap:anywhere]">
      {text(entry.kind)} · {calendarDate(entry.created_at, timezone)}{entry.kind === 'reminder' && <> · {entry.offset_minutes} {text('minutes')}</>}
    </li>)}</ol>
    {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={disabled || loading} onPageChange={setPage} />}
  </div>
}
