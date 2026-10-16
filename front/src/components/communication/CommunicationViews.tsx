import { useState, type FormEvent } from 'react'
import type { ApiClientError } from '../../api/errors'
import { communicationText as text, communicationDate as date, type Announcement, type AnnouncementComment, type CommunicationEvent, type CommunicationPreferences } from '../../api/communication'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { AsyncState } from '../feedback/AsyncState'
import { usePartnershipList } from '../partnerships/usePartnershipList'
import { MessageField } from './CommunicationForm'
import type { CommunicationProps } from './CommunicationPanel'
import { useCommunicationValue } from './useCommunicationValue'
type Errors = Record<string, string[]>
type Submit = (event: FormEvent<HTMLFormElement>, values: Record<string, unknown>) => void
export function CommunicationLoadState({ loading, error }: { loading: boolean; error: ApiClientError | null }) {
  return <>{loading && <AsyncState variant="loading" title={text('loading')} />}{error && <Alert tone="error" title={error.message} requestId={error.requestId} />}</>
}
export function CommentThread({ active, root, item, management, onInvalid, disabled, errors, onSubmit }: CommunicationProps & { root: string; item: Announcement; management: boolean; errors: Errors; onSubmit: Submit }) {
  const [page, setPage] = useState(1)
  const { list, loading, error } = usePartnershipList<AnnouncementComment>(root + '/announcements/' + item.id + '/comments?per_page=10&page=' + page + (management ? '&management=1' : ''), active, true, onInvalid)
  return <section aria-label={text('comments')} className="grid min-w-0 gap-3 rounded-xl border border-slate-700 p-3">
    <h3 className="font-bold [overflow-wrap:anywhere]">{text('comments')} : {item.title}</h3>
    <CommunicationLoadState {...{ loading, error }} />
    {list?.data.length === 0 && <p>{text('noComments')}</p>}
    <ul className="grid min-w-0 gap-3">{list?.data.map((comment) => <li key={comment.id} className="grid min-w-0 gap-1 border-b border-slate-700 pb-2">
      <p className="text-sm text-slate-400">{text(comment.is_mine ? 'you' : 'member')} · {date(comment.created_at, active.context!.timezone)}</p>
      <p className="whitespace-pre-wrap [overflow-wrap:anywhere]">{comment.body}</p>
    </li>)}</ul>
    {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={disabled || loading} onPageChange={setPage} />}
    {item.can_comment && !error && !loading && <form aria-label={text('sendComment')} noValidate className="grid min-w-0 gap-3" onSubmit={(event) => {
      event.preventDefault(); const values = new FormData(event.currentTarget)
      onSubmit(event, { body: String(values.get('body') ?? '').trim(), notify_members: values.has('notify_members') })
    }}>
      <MessageField disabled={disabled} error={errors.body?.[0]} />
      <FormField name="notify_members" label={text('notifyComments')} type="checkbox" disabled={disabled} />
      <Button type="submit" disabled={disabled}>{text('sendComment')}</Button>
    </form>}
    {!item.can_comment && <p className="text-sm text-slate-400">{text('commentUnavailable')}</p>}
  </section>
}
export function CommunicationInbox({ active, root, onInvalid, disabled, refresh, onRead }: CommunicationProps & { root: string; refresh: number; onRead: (event: FormEvent<HTMLFormElement>, id: string) => void }) {
  const [page, setPage] = useState(1), [unread, setUnread] = useState(false), [selected, setSelected] = useState<string | null>(null)
  const { list, loading, error } = usePartnershipList<CommunicationEvent>(root + '/inbox?per_page=10&page=' + page + (unread ? '&unread=1' : ''), active, true, onInvalid, refresh)
  return <div className="grid min-w-0 gap-3">
    <FormField name="unread" label={text('unreadOnly')} type="checkbox" checked={unread} disabled={disabled} onChange={(event) => { setUnread(event.target.checked); setPage(1); setSelected(null) }} />
    <CommunicationLoadState {...{ loading, error }} />
    {list?.data.length === 0 && <p>{text('inboxEmpty')}</p>}
    <ul className="grid min-w-0 gap-3">{list?.data.map((item) => <li key={item.id} className="grid min-w-0 gap-2 rounded-xl border border-slate-700 p-3">
      <p className="font-bold [overflow-wrap:anywhere]">{item.title}</p>
      <p>{text('notification.' + item.kind)} · {date(item.created_at, active.context!.timezone)} · {text(item.read ? 'alreadyRead' : 'unread')}</p>
      <Button disabled={disabled} onClick={() => setSelected(item.announcement_id)}>{text('openAnnouncement')}</Button>
      {!item.read && <form onSubmit={(event) => onRead(event, item.id)}><Button type="submit" disabled={disabled}>{text('read')}</Button></form>}
    </li>)}</ul>
    {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={disabled || loading} onPageChange={(next) => { setPage(next); setSelected(null) }} />}
    {selected && <AnnouncementPreview key={selected} {...{ active, root, disabled, onInvalid }} id={selected} />}
  </div>
}
function AnnouncementPreview({ active, root, id, onInvalid }: CommunicationProps & { root: string; id: string }) {
  const { data, loading, error } = useCommunicationValue<Announcement>(root + '/announcements/' + id, active, onInvalid)
  return <section aria-label={text('announcementDetail')} className="grid min-w-0 gap-3 rounded-xl border border-cyan-400/30 p-3">
    <CommunicationLoadState {...{ loading, error }} />
    {data && <><h3 className="font-bold [overflow-wrap:anywhere]">{data.title}</h3><p className="whitespace-pre-wrap [overflow-wrap:anywhere]">{data.body}</p><p>{text('replyInFeed')}</p></>}
  </section>
}
export function CommunicationPreferencesForm({ active, root, onInvalid, disabled, errors, onSubmit }: CommunicationProps & { root: string; errors: Errors; onSubmit: Submit }) {
  const { data, loading, error } = useCommunicationValue<CommunicationPreferences>(root + '/preferences', active, onInvalid)
  return <div className="grid min-w-0 gap-3">
    <CommunicationLoadState {...{ loading, error }} />
    {data && <form aria-label={text('savePreferences')} className="grid min-w-0 gap-3" onSubmit={(event) => {
      event.preventDefault(); const values = new FormData(event.currentTarget)
      onSubmit(event, { version: data.version, announcements_enabled: values.has('announcements_enabled'), comments_enabled: values.has('comments_enabled') })
    }}>
      <p className="text-sm text-slate-400">{text('preferencesHint')}</p>
      <FormField name="announcements_enabled" label={text('preferenceAnnouncements')} type="checkbox" defaultChecked={data.announcements_enabled} disabled={disabled} error={errors.announcements_enabled?.[0]} />
      <FormField name="comments_enabled" label={text('preferenceComments')} type="checkbox" defaultChecked={data.comments_enabled} disabled={disabled} error={errors.comments_enabled?.[0]} />
      <Button type="submit" disabled={disabled}>{text('savePreferences')}</Button>
    </form>}
  </div>
}
