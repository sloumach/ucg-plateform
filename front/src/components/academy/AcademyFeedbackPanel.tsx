import { useState, type FormEvent } from 'react'
import type { ActiveOrganization } from '../../api/tenancy'
import { coachingText as text, type AcademyFeedback } from '../../api/academyCoaching'
import { Button } from '../Button'
import { SelectField } from '../people/peopleForms'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { usePeopleMutation } from '../people/usePeopleMutation'
import { SessionTextArea } from './SessionTextArea'
import { useAcademyPage } from './useAcademyPage'

type Props = { active: ActiveOrganization; base: string; title: string; mode: 'shared' | 'internal' | 'self'; writable: boolean; disabled: boolean; onInvalid: () => void }
export function AcademyFeedbackPanel({ active, base, title, mode, writable, disabled, onInvalid }: Props) {
  const allowed = mode === 'self' ? active.context?.permissions.includes('academy.self.read') : active.context?.permissions.includes('academy.read') && (mode !== 'internal' || active.context.permissions.includes('academy.notes.manage'))
  const permission = mode === 'internal' ? 'academy.notes.manage' : 'academy.feedback.manage'
  const manage = writable && mode !== 'self' && (active.context?.permissions.includes(permission) ?? false)
  const [page, setPage] = useState(1), [refresh, setRefresh] = useState(0), [editor, setEditor] = useState<AcademyFeedback | 'new' | null>(null)
  const read = useAcademyPage<AcademyFeedback>(active, allowed ? base : null, page, refresh, onInvalid)
  const mutation = usePeopleMutation<AcademyFeedback>(active, () => { setEditor(null); setRefresh((value) => value + 1) }, onInvalid)
  if (!allowed) return null
  const blocked = disabled || read.loading || mutation.blocked, createAction = text(mode === 'internal' ? 'saveNote' : 'publish')
  const action = editor && editor !== 'new' ? text('saveCorrection') : createAction
  function save(event: FormEvent<HTMLFormElement>) {
    const data = new FormData(event.currentTarget), item = editor !== 'new' ? editor : null
    mutation.propose(event, item && data.get('status') === 'withdrawn' ? text('withdraw') : action, base + (item ? '/' + item.id : ''), { content: String(data.get('content')), ...(mode === 'internal' ? { justification: String(data.get('justification')) } : {}),
      ...(item ? { version: item.version, status: String(data.get('status')), change_reason: String(data.get('change_reason')) } : {}) }, !!item)
  }
  return <section aria-label={text(mode === 'internal' ? 'notes' : 'feedback') + ' · ' + title} className="grid min-w-0 gap-3 border-t border-slate-700 pt-4">
    <h5 className="font-bold [overflow-wrap:anywhere]">{text(mode === 'internal' ? 'notes' : 'feedback')} · {title}</h5><p>{text(mode === 'internal' ? 'notesHint' : 'sharedHint')}</p>
    {read.loading && <p role="status">{text('loading')}</p>}{read.error && <><Alert tone="error" title={read.error.message} requestId={read.error.requestId} /><Button onClick={() => setRefresh((value) => value + 1)}>{text('retry')}</Button></>}{mutation.feedback}
    {manage && <Button disabled={blocked} onClick={() => setEditor('new')}>{createAction}</Button>}
    {read.result?.data.length === 0 && <p>{text('noFeedback')}</p>}
    <ul className="grid min-w-0 gap-3">{read.result?.data.map((item) => <li key={item.id} className="grid min-w-0 gap-2 rounded-xl border border-slate-700 p-3 [overflow-wrap:anywhere]">
      <p className="whitespace-pre-wrap">{item.content}</p>{mode === 'internal' && item.justification && <p>{text('justification')} : {item.justification}</p>}<p>{item.created_at}{item.status && ' · ' + text(item.status)}</p>
      {manage && item.status === 'active' && <Button disabled={blocked} onClick={() => setEditor(item)}>{text('editFeedback')}</Button>}
    </li>)}</ul>
    {read.result && <Pagination currentPage={read.result.meta.current_page} totalPages={read.result.meta.last_page} disabled={blocked} onPageChange={(next) => { setPage(next); setEditor(null) }} />}
    {editor && manage && <form key={editor === 'new' ? 'new' : editor.id + editor.version} aria-label={action} onSubmit={save} className="grid min-w-0 gap-3">
      <SessionTextArea label={text('content')} name="content" required maxLength={4000} disabled={blocked} defaultValue={editor === 'new' ? '' : editor.content} error={mutation.errors(base + (editor === 'new' ? '' : '/' + editor.id)).content?.[0]} />
      {mode === 'internal' && <SessionTextArea label={text('justification')} name="justification" required minLength={10} maxLength={1000} defaultValue={editor === 'new' ? '' : editor.justification} disabled={blocked} error={mutation.errors(base + (editor === 'new' ? '' : '/' + editor.id)).justification?.[0]} />}
      {editor !== 'new' && <><SelectField name="status" title={text('status')} defaultValue="active" disabled={blocked}><option value="active">{text('active')}</option><option value="withdrawn">{text('withdrawn')}</option></SelectField><SessionTextArea label={text('reason')} name="change_reason" required minLength={10} maxLength={1000} disabled={blocked} error={mutation.errors(base + '/' + editor.id).change_reason?.[0]} /></>}
      <div className="flex flex-wrap gap-2"><Button type="submit" disabled={blocked}>{action}</Button><Button disabled={blocked} onClick={() => setEditor(null)}>{text('closeForm')}</Button></div>
    </form>}
  </section>
}
