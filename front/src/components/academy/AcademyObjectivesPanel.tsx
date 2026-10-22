import { useState } from 'react'
import type { ActiveOrganization } from '../../api/tenancy'
import type { Cohort, AcademyCycle } from '../../api/academy'
import { objectivesPath, coachingText as text, type AcademyObjective, type CoachingRevision } from '../../api/academyCoaching'
import { Button } from '../Button'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { usePeopleMutation } from '../people/usePeopleMutation'
import { useAcademyPage } from './useAcademyPage'
import { AcademyObjectiveForm } from './AcademyObjectiveForm'
import { AcademyFeedbackPanel } from './AcademyFeedbackPanel'

type Props = { active: ActiveOrganization; cohort: Cohort; cycle: AcademyCycle; disabled: boolean; onInvalid: () => void }
export function AcademyObjectivesPanel({ active, cohort, cycle, disabled, onInvalid }: Props) {
  const org = active.context?.organization_id ?? '', allowed = active.context?.permissions.includes('academy.read') ?? false
  const notes = active.context?.permissions.includes('academy.notes.manage') ?? false
  const writable = cohort.status === 'active' && cycle.status === 'active', manage = writable && (active.context?.permissions.includes('academy.manage') ?? false)
  const base = objectivesPath(org, cohort.id, cycle.id)
  const [open, setOpen] = useState(false), [page, setPage] = useState(1), [refresh, setRefresh] = useState(0)
  const [editor, setEditor] = useState<AcademyObjective | 'new' | null>(null), [detail, setDetail] = useState<{ id: string; mode: 'shared' | 'internal' | 'history' } | null>(null)
  const read = useAcademyPage<AcademyObjective>(active, open && allowed ? base : null, page, refresh, onInvalid)
  const selected = read.result?.data.find((item) => item.id === detail?.id)
  const mutation = usePeopleMutation<AcademyObjective>(active, () => { setEditor(null); setDetail(null); setRefresh((value) => value + 1) }, onInvalid)
  const blocked = disabled || read.loading || mutation.blocked
  if (!org || !allowed) return null
  return <section aria-label={text('panel')} className="grid min-w-0 gap-4 border-t border-slate-700 pt-4">
    <Button disabled={disabled || mutation.blocked} onClick={() => { setOpen((value) => !value); setDetail(null); setEditor(null) }}>{open ? text('close') : text('panel')}</Button>
    {open && <><h4 className="font-bold">{cycle.name}</h4><p>{text('hint')}</p>{mutation.feedback}
      {read.loading && <p role="status">{text('loading')}</p>}{read.error && <><Alert tone="error" title={read.error.message} requestId={read.error.requestId} /><Button onClick={() => setRefresh((value) => value + 1)}>{text('retry')}</Button></>}
      {manage && <Button disabled={blocked} onClick={() => { setEditor('new'); setDetail(null) }}>{text('create')}</Button>}{read.result?.data.length === 0 && <p>{text('empty')}</p>}
      <ul className="grid min-w-0 gap-3">{read.result?.data.map((item) => <li key={item.id} className="grid min-w-0 gap-3 rounded-xl border border-slate-700 p-3 [overflow-wrap:anywhere]">
        <h5 className="font-bold">{item.title}</h5><p>{text(item.scope)} · {text(item.status)} · {text('deadline')} : {item.due_on}</p>
        <p>{text('responsible')} : {item.responsible_label ?? text('unavailable')}{item.scope === 'individual' && ' · ' + (item.target_label ?? text('unavailable'))}</p>
        {item.description && <p className="whitespace-pre-wrap">{item.description}</p>}
        <div className="flex flex-wrap gap-2">
          <Button disabled={blocked} onClick={() => { setDetail({ id: item.id, mode: 'shared' }); setEditor(null) }}>{text('feedback')} {item.title}</Button>
          {notes && <Button disabled={blocked} onClick={() => { setDetail({ id: item.id, mode: 'internal' }); setEditor(null) }}>{text('notes')} {item.title}</Button>}
          <Button disabled={blocked} onClick={() => { setDetail({ id: item.id, mode: 'history' }); setEditor(null) }}>{text('history')} {item.title}</Button>
          {manage && item.status === 'active' && <Button disabled={blocked} onClick={() => { setEditor(item); setDetail(null) }}>{text('editObjective')} {item.title}</Button>}
        </div>
      </li>)}</ul>
      {read.result && <Pagination currentPage={read.result.meta.current_page} totalPages={read.result.meta.last_page} disabled={blocked} onPageChange={(next) => { setPage(next); setEditor(null); setDetail(null) }} />}
      {editor && manage && <AcademyObjectiveForm key={editor === 'new' ? 'new' : editor.id + editor.version} active={active} cohort={cohort} cycle={cycle} item={editor === 'new' ? null : editor} disabled={blocked} errors={mutation.errors(base + (editor === 'new' ? '' : '/' + editor.id))} onInvalid={onInvalid} onCancel={() => setEditor(null)} onSubmit={(event, body) => mutation.propose(event, text('saveObjective'), base + (editor === 'new' ? '' : '/' + editor.id), body, editor !== 'new')} />}
      {selected && detail?.mode === 'history' && <CoachingHistory key={selected.id} active={active} path={base + '/' + selected.id + '/history'} onInvalid={onInvalid} disabled={blocked} />}
      {selected && detail && detail.mode !== 'history' && (detail.mode !== 'internal' || notes) && <AcademyFeedbackPanel key={selected.id + detail.mode} active={active} base={base + '/' + selected.id + (detail.mode === 'internal' ? '/notes' : '/feedback')} title={selected.title} mode={detail.mode} writable={writable && selected.status !== 'cancelled'} disabled={blocked} onInvalid={onInvalid} />}
    </>}
  </section>
}
function CoachingHistory({ active, path, onInvalid, disabled }: { active: ActiveOrganization; path: string; onInvalid: () => void; disabled: boolean }) {
  const [page, setPage] = useState(1), [refresh, setRefresh] = useState(0), read = useAcademyPage<CoachingRevision>(active, path, page, refresh, onInvalid)
  return <section aria-label={text('history')} className="grid min-w-0 gap-3"><h5>{text('history')}</h5>{read.loading && <p role="status">{text('loading')}</p>}{read.error && <><Alert tone="error" title={read.error.message} /><Button onClick={() => setRefresh((value) => value + 1)}>{text('retry')}</Button></>}
    <ul>{read.result?.data.map((row) => <li key={row.id} className="[overflow-wrap:anywhere]">{text(row.kind)} · {row.recorded_at} · {text('author')} {row.actor_user_id}</li>)}</ul>
    {read.result && <Pagination currentPage={read.result.meta.current_page} totalPages={read.result.meta.last_page} disabled={disabled || read.loading} onPageChange={setPage} />}
  </section>
}
