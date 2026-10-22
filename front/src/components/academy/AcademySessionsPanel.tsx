import { useState } from 'react'
import type { ActiveOrganization } from '../../api/tenancy'
import type { Cohort, AcademyCycle } from '../../api/academy'
import { type AcademySession, sessionsPath, sessionText as text } from '../../api/academySessions'
import { Button } from '../Button'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { usePeopleMutation } from '../people/usePeopleMutation'
import { useAcademyPage } from './useAcademyPage'
import { AcademySessionForm } from './AcademySessionForm'
import { AcademyAttendancePanel } from './AcademyAttendancePanel'
import { SessionTextArea } from './SessionTextArea'

type Props = { active: ActiveOrganization; cohort: Cohort; cycle: AcademyCycle; disabled: boolean; onInvalid: () => void }
export function AcademySessionsPanel({ active, cohort, cycle, disabled, onInvalid }: Props) {
  const org = active.context?.organization_id ?? '', base = sessionsPath(org, cohort.id, cycle.id)
  const allowed = active.context?.permissions.includes('academy.read') ?? false
  const manage = (active.context?.permissions.includes('academy.manage') ?? false) && cohort.status === 'active' && cycle.status === 'active'
  const calendar = active.context?.permissions.includes('calendar.read') ?? false
  const [open, setOpen] = useState(false), [page, setPage] = useState(1), [refresh, setRefresh] = useState(0)
  const [editor, setEditor] = useState<AcademySession | 'new' | null>(null), [cancelling, setCancelling] = useState<AcademySession | null>(null), [selectedId, setSelectedId] = useState<string | null>(null)
  const read = useAcademyPage<AcademySession>(active, open && allowed ? base : null, page, refresh, onInvalid)
  const mutation = usePeopleMutation<AcademySession>(active, () => { setEditor(null); setCancelling(null); setSelectedId(null); setRefresh((value) => value + 1) }, onInvalid)
  const blocked = disabled || read.loading || mutation.blocked
  const selected = read.result?.data.find((item) => item.id === selectedId)
  if (!org || !allowed) return null
  return <section aria-label={text('panel')} className="grid min-w-0 gap-4 border-t border-slate-700 pt-4">
    <Button disabled={disabled || mutation.blocked} onClick={() => { setOpen((value) => !value); setEditor(null); setSelectedId(null); setCancelling(null) }}>{open ? text('close') : text('panel')}</Button>
    {open && <>
      <h4 className="font-bold [overflow-wrap:anywhere]">{cycle.name}</h4><p>{text('hint')}</p>
      {read.loading && <p role="status">{text('loading')}</p>}{read.error && <><Alert tone="error" title={read.error.message} /><Button onClick={() => setRefresh((value) => value + 1)}>{text('retry')}</Button></>}
      {mutation.feedback}
      {manage && calendar && <Button disabled={blocked} onClick={() => { setEditor('new'); setCancelling(null); setSelectedId(null) }}>{text('create')}</Button>}
      {read.result?.data.length === 0 && <p>{text('empty')}</p>}
      <ul className="grid min-w-0 gap-3">{read.result?.data.map((item) => <li key={item.id} className="grid min-w-0 gap-3 rounded-xl border border-slate-700 p-3 [overflow-wrap:anywhere]">
        <h5 className="font-bold">{item.title}</h5><p>{text(item.status)} · {item.responsible_label ?? text('personUnavailable')}</p>
        {item.starts_at && <p>{text('retainedPeriod')} : {item.starts_at} – {item.ends_at}</p>}
        {item.activity && <p>{text('activity')} : {item.activity.label} · {item.activity.timezone}</p>}
        {item.schedule_state !== 'current' && <Alert tone="warning" title={text(item.schedule_state === 'changed' ? 'scheduleChanged' : 'scheduleUnavailable')} />}
        <ul>{item.exercises.map((exercise, index) => <li key={index} className="whitespace-pre-wrap">{exercise.title} · {exercise.minutes} min{exercise.instructions && <p>{exercise.instructions}</p>}</li>)}</ul>
        {item.report && <p className="whitespace-pre-wrap">{item.report}</p>}
        <Button disabled={blocked} onClick={() => { setSelectedId(item.id); setEditor(null); setCancelling(null) }}>{text('follow')} {item.title}</Button>
        {manage && item.status !== 'cancelled' && <>
          {calendar && item.activity && <Button disabled={blocked} onClick={() => { setEditor(item); setSelectedId(null); setCancelling(null) }}>{text('edit')} {item.title}</Button>}
          <Button disabled={blocked} onClick={() => { setCancelling(item); setEditor(null); setSelectedId(null) }}>{text('cancel')} {item.title}</Button>
        </>}
      </li>)}</ul>
      {read.result && <Pagination currentPage={read.result.meta.current_page} totalPages={read.result.meta.last_page} disabled={blocked} onPageChange={(next) => { setPage(next); setEditor(null); setSelectedId(null); setCancelling(null) }} />}
      {editor && manage && calendar && <AcademySessionForm key={editor === 'new' ? 'new' : editor.id + editor.version} active={active} cohort={cohort} cycle={cycle} session={editor === 'new' ? null : editor} disabled={blocked} onInvalid={onInvalid} errors={mutation.errors(base + (editor === 'new' ? '' : '/' + editor.id))} onCancel={() => setEditor(null)} onSubmit={(event, body) => mutation.propose(event, text('save'), base + (editor === 'new' ? '' : '/' + editor.id), body, editor !== 'new')} />}
      {cancelling && <form aria-label={text('cancel')} className="grid min-w-0 gap-3" onSubmit={(event) => mutation.propose(event, text('cancel'), base + '/' + cancelling.id, { version: cancelling.version, status: 'cancelled', change_reason: String(new FormData(event.currentTarget).get('change_reason')) }, true)}><SessionTextArea label={text('reason')} name="change_reason" required minLength={10} maxLength={1000} disabled={blocked} /><Button type="submit" disabled={blocked}>{text('cancel')}</Button></form>}
      {selected && <AcademyAttendancePanel key={selected.id + selected.version} active={active} cohort={cohort} cycle={cycle} session={selected} disabled={blocked} onInvalid={onInvalid} onSaved={() => setRefresh((value) => value + 1)} />}
    </>}
  </section>
}
