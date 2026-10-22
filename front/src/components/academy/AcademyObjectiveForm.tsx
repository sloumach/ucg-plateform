import { useState, type FormEvent } from 'react'
import type { ActiveOrganization } from '../../api/tenancy'
import type { Cohort, AcademyCycle, Enrollment } from '../../api/academy'
import { coachingText as text, type AcademyObjective } from '../../api/academyCoaching'
import { FormField } from '../FormField'
import { Button } from '../Button'
import { SelectField } from '../people/peopleForms'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { SessionTextArea } from './SessionTextArea'
import { useAcademyPage } from './useAcademyPage'

type Props = { active: ActiveOrganization; cohort: Cohort; cycle: AcademyCycle; item: AcademyObjective | null; disabled: boolean; errors: Record<string, string[]>; onInvalid: () => void; onCancel: () => void; onSubmit: (event: FormEvent<HTMLFormElement>, body: Record<string, unknown>) => void }
export function AcademyObjectiveForm({ active, cohort, cycle, item, disabled, errors, onInvalid, onCancel, onSubmit }: Props) {
  const [page, setPage] = useState(1), [retry, setRetry] = useState(0)
  const [mentor, setMentor] = useState(item?.responsible_enrollment_id ?? ''), [target, setTarget] = useState('')
  const [labels, setLabels] = useState<Record<string, string>>({})
  const read = useAcademyPage<Enrollment>(active, 'tenants/' + active.context!.organization_id + '/academy/cohorts/' + cohort.id + '/enrollments?cycle_id=' + cycle.id + '&status=active', page, retry, onInvalid)
  const options = read.result?.data ?? [], mentors = options.filter((row) => row.role === 'mentor'), students = options.filter((row) => row.role === 'student')
  const blocked = disabled || read.loading || !!read.error
  function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const data = new FormData(event.currentTarget)
    if (item && data.get('status') !== 'active') {
      onSubmit(event, { version: item.version, status: String(data.get('status')), change_reason: String(data.get('change_reason')) })
      return
    }
    onSubmit(event, { title: String(data.get('title')), description: String(data.get('description') ?? '') || null, due_on: String(data.get('due_on')), responsible_enrollment_id: mentor,
      ...(item ? { version: item.version, status: String(data.get('status')), change_reason: String(data.get('change_reason')) } : { target_enrollment_id: target || null }) })
  }
  return <form aria-label={text('saveObjective')} onSubmit={save} className="grid min-w-0 gap-3 rounded-xl border border-slate-700 p-3">
    <p>{text('audienceFixed')}</p>
    {read.loading && <p role="status">{text('loading')}</p>}{read.error && <><Alert tone="error" title={read.error.message} /><Button onClick={() => setRetry((value) => value + 1)}>{text('retry')}</Button></>}
    <FormField label={text('title')} name="title" required maxLength={160} defaultValue={item?.title} disabled={blocked} error={errors.title?.[0]} />
    <SessionTextArea label={text('description')} name="description" maxLength={4000} defaultValue={item?.description ?? ''} disabled={blocked} error={errors.description?.[0]} />
    <FormField label={text('deadline')} name="due_on" type="date" required min={cycle.starts_on} max={cycle.ends_on} defaultValue={item?.due_on ?? cycle.ends_on} disabled={blocked} error={errors.due_on?.[0]} />
    <SelectField name="responsible_enrollment_id" title={text('responsible')} value={mentor} onChange={(id) => { setMentor(id); setLabels((current) => ({ ...current, [id]: mentors.find((row) => row.id === id)?.person_label ?? text('unavailable') })) }} required disabled={blocked} error={errors.responsible_enrollment_id?.[0]}>
      <option value="">{text('choose')}</option>{mentor && !mentors.some((row) => row.id === mentor) && <option value={mentor}>{labels[mentor] ?? item?.responsible_label ?? text('unavailable')}</option>}{mentors.map((row) => <option key={row.id} value={row.id}>{row.person_label ?? text('unavailable')}</option>)}
    </SelectField>
    {!item && <SelectField name="target_enrollment_id" title={text('target')} value={target} onChange={(id) => { setTarget(id); setLabels((current) => ({ ...current, [id]: students.find((row) => row.id === id)?.person_label ?? text('unavailable') })) }} disabled={blocked} error={errors.target_enrollment_id?.[0]}><option value="">{text('collective')}</option>{target && !students.some((row) => row.id === target) && <option value={target}>{labels[target] ?? text('unavailable')}</option>}{students.map((row) => <option key={row.id} value={row.id}>{row.person_label ?? text('unavailable')}</option>)}</SelectField>}
    {read.result && <Pagination currentPage={read.result.meta.current_page} totalPages={read.result.meta.last_page} disabled={blocked} onPageChange={setPage} />}
    {item && <><SelectField name="status" title={text('status')} defaultValue={item.status} disabled={blocked}>{['active', 'completed', 'cancelled'].map((value) => <option key={value} value={value}>{text(value)}</option>)}</SelectField><SessionTextArea label={text('reason')} name="change_reason" required minLength={10} maxLength={1000} disabled={blocked} error={errors.change_reason?.[0]} /></>}
    <div className="flex flex-wrap gap-2"><Button type="submit" disabled={blocked || !mentor}>{text('saveObjective')}</Button><Button disabled={disabled} onClick={onCancel}>{text('closeForm')}</Button></div>
  </form>
}
