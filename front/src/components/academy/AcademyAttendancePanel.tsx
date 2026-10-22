import { useState, type FormEvent } from 'react'
import type { ActiveOrganization } from '../../api/tenancy'
import type { Cohort, AcademyCycle, Enrollment } from '../../api/academy'
import { type AcademySession, type AcademyAttendance, type SessionRevision, sessionsPath, sessionText as text } from '../../api/academySessions'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { SelectField } from '../people/peopleForms'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { usePeopleMutation } from '../people/usePeopleMutation'
import { useAcademyPage } from './useAcademyPage'
import { SessionTextArea } from './SessionTextArea'

type Props = { active: ActiveOrganization; cohort: Cohort; cycle: AcademyCycle; session: AcademySession; disabled: boolean; onInvalid: () => void; onSaved: () => void }
export function AcademyAttendancePanel({ active, cohort, cycle, session, disabled, onInvalid, onSaved }: Props) {
  const org = active.context!.organization_id, base = sessionsPath(org, cohort.id, cycle.id) + '/' + session.id
  const canWrite = active.context!.permissions.includes('academy.attendance.manage') && active.context!.permissions.includes('calendar.read') && session.schedule_state === 'current' && session.status !== 'cancelled' && cohort.status === 'active' && cycle.status === 'active'
  const [page, setPage] = useState(1), [enrollmentPage, setEnrollmentPage] = useState(1), [historyPage, setHistoryPage] = useState(1), [history, setHistory] = useState(false), [editor, setEditor] = useState<AcademyAttendance | 'new' | null>(null)
  const read = useAcademyPage<AcademyAttendance>(active, base + '/attendances', page, 0, onInvalid)
  const enrollments = useAcademyPage<Enrollment>(active, editor === 'new' ? 'tenants/' + org + '/academy/cohorts/' + cohort.id + '/enrollments?cycle_id=' + cycle.id : null, enrollmentPage, 0, onInvalid)
  const revisions = useAcademyPage<SessionRevision>(active, history ? base + '/history' : null, historyPage, 0, onInvalid)
  const mutation = usePeopleMutation<AcademyAttendance>(active, () => { setEditor(null); onSaved() }, onInvalid)
  const blocked = disabled || read.loading || mutation.blocked
  function save(event: FormEvent<HTMLFormElement>) {
    const data = new FormData(event.currentTarget), item = editor === 'new' ? null : editor
    const body = { status: String(data.get('status')), ...(data.get('status') === 'late' ? { late_minutes: Number(data.get('late_minutes')) } : {}),
      ...(item ? { version: item.version, change_reason: String(data.get('change_reason')) } : { enrollment_id: String(data.get('enrollment_id')) }) }
    mutation.propose(event, text('saveAttendance'), base + '/attendances' + (item ? '/' + item.id : ''), body, !!item)
  }
  return <section aria-label={text('attendanceTitle')} className="grid min-w-0 gap-3 border-t border-slate-700 pt-3">
    <h4 className="font-bold [overflow-wrap:anywhere]">{text('attendanceTitle')} · {session.title}</h4><p>{text('attendanceHint')}</p>
    {read.loading && <p role="status">{text('loading')}</p>}{read.error && <Alert tone="error" title={read.error.message} />}{mutation.feedback}
    {canWrite && <Button disabled={blocked} onClick={() => setEditor('new')}>{text('addAttendance')}</Button>}
    {read.result?.data.length === 0 && <p>{text('noAttendance')}</p>}
    <ul className="grid min-w-0 gap-3">{read.result?.data.map((item) => <li key={item.id} className="grid min-w-0 gap-2 rounded-xl border border-slate-700 p-3 [overflow-wrap:anywhere]"><p>{item.person_label ?? text('personUnavailable')} · {text(item.status)}{item.late_minutes !== null && ' · ' + item.late_minutes + ' min'}</p>{canWrite && <Button disabled={blocked} onClick={() => setEditor(item)}>{text('correctAttendance')} {item.person_label ?? text('personUnavailable')}</Button>}</li>)}</ul>
    {read.result && <Pagination currentPage={read.result.meta.current_page} totalPages={read.result.meta.last_page} disabled={blocked} onPageChange={setPage} />}
    {editor && canWrite && <AttendanceForm key={editor === 'new' ? 'new' : editor.id + editor.version} item={editor === 'new' ? null : editor} disabled={blocked || enrollments.loading} enrollments={enrollments.result?.data.filter((item) => item.status === 'active') ?? []} errors={mutation.errors(base + '/attendances' + (editor === 'new' ? '' : '/' + editor.id))} onSubmit={save} onCancel={() => setEditor(null)} />}
    {editor === 'new' && enrollments.error && <Alert tone="error" title={enrollments.error.message} />}
    {editor === 'new' && enrollments.result && <Pagination currentPage={enrollments.result.meta.current_page} totalPages={enrollments.result.meta.last_page} disabled={blocked || enrollments.loading} onPageChange={setEnrollmentPage} />}
    <Button disabled={blocked} onClick={() => setHistory((value) => !value)}>{text('history')}</Button>
    {history && <>{revisions.loading && <p role="status">{text('loading')}</p>}{revisions.error && <Alert tone="error" title={revisions.error.message} />}<ul>{revisions.result?.data.map((item) => <li key={item.id} className="[overflow-wrap:anywhere]">{text(item.kind)} · {item.recorded_at} · {item.resource_id}</li>)}</ul>{revisions.result && <Pagination currentPage={revisions.result.meta.current_page} totalPages={revisions.result.meta.last_page} disabled={blocked || revisions.loading} onPageChange={setHistoryPage} />}</>}
  </section>
}

function AttendanceForm({ item, disabled, enrollments, errors, onSubmit, onCancel }: { item: AcademyAttendance | null; disabled: boolean; enrollments: Enrollment[]; errors: Record<string, string[]>; onSubmit: (event: FormEvent<HTMLFormElement>) => void; onCancel: () => void }) {
  const [status, setStatus] = useState<string>(item?.status ?? 'present')
  return <form aria-label={text('saveAttendance')} className="grid min-w-0 gap-3" onSubmit={onSubmit}>
    {!item && <SelectField name="enrollment_id" title={text('enrollment')} required disabled={disabled} error={errors.enrollment_id?.[0]}><option value="">{text('choose')}</option>{enrollments.map((enrollment) => <option key={enrollment.id} value={enrollment.id}>{enrollment.person_label ?? text('personUnavailable')}</option>)}</SelectField>}
    <SelectField name="status" title={text('attendanceStatus')} value={status} onChange={setStatus} disabled={disabled} error={errors.status?.[0]}>{['present', 'absent', 'excused', 'late'].map((value) => <option key={value} value={value}>{text(value)}</option>)}</SelectField>
    {status === 'late' && <FormField label={text('lateMinutes')} name="late_minutes" type="number" required min={1} max={240} defaultValue={item?.late_minutes ?? 1} disabled={disabled} error={errors.late_minutes?.[0]} />}
    {item && <SessionTextArea label={text('reason')} name="change_reason" required minLength={10} maxLength={1000} disabled={disabled} error={errors.change_reason?.[0]} />}
    <div className="flex flex-wrap gap-2"><Button type="submit" disabled={disabled || (!item && enrollments.length === 0)}>{text('saveAttendance')}</Button><Button disabled={disabled} onClick={onCancel}>{text('closeForm')}</Button></div>
  </form>
}
