import { useId, useState, type FormEvent } from 'react'
import { taskText as text, type WorkTask } from '../../api/communication'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { SelectField } from '../people/peopleForms'
import { Pagination } from '../data/Pagination'
import { usePartnershipList } from '../partnerships/usePartnershipList'
import { CommunicationLoadState } from './CommunicationViews'
import type { CommunicationProps } from './CommunicationPanel'

type Props = CommunicationProps & { root: string; item: WorkTask | null; errors: Record<string, string[]>; onSubmit: (event: FormEvent<HTMLFormElement>, values: Record<string, unknown>) => void; onCancel: () => void }

export function WorkTaskForm({ active, disabled, onInvalid, root, item, errors, onSubmit, onCancel }: Props) {
  const descriptionId = useId()
  const [page, setPage] = useState(1), [assignee, setAssignee] = useState(item?.assignee_id ?? '')
  const { list, loading, error } = usePartnershipList<{ id: string; label: string }>(root + '/people?per_page=10&page=' + page, active, true, onInvalid)
  return <form aria-label={text(item ? 'edit' : 'create')} noValidate className="grid min-w-0 gap-3 rounded-xl border border-cyan-400/30 p-4" onSubmit={(event) => {
    event.preventDefault()
    if (disabled || error) return
    const input = new FormData(event.currentTarget)
    const values = { title: String(input.get('title') ?? '').trim(), description: String(input.get('description') ?? '').trim() || null,
      assignee_id: assignee || null, due_on: String(input.get('due_on') ?? '') || null, status: String(input.get('status') ?? 'todo') }
    const changed = item ? Object.fromEntries(Object.entries(values).filter(([key, value]) => value !== item[key as keyof WorkTask])) : values
    onSubmit(event, changed)
  }}>
    <FormField name="title" label={text('taskTitle')} defaultValue={item?.title ?? ''} required maxLength={160} disabled={disabled} error={errors.title?.[0]} />
    <label htmlFor={descriptionId}>{text('description')}</label>
    <textarea id={descriptionId} name="description" defaultValue={item?.description ?? ''} rows={4} maxLength={4000} disabled={disabled}
      aria-invalid={errors.description ? true : undefined} aria-describedby={errors.description ? descriptionId + '-error' : undefined} className="min-w-0 w-full rounded-xl border border-white/15 bg-slate-900 px-4 py-3 text-slate-100" />
    {errors.description && <p id={descriptionId + '-error'} className="text-sm text-rose-200">{errors.description[0]}</p>}
    <SelectField name="assignee_id" title={text('assignee')} value={assignee} onChange={setAssignee} disabled={disabled} error={errors.assignee_id?.[0]}>
      <option value="">{text('unassigned')}</option>
      {assignee && !list?.data.some((person) => person.id === assignee) && <option value={assignee}>{item?.assignee_label ?? text('currentAssignee')}</option>}
      {list?.data.map((person) => <option key={person.id} value={person.id}>{person.label}</option>)}
    </SelectField>
    <CommunicationLoadState {...{ loading, error }} />
    {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={disabled || loading} onPageChange={setPage} />}
    <FormField name="due_on" label={text('dueOn')} type="date" defaultValue={item?.due_on ?? ''} disabled={disabled} error={errors.due_on?.[0]} />
    <p className="text-sm text-slate-400">{text('dateHint')}</p>
    <SelectField name="status" title={text('status')} defaultValue={item?.status ?? 'todo'} disabled={disabled} error={errors.status?.[0]}>
      {['todo', 'in_progress', 'done'].map((value) => <option key={value} value={value}>{text('status.' + value)}</option>)}
    </SelectField>
    <div className="flex flex-wrap gap-3"><Button type="submit" disabled={disabled || !!error}>{text('save')}</Button><Button disabled={disabled} onClick={onCancel}>{text('cancel')}</Button></div>
  </form>
}
