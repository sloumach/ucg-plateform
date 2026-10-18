import { useEffect, useId, useRef, useState, type FormEvent } from 'react'
import { tenancyRequest, type ActiveOrganization, type Page } from '../../api/tenancy'
import { competitionText as text, safeCompetitionUrl, type CompetitionDocument, type ExternalCompetition } from '../../api/externalCompetitions'
import { calendarDate } from '../../api/calendar'
import { usePartnershipList } from '../partnerships/usePartnershipList'
import { usePeopleMutation } from '../people/usePeopleMutation'
import { apiError, shouldReloadContext } from '../people/peopleFormValues'
import { useNotifications } from '../feedback/notificationContext'
import { Pagination } from '../data/Pagination'
import { SelectField } from '../people/peopleForms'
import { FormField } from '../FormField'
import { Button } from '../Button'
import { Alert } from '../feedback/Alert'
type Props = { active: ActiveOrganization; root: string; item: ExternalCompetition; disabled: boolean; onInvalid: () => void; onClose: () => void }
export function CompetitionDocuments({ active, root, item, disabled, onInvalid, onClose }: Props) {
  const [page, setPage] = useState(1), [refresh, setRefresh] = useState(0)
  const [editor, setEditor] = useState<CompetitionDocument | 'new' | null>(null), [history, setHistory] = useState<CompetitionDocument | null>(null)
  const permissions = active.context?.permissions ?? [], allowed = permissions.includes('sports.read') && permissions.includes('sports.competitions.read')
  const writable = allowed && item.status === 'active' && permissions.includes('sports.competitions.write')
  const path = root + '/' + item.id + '/documents', editPath = path + (editor && editor !== 'new' ? '/' + editor.document_id : '')
  const { list, loading, error } = usePartnershipList<CompetitionDocument>(path + '?page=' + page + '&per_page=5', active, allowed, onInvalid, refresh)
  const mutation = usePeopleMutation<CompetitionDocument>(active, () => { setEditor(null); setHistory(null); setRefresh((value) => value + 1) }, onInvalid)
  const blocked = disabled || mutation.blocked
  if (!allowed) return null
  return <section aria-label={text('documents')} className="grid min-w-0 gap-3 rounded-xl border border-cyan-400/30 p-3"><h3 className="font-bold [overflow-wrap:anywhere]">{text('documents')} · {item.name}</h3>
    <p className="text-sm text-slate-400">{text('documentsHint')}</p><Button disabled={blocked} onClick={onClose}>{text('closeDocuments')}</Button>{mutation.feedback}
    {loading && <p role="status">{text('loading')}</p>}{error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
    {writable && <Button disabled={blocked} onClick={() => { setHistory(null); setEditor('new') }}>{text('createDocument')}</Button>}
    {list?.data.length === 0 && <p>{text('emptyDocuments')}</p>}
    <ol className="grid min-w-0 gap-3">{list?.data.map((document) => <li key={document.version} className="grid min-w-0 gap-3 rounded-lg border border-slate-700 p-3">
      <DocumentContent document={document} zone={active.context!.timezone} />
      <div className="flex flex-wrap gap-3">{writable && <Button disabled={blocked} onClick={() => { setHistory(null); setEditor(document) }}>{text('editDocument')}</Button>}
        <Button disabled={blocked} onClick={() => { setEditor(null); setHistory(document) }}>{text('documentHistory')}</Button>
        <DocumentDownload {...{ active, onInvalid }} disabled={blocked} path={path + '/' + document.document_id + '/versions/' + document.version + '/download'} version={document.version} />
      </div>
    </li>)}</ol>
    {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={blocked || loading} onPageChange={(value) => { setEditor(null); setHistory(null); setPage(value) }} />}
    {editor && writable && <DocumentForm key={editor === 'new' ? 'new' : editor.version} item={editor === 'new' ? null : editor} disabled={blocked} errors={mutation.errors(editPath)} onCancel={() => setEditor(null)}
      onSubmit={(event, body) => mutation.propose(event, text('saveDocument'), editPath, { ...body, competition_version: item.version }, editor !== 'new')} />}
    {history && <DocumentHistory key={history.document_id} {...{ active, onInvalid }} disabled={blocked} path={path + '/' + history.document_id} onClose={() => setHistory(null)} />}
  </section>
}
function DocumentContent({ document, zone }: { document: CompetitionDocument; zone: string }) {
  const link = safeCompetitionUrl(document.source_url)
  return <>
    <h4 className="font-bold [overflow-wrap:anywhere]">{document.title}</h4><p>{text(document.kind)} · v{document.number} · {calendarDate(document.occurred_at, zone)}</p>
    <p className="whitespace-pre-wrap [overflow-wrap:anywhere]">{document.body}</p><p className="whitespace-pre-wrap [overflow-wrap:anywhere]">{text('reason')} : {document.reason}</p>
    {link && <a className="break-all text-cyan-300 underline" href={link} target="_blank" rel="noopener noreferrer" referrerPolicy="no-referrer">{text('openSource')}</a>}
  </>
}
function DocumentForm({ item, disabled, errors, onCancel, onSubmit }: { item: CompetitionDocument | null; disabled: boolean; errors: Record<string, string[]>; onCancel: () => void; onSubmit: (event: FormEvent<HTMLFormElement>, body: Record<string, unknown>) => void }) {
  const id = useId()
  return <form aria-label={text('saveDocument')} className="grid min-w-0 gap-3 rounded-lg border border-amber-400/30 p-3" onSubmit={(event) => {
    const values = new FormData(event.currentTarget), value = (key: string) => String(values.get(key) ?? '').trim()
    onSubmit(event, { ...(item ? { version: item.version } : { kind: value('kind') }), title: value('title'), body: value('body'), source_url: value('source_url') || null, reason: value('reason') })
  }}>
    {item ? <p>{text(item.kind)} · {text('fixedKind')}</p> : <SelectField name="kind" title={text('kind')} defaultValue="rules" required disabled={disabled} error={errors.kind?.[0]}><option value="rules">{text('rules')}</option><option value="note">{text('note')}</option></SelectField>}
    <FormField name="title" label={text('documentTitle')} defaultValue={item?.title ?? ''} maxLength={200} required disabled={disabled} error={errors.title?.[0]} />
    <label htmlFor={id}>{text('documentBody')}</label><textarea id={id} name="body" defaultValue={item?.body ?? ''} rows={7} maxLength={20000} required disabled={disabled} aria-invalid={!!errors.body} aria-describedby={errors.body ? id + '-error' : undefined} className="min-w-0 w-full rounded-xl border border-white/15 bg-slate-900 p-3" />
    {errors.body?.[0] && <p id={id + '-error'} className="text-rose-200">{errors.body[0]}</p>}
    <FormField name="source_url" label={text('sourceUrl')} type="url" pattern="https://.*" defaultValue={item?.source_url ?? ''} maxLength={2000} disabled={disabled} error={errors.source_url?.[0]} />
    <FormField name="reason" label={text('reason')} minLength={10} maxLength={1000} required disabled={disabled} error={errors.reason?.[0]} />
    <div className="flex flex-wrap gap-3"><Button type="submit" disabled={disabled}>{text('saveDocument')}</Button><Button disabled={disabled} onClick={onCancel}>{text('cancel')}</Button></div>
  </form>
}
function DocumentHistory({ active, path, disabled, onInvalid, onClose }: { active: ActiveOrganization; path: string; disabled: boolean; onInvalid: () => void; onClose: () => void }) {
  const [page, setPage] = useState(1)
  const { list, loading, error } = usePartnershipList<CompetitionDocument, Page<CompetitionDocument>>(path + '/history?page=' + page + '&per_page=5', active, true, onInvalid)
  return <section aria-label={text('documentHistory')} className="grid min-w-0 gap-3 rounded-lg border border-slate-700 p-3"><h4 className="font-bold">{text('documentHistory')}</h4><Button disabled={disabled} onClick={onClose}>{text('closeHistory')}</Button>
    {loading && <p role="status">{text('loading')}</p>}{error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
    <ol className="grid min-w-0 gap-3">{list?.data.map((document) => <li key={document.version} className="grid min-w-0 gap-3 border-b border-slate-700 pb-3"><DocumentContent {...{ document }} zone={active.context!.timezone} />
      <DocumentDownload {...{ active, onInvalid, disabled }} path={path + '/versions/' + document.version + '/download'} version={document.version} />
    </li>)}</ol>
    {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={disabled || loading} onPageChange={setPage} />}
  </section>
}
function DocumentDownload({ active, path, version, disabled, onInvalid }: { active: ActiveOrganization; path: string; version: string; disabled: boolean; onInvalid: () => void }) {
  const [busy, setBusy] = useState(false), [error, setError] = useState<ReturnType<typeof apiError> | null>(null)
  const controller = useRef<AbortController | null>(null), alive = useRef(true)
  const { notify } = useNotifications()
  useEffect(() => { alive.current = true; return () => { alive.current = false; controller.current?.abort() } }, [])
  async function download() {
    if (busy || disabled) return
    const transfer = new AbortController(); controller.current = transfer; setBusy(true); setError(null)
    try {
      const blob = await tenancyRequest<Blob>(path, { download: true, revision: active.revision ?? undefined, signal: transfer.signal })
      if (!alive.current || transfer.signal.aborted) return
      const url = URL.createObjectURL(blob), anchor = document.createElement('a')
      anchor.href = url; anchor.download = 'competition-document-' + version + '.txt'; anchor.click()
      setTimeout(() => URL.revokeObjectURL(url), 0)
      notify({ type: 'success', message: text('downloaded') })
    } catch (failure) {
      if (!alive.current || transfer.signal.aborted) return
      const next = apiError(failure); setError(next); notify({ type: 'warning', message: next.message, requestId: next.requestId })
      if (shouldReloadContext(next)) onInvalid()
    } finally { if (alive.current && !transfer.signal.aborted) setBusy(false) }
  }
  return <div className="grid min-w-0 gap-2">{error && <Alert tone="error" title={error.message} requestId={error.requestId} />}<Button disabled={disabled || busy} onClick={() => void download()}>{text('download')}</Button></div>
}
