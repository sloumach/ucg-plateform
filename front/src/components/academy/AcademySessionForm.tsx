import { useState, type FormEvent } from 'react'
import type { ActiveOrganization } from '../../api/tenancy'
import type { AcademyCycle, Cohort } from '../../api/academy'
import { sessionsPath, sessionText as text, sessionWindow, type AcademySession, type SessionActivity, type SessionExercise } from '../../api/academySessions'
import type { Enrollment } from '../../api/academy'
import { FormField } from '../FormField'
import { Button } from '../Button'
import { SelectField } from '../people/peopleForms'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { useAcademyPage } from './useAcademyPage'
import { SessionTextArea } from './SessionTextArea'

type Props = { active: ActiveOrganization; cohort: Cohort; cycle: AcademyCycle; session: AcademySession | null; disabled: boolean; errors: Record<string, string[]>; onInvalid: () => void; onCancel: () => void; onSubmit: (event: FormEvent<HTMLFormElement>, body: Record<string, unknown>) => void }
export function AcademySessionForm({ active, cohort, cycle, session, disabled, errors, onInvalid, onCancel, onSubmit }: Props) {
  const org = active.context!.organization_id, base = sessionsPath(org, cohort.id, cycle.id)
  const [window, setWindow] = useState(sessionWindow(cycle.starts_on, cycle.ends_on)), [activityPage, setActivityPage] = useState(1), [mentorPage, setMentorPage] = useState(1)
  const [activityId, setActivityId] = useState(session?.activity?.id ?? ''), [mentorId, setMentorId] = useState(session?.responsible_enrollment_id ?? '')
  const [exercises, setExercises] = useState<SessionExercise[]>(session?.exercises ?? [])
  const activities = useAcademyPage<SessionActivity>(active, base.replace(/\/sessions$/, '/session-activities') + '?' + new URLSearchParams(window), activityPage, 0, onInvalid)
  const mentors = useAcademyPage<Enrollment>(active, 'tenants/' + org + '/academy/cohorts/' + cohort.id + '/enrollments?cycle_id=' + cycle.id, mentorPage, 0, onInvalid)
  const activityOptions = [...(session?.activity ? [session.activity] : []), ...(activities.result?.data.filter((item) => item.id !== session?.activity?.id) ?? [])]
  const activity = activityOptions.find((item) => item.id === activityId)
  const mentorOptions = mentors.result?.data.filter((item) => item.role === 'mentor' && item.status === 'active') ?? []
  const blocked = disabled || activities.loading || mentors.loading
  function updateExercise(index: number, changes: Partial<SessionExercise>) { setExercises((items) => items.map((item, current) => current === index ? { ...item, ...changes } : item)) }
  function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!activity || !mentorId) return
    const data = new FormData(event.currentTarget)
    onSubmit(event, { title: String(data.get('title')), activity_id: activity.id, activity_version: activity.version, responsible_enrollment_id: mentorId,
      exercises, report: String(data.get('report') ?? '') || null, ...(session ? { version: session.version, status: String(data.get('status')), change_reason: String(data.get('change_reason')) } : {}) })
  }
  return <div className="grid min-w-0 gap-4 rounded-xl border border-slate-700 p-3">
    <p>{text('calendarHint')}</p>
    <form aria-label={text('calendarFilter')} className="grid min-w-0 gap-3 sm:grid-cols-2" onSubmit={(event) => { event.preventDefault(); const data = new FormData(event.currentTarget); setWindow({ from_date: String(data.get('from_date')), to_date: String(data.get('to_date')) }); setActivityPage(1) }}>
      <FormField label={text('from')} name="from_date" type="date" required defaultValue={window.from_date} disabled={disabled} /><FormField label={text('to')} name="to_date" type="date" required defaultValue={window.to_date} disabled={disabled} /><Button disabled={blocked} type="submit">{text('filter')}</Button>
    </form>
    {activities.error && <Alert tone="error" title={activities.error.message} />}{mentors.error && <Alert tone="error" title={mentors.error.message} />}
    <form aria-label={text('save')} onSubmit={save} className="grid min-w-0 gap-3">
      <FormField label={text('title')} name="title" required maxLength={160} defaultValue={session?.title} disabled={blocked} error={errors.title?.[0]} />
      <SelectField name="activity_id" title={text('activity')} value={activityId} onChange={setActivityId} disabled={blocked || (session?.attendance_count ?? 0) > 0} required error={errors.activity_id?.[0]}><option value="">{text('choose')}</option>{activityOptions.map((item) => <option key={item.id} value={item.id}>{item.label} · {item.starts_at}</option>)}</SelectField>
      {activities.result && <Pagination currentPage={activities.result.meta.current_page} totalPages={activities.result.meta.last_page} disabled={blocked} onPageChange={(next) => { setActivityPage(next); if (!session) setActivityId('') }} />}
      <SelectField name="responsible_enrollment_id" title={text('responsible')} value={mentorId} onChange={setMentorId} disabled={blocked || (session?.attendance_count ?? 0) > 0} required error={errors.responsible_enrollment_id?.[0]}><option value="">{text('choose')}</option>{session && !mentorOptions.some((item) => item.id === session.responsible_enrollment_id) && <option value={session.responsible_enrollment_id}>{session.responsible_label ?? text('personUnavailable')}</option>}{mentorOptions.map((item) => <option key={item.id} value={item.id}>{item.person_label ?? text('personUnavailable')}</option>)}</SelectField>
      {mentors.result && <Pagination currentPage={mentors.result.meta.current_page} totalPages={mentors.result.meta.last_page} disabled={blocked} onPageChange={(next) => { setMentorPage(next); if (!session) setMentorId('') }} />}
      {exercises.map((item, index) => <fieldset key={index} disabled={blocked} className="grid min-w-0 gap-3 rounded-xl border border-slate-700 p-3"><legend>{text('exercise')} {index + 1}</legend>
        <FormField label={text('exerciseTitle')} value={item.title} required maxLength={160} onChange={(event) => updateExercise(index, { title: event.target.value })} error={errors['exercises.' + index + '.title']?.[0]} />
        <SessionTextArea label={text('instructions')} maxLength={1000} value={item.instructions ?? ''} onChange={(event) => updateExercise(index, { instructions: event.target.value || null })} error={errors['exercises.' + index + '.instructions']?.[0]} />
        <FormField label={text('minutes')} type="number" required min={1} max={480} value={item.minutes} onChange={(event) => updateExercise(index, { minutes: Number(event.target.value) })} error={errors['exercises.' + index + '.minutes']?.[0]} />
        <Button onClick={() => setExercises((items) => items.filter((_, current) => current !== index))}>{text('removeExercise')}</Button>
      </fieldset>)}
      {errors.exercises && <p role="alert">{errors.exercises[0]}</p>}
      <Button disabled={blocked || exercises.length >= 20} onClick={() => setExercises((items) => [...items, { title: '', instructions: null, minutes: 20 }])}>{text('addExercise')}</Button>
      <SessionTextArea label={text('report')} name="report" rows={4} maxLength={4000} defaultValue={session?.report ?? ''} disabled={blocked} error={errors.report?.[0]} />
      {session && <><SelectField name="status" title={text('status')} defaultValue={session.status} disabled={blocked}><option value="planned" disabled={session.status === 'completed'}>{text('planned')}</option><option value="completed">{text('completed')}</option></SelectField><SessionTextArea label={text('reason')} name="change_reason" required minLength={10} maxLength={1000} disabled={blocked} error={errors.change_reason?.[0]} /></>}
      <div className="flex flex-wrap gap-2"><Button disabled={blocked || !activity || !mentorId} type="submit">{text('save')}</Button><Button disabled={disabled} onClick={onCancel}>{text('closeForm')}</Button></div>
    </form>
  </div>
}
