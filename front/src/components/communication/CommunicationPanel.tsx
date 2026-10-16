import { useState, type FormEvent } from 'react'
import type { ActiveOrganization } from '../../api/tenancy'
import { communicationText as text, communicationDate as date, type Announcement } from '../../api/communication'
import { Button } from '../Button'
import { Pagination } from '../data/Pagination'
import { SelectField } from '../people/peopleForms'
import { usePeopleMutation } from '../people/usePeopleMutation'
import { usePartnershipList } from '../partnerships/usePartnershipList'
import { CommunicationForm, PublishForm } from './CommunicationForm'
import { CommentThread, CommunicationInbox, CommunicationPreferencesForm, CommunicationLoadState } from './CommunicationViews'

export type CommunicationProps = { active: ActiveOrganization; disabled: boolean; onInvalid: () => void }
export function CommunicationPanel({ active, disabled, onInvalid }: CommunicationProps) {
  const [open, setOpen] = useState(false), [view, setView] = useState('feed'), [page, setPage] = useState(1), [refresh, setRefresh] = useState(0)
  const [status, setStatus] = useState(''), [editor, setEditor] = useState<Announcement | 'new' | null>(null), [thread, setThread] = useState<Announcement | null>(null)
  const permissions = active.context?.permissions ?? [], canRead = permissions.includes('communication.read'), canWrite = permissions.includes('communication.write'), canPublish = permissions.includes('communication.publish')
  const root = 'tenants/' + active.context!.organization_id + '/communication', management = view === 'management'
  const path = root + '/announcements?page=' + page + '&per_page=10' + (management ? '&management=1' + (status ? '&status=' + status : '') : '')
  const { list, loading, error } = usePartnershipList<Announcement>(path, active, open && canRead && ['feed', 'management'].includes(view), onInvalid, refresh)
  const mutation = usePeopleMutation(active, () => { setEditor(null); setThread(null); setRefresh((n) => n + 1) }, onInvalid)
  const blocked = disabled || mutation.blocked
  if (!canRead) return null
  function changeView(next: string) { setView(next); setPage(1); setEditor(null); setThread(null) }
  function action(event: FormEvent<HTMLFormElement>, item: Announcement, name: string, extra: Record<string, unknown> = {}) {
    mutation.propose(event, text('action.' + name), root + '/announcements/' + item.id, { version: item.version, action: name, ...extra }, true)
  }
  return <section aria-label={text('title')} className="grid min-w-0 gap-4 rounded-2xl border border-white/10 p-4">
    <h2 className="text-xl font-bold">{text('title')}</h2>
    <p className="text-sm text-slate-400">{text('hint')}</p>
    <Button disabled={blocked} onClick={() => { setOpen(!open); setEditor(null); setThread(null) }}>{text(open ? 'close' : 'open')}</Button>
    {open && <>
      {mutation.feedback}
      <div role="group" aria-label={text('views')} className="flex flex-wrap gap-2">
        {['feed', ...(canWrite ? ['management'] : []), 'inbox', 'preferences'].map((key) => <Button key={key} aria-pressed={view === key} disabled={blocked} onClick={() => changeView(key)}>{text('view.' + key)}</Button>)}
        <Button disabled={blocked} onClick={() => { setThread(null); setRefresh((n) => n + 1) }}>{text('refresh')}</Button>
      </div>
      {['feed', 'management'].includes(view) && <>
        {management && <><p className="text-sm text-amber-200">{text('managementHint')}</p>
          <SelectField name="status" title={text('statusFilter')} value={status} disabled={blocked} onChange={(next) => { setStatus(next); setPage(1); setThread(null) }}>
            <option value="">{text('all')}</option>{['draft', 'published', 'withdrawn'].map((key) => <option key={key} value={key}>{text('status.' + key)}</option>)}
          </SelectField>
          <Button disabled={blocked} onClick={() => { setEditor('new'); setThread(null) }}>{text('new')}</Button>
        </>}
        <CommunicationLoadState {...{ loading, error }} />
        {list?.data.length === 0 && <p>{text('empty')}</p>}
        <ul className="grid min-w-0 gap-3">{list?.data.map((item) => <li key={item.id} className="grid min-w-0 gap-3 rounded-xl border border-slate-700 p-3">
          <h3 className="font-bold [overflow-wrap:anywhere]">{item.title}</h3>
          <p>{text('status.' + item.status)} · {text('target.' + item.target_type)}</p>
          {item.published_at && <p className="text-sm text-slate-400">{text('publishedAt')} : {date(item.published_at, active.context!.timezone)}</p>}
          {item.expires_at && <p className="text-sm text-slate-400">{text('expiresAt')} : {date(item.expires_at, active.context!.timezone)}</p>}
          <p className="whitespace-pre-wrap [overflow-wrap:anywhere]">{item.body}</p>
          <p>{text(item.comments_enabled ? 'commentsOpen' : 'commentsClosed')}</p>
          {management && item.status === 'draft' && <>
            <Button disabled={blocked} onClick={() => { setEditor(item); setThread(null) }}>{text('edit')}</Button>
            {canPublish && <PublishForm disabled={blocked} errors={mutation.errors(root + '/announcements/' + item.id)} onSubmit={(event, notify) => action(event, item, 'publish', { confirm_publication: true, notify_members: notify })} />}
          </>}
          {management && item.status === 'published' && <div className="flex flex-wrap gap-2">
            <form onSubmit={(event) => action(event, item, 'comments', { comments_enabled: !item.comments_enabled })}><Button type="submit" disabled={blocked}>{text(item.comments_enabled ? 'closeComments' : 'openComments')}</Button></form>
            {canPublish && <form onSubmit={(event) => action(event, item, 'withdraw')}><Button type="submit" disabled={blocked}>{text('action.withdraw')}</Button></form>}
          </div>}
          {item.status !== 'draft' && <Button disabled={blocked} onClick={() => { setThread(thread?.id === item.id ? null : item); setEditor(null) }}>{text('comments')}</Button>}
        </li>)}</ul>
        {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={blocked || loading} onPageChange={(next) => { setPage(next); setThread(null) }} />}
        {editor && management && <CommunicationForm key={editor === 'new' ? 'new' : editor.id + editor.version} {...{ active, onInvalid, root }} disabled={blocked} item={editor === 'new' ? null : editor}
          errors={mutation.errors(root + '/announcements' + (editor === 'new' ? '' : '/' + editor.id))} onCancel={() => setEditor(null)}
          onSubmit={(event, values) => mutation.propose(event, text('save'), root + '/announcements' + (editor === 'new' ? '' : '/' + editor.id), { ...values, ...(editor === 'new' ? {} : { version: editor.version }) }, editor !== 'new')} />}
        {thread && <CommentThread key={thread.id + thread.version + management} {...{ active, root, onInvalid, management }} item={thread} disabled={blocked} errors={mutation.errors(root + '/announcements/' + thread.id + '/comments')}
          onSubmit={(event, values) => mutation.propose(event, text('sendComment'), root + '/announcements/' + thread.id + '/comments', { ...values, version: thread.version })} />}
      </>}
      {view === 'inbox' && <CommunicationInbox {...{ active, root, onInvalid, refresh }} disabled={blocked}
        onRead={(event, id) => mutation.propose(event, text('read'), root + '/inbox/' + id + '/read', {})} />}
      {view === 'preferences' && <CommunicationPreferencesForm key={refresh} {...{ active, root, onInvalid }} disabled={blocked}
        errors={mutation.errors(root + '/preferences')} onSubmit={(event, values) => mutation.propose(event, text('savePreferences'), root + '/preferences', values, true)} />}
      <p className="text-sm text-slate-400">{text('limits')}</p>
    </>}
  </section>
}
