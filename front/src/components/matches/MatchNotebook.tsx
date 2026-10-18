import { useId, useState, type FormEvent } from 'react'
import type { ActiveOrganization, Page } from '../../api/tenancy'
import { notebookKinds, matchText as text, type MatchRecord, type MatchNotebookEntry, type MatchNotebookPage, type NotebookAudience } from '../../api/matches'
import { calendarDate } from '../../api/calendar'
import { usePartnershipList } from '../partnerships/usePartnershipList'
import { usePeopleMutation } from '../people/usePeopleMutation'
import { SelectField } from '../people/peopleForms'
import { FormField } from '../FormField'
import { Button } from '../Button'
import { Alert } from '../feedback/Alert'
import { Pagination } from '../data/Pagination'

type Props = { active: ActiveOrganization; item: MatchRecord; root: string; management: boolean; disabled: boolean;
  onInvalid: () => void; onClose: () => void }
export function MatchNotebook({ active, item, root, management, disabled, onInvalid, onClose }: Props) {
  const [page, setPage] = useState(1), [refresh, setRefresh] = useState(0)
  const [filter, setFilter] = useState({ audience: '', kind: '' })
  const [editor, setEditor] = useState<MatchNotebookEntry | 'new' | null>(null), [history, setHistory] = useState<MatchNotebookEntry | null>(null)
  const path = root + '/' + item.id + '/notebook'
  const params = new URLSearchParams({ page: String(page), per_page: '10', ...(management ? { management: '1' } : {}), ...(filter.audience ? { audience: filter.audience } : {}), ...(filter.kind ? { kind: filter.kind } : {}) })
  const { list, loading, error } = usePartnershipList<MatchNotebookEntry, MatchNotebookPage>(path + '?' + params, active, true, onInvalid, refresh)
  const mutation = usePeopleMutation<MatchNotebookEntry>(active, () => { setEditor(null); setHistory(null); setPage(1); setRefresh((n) => n + 1) }, onInvalid)
  const blocked = disabled || mutation.blocked, writable = !loading && !error ? list?.writable_audiences ?? [] : []
  const permissions = active.context?.permissions ?? []
  const readable: NotebookAudience[] = ['shared', ...(permissions.includes('sports.notes.read') ? ['internal' as const] : []), ...(permissions.includes('sports.strategies.read') ? ['strategy' as const] : [])]
  const editPath = editor && editor !== 'new' ? path + '/' + editor.entry_id : path
  return <section aria-label={text('notebook.title')} className="grid min-w-0 gap-3 rounded-xl border border-cyan-400/30 p-3">
    <h3 className="font-bold [overflow-wrap:anywhere]">{text('notebook.title')} · {item.title}</h3>
    <p className="text-sm text-slate-400">{text('notebook.hint')}</p>
    <Button disabled={blocked} onClick={onClose}>{text('notebook.close')}</Button>
    {mutation.feedback}
    <form aria-label={text('notebook.filters')} className="grid min-w-0 gap-3 sm:grid-cols-2" onSubmit={(event) => {
      event.preventDefault(); const values = new FormData(event.currentTarget)
      setFilter({ audience: String(values.get('audience') ?? ''), kind: String(values.get('kind') ?? '') }); setPage(1); setEditor(null); setHistory(null)
    }}>
      <SelectField name="audience" title={text('notebook.audience')} disabled={blocked}><option value="">{text('all')}</option>{readable.map((a) => <option key={a} value={a}>{text('notebook.audience.' + a)}</option>)}</SelectField>
      <SelectField name="kind" title={text('notebook.kind')} disabled={blocked}><option value="">{text('all')}</option>{notebookKinds.map((k) => <option key={k} value={k}>{text('notebook.kind.' + k)}</option>)}</SelectField>
      <Button type="submit" disabled={blocked}>{text('notebook.filter')}</Button>
    </form>
    {loading && <p role="status">{text('loading')}</p>}
    {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
    {writable.length > 0 && <Button disabled={blocked} onClick={() => { setEditor('new'); setHistory(null) }}>{text('notebook.create')}</Button>}
    {list?.data.length === 0 && <p>{text('notebook.empty')}</p>}
    <ol className="grid min-w-0 gap-3">{list?.data.map((entry) => <li key={entry.entry_id} className="grid min-w-0 gap-2 rounded-lg border border-slate-700 p-3">
      <NotebookContent entry={entry} zone={active.context!.timezone} />
      <div className="flex flex-wrap gap-3">
        {writable.includes(entry.audience) && <Button disabled={blocked} onClick={() => { setEditor(entry); setHistory(null) }}>{text('notebook.edit')}</Button>}
        <Button disabled={blocked} onClick={() => { setHistory(entry); setEditor(null) }}>{text('notebook.history')}</Button>
      </div>
    </li>)}</ol>
    {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={blocked || loading} onPageChange={(next) => { setPage(next); setEditor(null); setHistory(null) }} />}
    {editor && writable.length > 0 && (editor === 'new' || writable.includes(editor.audience)) && <NotebookForm key={editor === 'new' ? 'new' : editor.version} item={editor === 'new' ? null : editor}
      writable={writable} disabled={blocked} errors={mutation.errors(editPath)} onCancel={() => setEditor(null)} onSubmit={(event, body) => mutation.propose(event, text('notebook.save'), editPath, body, editor !== 'new')} />}
    {history && <NotebookHistory key={history.entry_id} {...{ active, management, disabled: blocked, onInvalid }} path={path + '/' + history.entry_id + '/history'} onClose={() => setHistory(null)} />}
  </section>
}

function safeVod(value: string | null) {
  try { const url = new URL(value ?? ''); return url.protocol === 'https:' && !url.username && !url.password ? url.href : undefined } catch { return undefined }
}
function NotebookContent({ entry, zone }: { entry: MatchNotebookEntry; zone: string }) {
  const vod = safeVod(entry.vod_url)
  return <>
    <h4 className="font-bold [overflow-wrap:anywhere]">{entry.title}</h4>
    <p>{text('notebook.kind.' + entry.kind)} · {text('notebook.audience.' + entry.audience)} · v{entry.number}</p>
    <p className="whitespace-pre-wrap [overflow-wrap:anywhere]">{entry.body}</p>
    {entry.kind === 'vod' && <><p className="text-sm text-slate-400">{text('notebook.vodHint')}</p>{vod && <a className="break-all text-cyan-300 underline" href={vod} target="_blank" rel="noopener noreferrer" referrerPolicy="no-referrer">{text('notebook.openVod')}</a>}
      {entry.timestamp_seconds !== null && <p>{text('notebook.timestamp')} : {entry.timestamp_seconds}</p>}</>}
    {entry.state && <p>{text('notebook.state.' + entry.state)}</p>}
    {entry.due_at && <p>{text('notebook.due')} : {calendarDate(entry.due_at, 'UTC')}</p>}
    <p className="text-sm text-slate-400">{text('actor')} #{entry.actor_user_id} · {calendarDate(entry.occurred_at, zone)}</p>
  </>
}
function NotebookForm({ item, writable, disabled, errors, onCancel, onSubmit }: { item: MatchNotebookEntry | null; writable: NotebookAudience[]; disabled: boolean;
  errors: Record<string, string[]>; onCancel: () => void; onSubmit: (event: FormEvent<HTMLFormElement>, body: Record<string, unknown>) => void }) {
  const [kind, setKind] = useState(item?.kind ?? 'note'), [audience, setAudience] = useState<NotebookAudience>(item?.audience ?? (writable.includes('internal') ? 'internal' : writable[0]))
  const id = useId()
  return <form aria-label={text('notebook.save')} className="grid min-w-0 gap-3 rounded-xl border border-amber-400/30 p-3" onSubmit={(event) => {
    const values = new FormData(event.currentTarget), value = (key: string) => String(values.get(key) ?? '').trim()
    onSubmit(event, { ...(item ? { version: item.version } : { kind, audience }), title: value('title'), body: value('body'),
      ...(kind === 'vod' ? { vod_url: value('vod_url'), timestamp_seconds: value('timestamp_seconds') === '' ? null : Number(value('timestamp_seconds')) } : {}),
      ...(kind === 'action' ? { state: value('state'), due_at: value('due_at') ? value('due_at') + ':00+00:00' : null } : {}),
      ...(audience === 'shared' ? { confirm_sharing: values.has('confirm_sharing') } : {}) })
  }}>
    {item ? <p className="text-sm text-amber-200">{text('notebook.fixedAudience')} {text('notebook.audience.' + audience)} · {text('notebook.kind.' + kind)}</p> : <>
      <SelectField name="kind" title={text('notebook.kind')} value={kind} onChange={(v) => setKind(v as MatchNotebookEntry['kind'])} disabled={disabled} error={errors.kind?.[0]}>{notebookKinds.map((k) => <option key={k} value={k}>{text('notebook.kind.' + k)}</option>)}</SelectField>
      <SelectField name="audience" title={text('notebook.audience')} value={audience} onChange={(v) => setAudience(v as NotebookAudience)} disabled={disabled} error={errors.audience?.[0]}>{writable.map((a) => <option key={a} value={a}>{text('notebook.audience.' + a)}</option>)}</SelectField>
    </>}
    <FormField name="title" label={text('notebook.titleField')} defaultValue={item?.title ?? ''} maxLength={200} required disabled={disabled} error={errors.title?.[0]} />
    <label htmlFor={id}>{text('notebook.body')}</label><textarea id={id} name="body" defaultValue={item?.body ?? ''} maxLength={10000} required disabled={disabled} rows={5}
      aria-invalid={!!errors.body} aria-describedby={errors.body ? id + '-error' : undefined} className="min-w-0 w-full rounded-xl border border-white/15 bg-slate-900 p-3" />
    {errors.body && <p id={id + '-error'} className="text-rose-200">{errors.body[0]}</p>}
    {kind === 'vod' && <><FormField name="vod_url" label={text('notebook.url')} type="url" pattern="https://.*" defaultValue={item?.vod_url ?? ''} maxLength={2000} required disabled={disabled} error={errors.vod_url?.[0]} hint={text('notebook.vodHint')} />
      <FormField name="timestamp_seconds" label={text('notebook.timestamp')} type="number" min={0} max={604800} step={1} defaultValue={item?.timestamp_seconds ?? ''} disabled={disabled} error={errors.timestamp_seconds?.[0]} /></>}
    {kind === 'action' && <><SelectField name="state" title={text('notebook.state')} defaultValue={item?.state ?? 'open'} disabled={disabled} error={errors.state?.[0]}><option value="open">{text('notebook.state.open')}</option><option value="done">{text('notebook.state.done')}</option></SelectField>
      <FormField name="due_at" label={text('notebook.due')} type="datetime-local" defaultValue={item?.due_at ? new Date(item.due_at).toISOString().slice(0, 16) : ''} disabled={disabled} error={errors.due_at?.[0]} /></>}
    {audience === 'shared' && <FormField name="confirm_sharing" label={text('notebook.sharing')} type="checkbox" required disabled={disabled} error={errors.confirm_sharing?.[0]} />}
    <div className="flex flex-wrap gap-3"><Button type="submit" disabled={disabled}>{text('notebook.save')}</Button><Button disabled={disabled} onClick={onCancel}>{text('cancel')}</Button></div>
  </form>
}
function NotebookHistory({ active, path, management, disabled, onInvalid, onClose }: { active: ActiveOrganization; path: string; management: boolean; disabled: boolean; onInvalid: () => void; onClose: () => void }) {
  const [page, setPage] = useState(1)
  const { list, loading, error } = usePartnershipList<MatchNotebookEntry, Page<MatchNotebookEntry>>(path + '?page=' + page + '&per_page=10' + (management ? '&management=1' : ''), active, true, onInvalid)
  return <section aria-label={text('notebook.history')} className="grid min-w-0 gap-3 rounded-xl border border-slate-700 p-3">
    <h4 className="font-bold">{text('notebook.history')}</h4><Button disabled={disabled} onClick={onClose}>{text('notebook.closeHistory')}</Button>
    {loading && <p role="status">{text('loading')}</p>}{error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
    <ol className="grid min-w-0 gap-3">{list?.data.map((entry) => <li key={entry.version} className="grid min-w-0 gap-2"><NotebookContent entry={entry} zone={active.context!.timezone} /></li>)}</ol>
    {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={disabled || loading} onPageChange={setPage} />}
  </section>
}
