import { useId, useState } from 'react'
import type { Page } from '../../api/tenancy'
import { communicationText as text, communicationDate as date, type AnnouncementComment } from '../../api/communication'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { Pagination } from '../data/Pagination'
import { usePeopleMutation } from '../people/usePeopleMutation'
import { usePartnershipList } from '../partnerships/usePartnershipList'
import type { CommunicationProps } from './CommunicationPanel'
import { AsyncState } from '../feedback/AsyncState'
import { Alert } from '../feedback/Alert'

type ContextType = 'event' | 'match' | 'session'
type Props = CommunicationProps & { type: ContextType; id: string }
type DiscussionPage = Page<AnnouncementComment> & {
  context: { id: string; type: ContextType; label: string; version: string; thread_version: string | null; can_comment: boolean }
}

export function ContextDiscussion(props: Props) {
  const [open, setOpen] = useState(false)
  const permissions = props.active.context?.permissions ?? []
  const required = { event: 'calendar.read', match: 'sports.read', session: 'academy.read' }[props.type]
  if (!permissions.includes('communication.read') || !permissions.includes(required)) return null
  return <div className="grid min-w-0 gap-3">
    <Button disabled={props.disabled} onClick={() => setOpen(!open)}>{text(open ? 'closeDiscussion' : 'openDiscussion')}</Button>
    {open && <Discussion key={props.active.context!.organization_id + props.type + props.id + props.active.revision} {...props} />}
  </div>
}

function Discussion({ active, disabled, onInvalid, type, id }: Props) {
  const [page, setPage] = useState(1), [refresh, setRefresh] = useState(0)
  const [body, setBody] = useState(''), [notify, setNotify] = useState(false)
  const messageId = useId()
  const path = 'tenants/' + active.context!.organization_id + '/communication/discussions/' + type + '/' + id
  const { list, loading, error } = usePartnershipList<AnnouncementComment, DiscussionPage>(path + '?per_page=10&page=' + page, active, true, onInvalid, refresh)
  const mutation = usePeopleMutation(active, () => { setBody(''); setNotify(false); setPage(1); setRefresh((n) => n + 1) }, onInvalid)
  const blocked = disabled || mutation.blocked, pendingRead = loading || list === null
  const errors = mutation.errors(path)
  const mayComment = active.context!.permissions.includes('communication.comment')
  return <section aria-label={text('discussion')} className="grid min-w-0 gap-3 rounded-xl border border-slate-700 p-3">
    <h4 className="font-bold [overflow-wrap:anywhere]">{list?.context.label ?? text('discussion')}</h4>
    <p className="text-sm text-slate-400">{text('contextHint')}</p>
    {mutation.feedback}
    <Button disabled={blocked || loading} onClick={() => setRefresh((n) => n + 1)}>{text('refresh')}</Button>
    {loading && <AsyncState variant="loading" title={text('loading')} />}{error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
    {list?.data.length === 0 && <p>{text('noComments')}</p>}
    <ul className="grid min-w-0 gap-3">{list?.data.map((comment) => <li key={comment.id} className="grid min-w-0 gap-1 border-b border-slate-700 pb-2">
      <p className="text-sm text-slate-400">{text(comment.is_mine ? 'you' : 'member')} · {date(comment.created_at, active.context!.timezone)}</p>
      <p className="whitespace-pre-wrap [overflow-wrap:anywhere]">{comment.body}</p>
    </li>)}</ul>
    {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={blocked || loading} onPageChange={setPage} />}
    {mayComment && !error && <form aria-label={text('sendComment')} className="grid min-w-0 gap-3" noValidate onSubmit={(event) => {
      event.preventDefault()
      if (blocked || pendingRead || !list?.context.can_comment) return
      mutation.propose(event, text('sendComment'), path, { body: body.trim(), notify_members: notify, version: list.context.version, thread_version: list.context.thread_version })
    }}>
      <label htmlFor={messageId}>{text('body')}</label>
      <textarea id={messageId} name="body" value={body} onChange={(event) => setBody(event.target.value)} rows={4} required maxLength={4000}
        disabled={blocked || pendingRead || !list?.context.can_comment} aria-invalid={errors.body ? true : undefined}
        aria-describedby={errors.body ? messageId + '-error' : undefined} className="min-w-0 w-full rounded-xl border border-white/15 bg-slate-900 px-4 py-3 text-slate-100" />
      {errors.body && <p id={messageId + '-error'} className="text-sm text-rose-200">{errors.body[0]}</p>}
      <FormField name="notify_members" label={text('notifyComments')} type="checkbox" checked={notify} onChange={(event) => setNotify(event.target.checked)} disabled={blocked || pendingRead || !list?.context.can_comment} />
      <Button type="submit" disabled={blocked || pendingRead || !list?.context.can_comment}>{text('sendComment')}</Button>
    </form>}
    {list && !list.context.can_comment && <p>{text('commentUnavailable')}</p>}
  </section>
}
