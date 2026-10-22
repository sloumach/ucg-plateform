import { useState, type FormEvent } from 'react'
import type { ActiveOrganization } from '../../api/tenancy'
import { academyPath, type Cohort, type AcademyCycle, type Enrollment } from '../../api/academy'
import { t } from '../../i18n/fr'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { SelectField } from '../people/peopleForms'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { usePeopleMutation } from '../people/usePeopleMutation'
import { useAcademyPage } from './useAcademyPage'

type Props = { active: ActiveOrganization; cohort: Cohort; cycle: AcademyCycle; disabled: boolean; onInvalid: () => void }
export function AcademyEnrollments({ active, cohort, cycle, disabled, onInvalid }: Props) {
  const org = active.context!.organization_id, base = academyPath(org, cohort.id) + '/enrollments'
  const manage = active.context!.permissions.includes('academy.manage') && cohort.status === 'active' && cycle.status === 'active'
  const [page, setPage] = useState(1), [peoplePage, setPeoplePage] = useState(1), [refresh, setRefresh] = useState(0), [adding, setAdding] = useState(false)
  const read = useAcademyPage<Enrollment>(active, base + '?cycle_id=' + cycle.id, page, refresh, onInvalid)
  const people = useAcademyPage<{ id: string; label: string }>(active, adding && manage ? 'tenants/' + org + '/academy/people' : null, peoplePage, 0, onInvalid)
  const mutation = usePeopleMutation<Enrollment>(active, () => { setAdding(false); setRefresh((value) => value + 1) }, onInvalid)
  const blocked = disabled || read.loading || mutation.blocked
  const errors = mutation.errors(base)
  function save(event: FormEvent<HTMLFormElement>) {
    const data = new FormData(event.currentTarget)
    mutation.propose(event, t('academy.addEnrollment'), base, { cycle_id: cycle.id, person_id: String(data.get('person_id')), role: String(data.get('role')), starts_on: String(data.get('starts_on')), ends_on: String(data.get('ends_on')) })
  }
  return <section aria-label={t('academy.enrollments')} className="grid min-w-0 gap-3 border-t border-slate-700 pt-3">
    <h4 className="font-bold [overflow-wrap:anywhere]">{t('academy.enrollments')} · {cycle.name}</h4>
    {read.loading && <p role="status">{t('academy.loading')}</p>}
    {read.error && <><Alert tone="error" title={read.error.message} /><Button onClick={() => setRefresh((value) => value + 1)}>{t('academy.retry')}</Button></>}
    {mutation.feedback}
    {manage && <Button disabled={blocked} onClick={() => setAdding((value) => !value)}>{t('academy.addEnrollment')}</Button>}
    {read.result?.data.length === 0 && <p>{t('academy.noEnrollments')}</p>}
    <ul className="grid min-w-0 gap-3">{read.result?.data.map((item) => <li key={item.id} className="grid min-w-0 gap-2 rounded-xl border border-slate-700 p-3 [overflow-wrap:anywhere]">
      <p>{item.person_label ?? t('academy.personUnavailable')} · {t(item.role === 'mentor' ? 'academy.mentor' : 'academy.student')}</p>
      <p>{item.starts_on} – {item.ends_on} · {t(item.status === 'active' ? 'academy.active' : item.status === 'completed' ? 'academy.completed' : 'academy.withdrawn')}</p>
      {manage && item.status === 'active' && <div className="flex flex-wrap gap-2">{(['withdrawn', 'completed'] as const).map((status) => <form key={status} onSubmit={(event) => mutation.propose(event, t(status === 'withdrawn' ? 'academy.withdraw' : 'academy.complete'), base + '/' + item.id, { version: item.version, status }, true)}><Button type="submit" disabled={blocked}>{t(status === 'withdrawn' ? 'academy.withdraw' : 'academy.complete')}</Button></form>)}</div>}
    </li>)}</ul>
    {read.result && <Pagination currentPage={read.result.meta.current_page} totalPages={read.result.meta.last_page} disabled={blocked} onPageChange={setPage} />}
    {adding && manage && <form aria-label={t('academy.addEnrollment')} onSubmit={save} className="grid min-w-0 gap-3">
      {people.loading && <p role="status">{t('academy.loading')}</p>}
      {people.error && <Alert tone="error" title={people.error.message} />}
      <p className="text-sm text-slate-400">{t('academy.mentorHint')}</p>
      <SelectField key={peoplePage} name="person_id" title={t('academy.person')} required disabled={blocked || people.loading} error={errors.person_id?.[0]}><option value="">{t('academy.choose')}</option>{people.result?.data.map((person) => <option key={person.id} value={person.id}>{person.label}</option>)}</SelectField>
      {people.result && <Pagination currentPage={people.result.meta.current_page} totalPages={people.result.meta.last_page} disabled={blocked || people.loading} onPageChange={setPeoplePage} />}
      <SelectField name="role" title={t('academy.role')} disabled={blocked} error={errors.role?.[0]}><option value="student">{t('academy.student')}</option><option value="mentor">{t('academy.mentor')}</option></SelectField>
      <FormField label={t('academy.starts')} name="starts_on" type="date" required min={cycle.starts_on} max={cycle.ends_on} defaultValue={cycle.starts_on} disabled={blocked} error={errors.starts_on?.[0]} />
      <FormField label={t('academy.ends')} name="ends_on" type="date" required min={cycle.starts_on} max={cycle.ends_on} defaultValue={cycle.ends_on} disabled={blocked} error={errors.ends_on?.[0]} />
      <Button type="submit" disabled={blocked || people.loading || !people.result?.data.length}>{t('academy.saveEnrollment')}</Button>
    </form>}
  </section>
}
