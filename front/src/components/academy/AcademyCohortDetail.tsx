import { useState, type FormEvent } from 'react'
import type { ActiveOrganization } from '../../api/tenancy'
import { academyDay, academyPath, type Cohort, type AcademyCycle, type AcademyRevision } from '../../api/academy'
import { t } from '../../i18n/fr'
import { Button } from '../Button'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { usePeopleMutation } from '../people/usePeopleMutation'
import { useAcademyPage } from './useAcademyPage'
import { AcademyCycleForm } from './AcademyCycleForm'
import { AcademyEnrollments } from './AcademyEnrollments'

type Props = { active: ActiveOrganization; cohort: Cohort; disabled: boolean; onInvalid: () => void }
export function AcademyCohortDetail({ active, cohort, disabled, onInvalid }: Props) {
  const org = active.context!.organization_id, base = academyPath(org, cohort.id)
  const manage = active.context!.permissions.includes('academy.manage') && cohort.status === 'active'
  const [tab, setTab] = useState<'cycles' | 'history'>('cycles'), [page, setPage] = useState(1), [refresh, setRefresh] = useState(0)
  const [editor, setEditor] = useState<AcademyCycle | 'new' | null>(null), [cycle, setCycle] = useState<AcademyCycle | null>(null)
  const read = useAcademyPage<AcademyCycle | AcademyRevision>(active, base + '/' + tab, page, refresh, onInvalid)
  const mutation = usePeopleMutation<AcademyCycle>(active, () => { setEditor(null); setCycle(null); setRefresh((value) => value + 1) }, onInvalid)
  const blocked = disabled || read.loading || mutation.blocked
  function save(event: FormEvent<HTMLFormElement>, body: Record<string, unknown>) {
    const editing = editor !== 'new' ? editor : null
    mutation.propose(event, t('academy.saveCycle'), base + '/cycles' + (editing ? '/' + editing.id : ''), { ...body, ...(editing ? { version: editing.version } : {}) }, !!editing)
  }
  return <section aria-label={cohort.name} className="grid min-w-0 gap-4 border-t border-slate-700 pt-4">
    <h3 className="font-bold [overflow-wrap:anywhere]">{cohort.name}</h3>
    <div className="flex flex-wrap gap-2">{(['cycles', 'history'] as const).map((value) => <Button key={value} disabled={blocked} aria-pressed={tab === value} onClick={() => { setTab(value); setPage(1); setEditor(null); setCycle(null) }}>{t(value === 'cycles' ? 'academy.cycles' : 'academy.history')}</Button>)}</div>
    {read.loading && <p role="status">{t('academy.loading')}</p>}
    {read.error && <><Alert tone="error" title={read.error.message} /><Button onClick={() => setRefresh((value) => value + 1)}>{t('academy.retry')}</Button></>}
    {mutation.feedback}
    {read.result?.data.length === 0 && <p>{t('academy.emptyDetails')}</p>}
    {tab === 'cycles' && <>
      {manage && <Button disabled={blocked} onClick={() => { setEditor('new'); setCycle(null) }}>{t('academy.createCycle')}</Button>}
      <ul className="grid min-w-0 gap-3">{read.result?.data.map((item) => { const current = item as AcademyCycle; return <li key={current.id} className="grid min-w-0 gap-2 rounded-xl border border-slate-700 p-3 [overflow-wrap:anywhere]">
        <h4 className="font-bold">{current.name}</h4><p>{current.starts_on} – {current.ends_on} · {current.timezone} · {t(current.status === 'active' ? 'academy.active' : 'academy.archived')}</p>
        {current.timetable.length === 0 && <p>{t('academy.noSlots')}</p>}
        <ul>{current.timetable.map((slot, index) => <li key={index}>{academyDay(slot.day)} : {slot.starts_at} – {slot.ends_at}</li>)}</ul>
        <Button disabled={blocked} onClick={() => { setCycle(current); setEditor(null) }}>{t('academy.enrollments')} {current.name}</Button>
        {manage && current.status === 'active' && <>
          <Button disabled={blocked} onClick={() => { setEditor(current); setCycle(null) }}>{t('academy.edit')} {current.name}</Button>
          <form onSubmit={(event) => mutation.propose(event, t('academy.archive'), base + '/cycles/' + current.id, { version: current.version, status: 'archived' }, true)}><Button disabled={blocked} type="submit">{t('academy.archive')} {current.name}</Button></form>
        </>}
      </li> })}</ul>
      {editor && <AcademyCycleForm key={editor === 'new' ? 'new' : editor.id + editor.version} cycle={editor === 'new' ? null : editor} timezone={active.context!.timezone} disabled={blocked} errors={mutation.errors(base + '/cycles' + (editor === 'new' ? '' : '/' + editor.id))} onSubmit={save} onCancel={() => setEditor(null)} />}
      {cycle && <AcademyEnrollments key={cycle.id + cycle.version} active={active} cohort={cohort} cycle={cycle} disabled={blocked} onInvalid={onInvalid} />}
    </>}
    {tab === 'history' && <ul className="grid min-w-0 gap-2">{read.result?.data.map((item) => { const revision = item as AcademyRevision; return <li key={revision.id} className="[overflow-wrap:anywhere]">{t(revision.kind === 'cohort' ? 'academy.cohort' : revision.kind === 'cycle' ? 'academy.cycle' : 'academy.enrollment')} · {revision.recorded_at} · {revision.resource_id}</li> })}</ul>}
    {read.result && <Pagination currentPage={read.result.meta.current_page} totalPages={read.result.meta.last_page} disabled={blocked} onPageChange={(next) => { setPage(next); setCycle(null); setEditor(null) }} />}
  </section>
}
