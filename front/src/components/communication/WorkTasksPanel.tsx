import { useState, type FormEvent } from 'react'
import { taskText as text, type WorkTask } from '../../api/communication'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { SelectField } from '../people/peopleForms'
import { Pagination } from '../data/Pagination'
import { usePeopleMutation } from '../people/usePeopleMutation'
import { usePartnershipList } from '../partnerships/usePartnershipList'
import { CommunicationLoadState } from './CommunicationViews'
import { ContextDiscussion } from './ContextDiscussion'
import { WorkTaskForm } from './WorkTaskForm'
import type { CommunicationProps } from './CommunicationPanel'

export function WorkTasksPanel(props: CommunicationProps) {
  const { active, disabled, onInvalid } = props
  const [open, setOpen] = useState(false), [page, setPage] = useState(1), [refresh, setRefresh] = useState(0)
  const [status, setStatus] = useState(''), [dueBefore, setDueBefore] = useState('')
  const [editor, setEditor] = useState<WorkTask | 'new' | null>(null)
  const permissions = active.context?.permissions ?? [], canRead = permissions.includes('tasks.read'), canManage = permissions.includes('tasks.manage')
  const root = 'tenants/' + active.context!.organization_id + '/tasks'
  const query = new URLSearchParams({ page: String(page), per_page: '10', ...(status ? { status } : {}), ...(dueBefore ? { due_before: dueBefore } : {}) })
  const { list, loading, error } = usePartnershipList<WorkTask>(root + '?' + query, active, open && canRead, onInvalid, refresh)
  const mutation = usePeopleMutation<WorkTask>(active, () => { setEditor(null); setRefresh((value) => value + 1) }, onInvalid)
  const blocked = disabled || mutation.blocked
  if (!canRead) return null
  function save(event: FormEvent<HTMLFormElement>, values: Record<string, unknown>) {
    if (blocked || editor === null) return
    const editing = editor !== 'new'
    mutation.propose(event, text('save'), root + (editing ? '/' + editor.id : ''), editing ? { ...values, version: editor.version } : values, editing)
  }
  return <section aria-label={text('title')} className="grid min-w-0 gap-4 rounded-2xl border border-white/10 p-4">
    <h2 className="text-xl font-bold">{text('title')}</h2>
    <p className="text-sm text-slate-400">{text('hint')}</p>
    <Button disabled={blocked} onClick={() => { setOpen(!open); setEditor(null) }}>{text(open ? 'close' : 'open')}</Button>
    {open && <>
      {mutation.feedback}
      <div className="grid gap-3 sm:grid-cols-2">
        <SelectField name="task_status_filter" title={text('filterStatus')} value={status} onChange={(value) => { setStatus(value); setPage(1); setEditor(null) }} disabled={blocked}>
          <option value="">{text('allStatuses')}</option>{['todo', 'in_progress', 'done'].map((value) => <option key={value} value={value}>{text('status.' + value)}</option>)}
        </SelectField>
        <FormField name="due_before" label={text('dueBefore')} type="date" value={dueBefore} onChange={(event) => { setDueBefore(event.target.value); setPage(1); setEditor(null) }} disabled={blocked} />
      </div>
      <div className="flex flex-wrap gap-3"><Button disabled={blocked || loading} onClick={() => { setEditor(null); setRefresh((value) => value + 1) }}>{text('refresh')}</Button>
        {canManage && <Button disabled={blocked || loading} onClick={() => setEditor('new')}>{text('create')}</Button>}
      </div>
      {editor !== null && canManage && <WorkTaskForm key={editor === 'new' ? 'new' : editor.id + editor.version} {...props} root={root} item={editor === 'new' ? null : editor}
        disabled={blocked} errors={mutation.errors(root + (editor === 'new' ? '' : '/' + editor.id))} onSubmit={save} onCancel={() => setEditor(null)} />}
      <CommunicationLoadState {...{ loading, error }} />
      {list?.data.length === 0 && <p>{text('empty')}</p>}
      <ul className="grid min-w-0 gap-3">{list?.data.map((task) => <li key={task.id} className="grid min-w-0 gap-3 rounded-xl border border-slate-700 p-3">
        <h3 className="font-bold [overflow-wrap:anywhere]">{task.title}</h3><p>{text('status.' + task.status)}</p>
        {task.description && <p className="whitespace-pre-wrap [overflow-wrap:anywhere]">{task.description}</p>}
        <p className="[overflow-wrap:anywhere]">{text('assignee')} : {task.assignee_label ?? text(task.assignee_id ? 'unavailableAssignee' : 'unassigned')}</p>
        {task.due_on && <p>{text('dueOn')} : <time dateTime={task.due_on}>{new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(task.due_on + 'T12:00:00Z'))}</time></p>}
        {canManage && <Button disabled={blocked || loading} onClick={() => setEditor(task)}>{text('edit')}</Button>}
        <ContextDiscussion key={task.id + task.version} {...props} disabled={blocked || loading} type="task" id={task.id} />
      </li>)}</ul>
      {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={blocked || loading} onPageChange={(value) => { setPage(value); setEditor(null) }} />}
    </>}
  </section>
}
