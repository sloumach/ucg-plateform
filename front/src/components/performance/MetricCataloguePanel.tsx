import { useEffect, useRef, useState, type FormEvent } from 'react'
import { tenancyRequest, type Page } from '../../api/tenancy'
import type { ApiSuccessResponse } from '../../api/contracts'
import { metricText as text, type MetricDefinition, type MetricPage } from '../../api/metricCatalogue'
import { apiError } from '../people/peopleFormValues'
import { MetricCatalogueForm, MetricReference } from './MetricCatalogueForm'
import { Button } from '../Button'
import { Alert } from '../feedback/Alert'
import { Pagination } from '../data/Pagination'
import { SelectField } from '../people/peopleForms'
import { useNotifications } from '../feedback/notificationContext'
type Pending = { body: Record<string, unknown>; path: string; form: HTMLFormElement; method: 'POST' | 'PATCH' }
export function MetricCataloguePanel() {
  const [open, setOpen] = useState(false), [page, setPage] = useState(1), [game, setGame] = useState(''), [status, setStatus] = useState('active'), [refresh, setRefresh] = useState(0)
  const [list, setList] = useState<MetricPage | null>(null), [loading, setLoading] = useState(false), [error, setError] = useState<ReturnType<typeof apiError> | null>(null)
  const [editor, setEditor] = useState<MetricDefinition | 'new' | null>(null), [history, setHistory] = useState<MetricDefinition | null>(null), [pending, setPending] = useState<Pending | null>(null), [confirmed, setConfirmed] = useState(false), [busy, setBusy] = useState(false)
  const alive = useRef(true), region = useRef<HTMLDivElement>(null), returnFocus = useRef<HTMLElement | null>(null), { notify } = useNotifications()
  useEffect(() => { alive.current = true; return () => { alive.current = false } }, [])
  useEffect(() => { if (pending) region.current?.focus(); else if (!busy) returnFocus.current?.focus() }, [pending, busy])
  useEffect(() => {
    if (!open) return
    const controller = new AbortController(), load = async () => {
      setLoading(true); setList(null); setError(null); setEditor(null); setHistory(null)
      try { const next = await tenancyRequest<MetricPage>('metric-catalogue?per_page=10&page=' + page + '&status=' + status + (game ? '&game_id=' + game : ''), { signal: controller.signal }); if (!controller.signal.aborted) setList(next) }
      catch (e) { if (!controller.signal.aborted) setError(apiError(e)) }
      finally { if (!controller.signal.aborted) setLoading(false) }
    }; void load(); return () => controller.abort()
  }, [open, page, status, game, refresh])
  const blocked = busy || loading || pending !== null
  function propose(e: FormEvent<HTMLFormElement>, body: Record<string, unknown>) {
    e.preventDefault(); if (!list || !editor || blocked) return
    setError(null); setConfirmed(false); returnFocus.current = e.currentTarget.querySelector('button[type="submit"]')
    setPending({ body: { ...body, catalogue_version: list.meta.catalogue_version, game_catalogue_version: list.meta.game_catalogue_version, confirm_global: true }, path: 'metric-catalogue' + (editor === 'new' ? '' : '/' + editor.id), method: editor === 'new' ? 'POST' : 'PATCH', form: e.currentTarget })
  }
  async function save() {
    if (!pending || !confirmed || busy) return
    const action = pending; setBusy(true)
    try {
      const result = await tenancyRequest<ApiSuccessResponse<MetricDefinition>>(action.path, { method: action.method, body: action.body })
      if (!alive.current) return
      notify(result.notification ?? { type: 'success', message: text('save') }); setPending(null); setEditor(null); setRefresh((n) => n + 1)
    } catch (e) {
      if (!alive.current) return
      const next = apiError(e); setError(next); setPending(null)
      const name = Object.keys(next.fieldErrors)[0], field = Array.from(action.form.elements).find((f) => f.getAttribute('name') === name)
      if (field instanceof HTMLElement) returnFocus.current = field
      if ([401, 403, 404, 409].includes(next.status ?? 0)) { notify({ type: 'warning', message: next.message, requestId: next.requestId }); setEditor(null); setRefresh((n) => n + 1) }
    } finally { if (alive.current) setBusy(false) }
  }
  return <section aria-label={text('title')} className="grid min-w-0 gap-4 rounded-2xl border border-white/10 p-4"><h2 className="text-xl font-bold">{text('title')}</h2><p className="text-sm text-slate-400">{text('hint')}</p><Button disabled={blocked} onClick={() => { setOpen(!open); setEditor(null); setHistory(null); setError(null) }}>{text(open ? 'close' : 'open')}</Button>
    {open && <><p className="text-sm text-slate-400">{text('formulaHint')}</p>{error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
      <MetricReference kind="game" name="game_id" value={game} onChange={(v) => { setGame(v); setPage(1) }} disabled={blocked} /><SelectField name="filter_status" title={text('status')} value={status} onChange={(v) => { setStatus(v); setPage(1) }} disabled={blocked}>{['active', 'retired'].map((v) => <option key={v} value={v}>{text(v)}</option>)}</SelectField>
      <Button disabled={blocked} onClick={() => setRefresh((n) => n + 1)}>{text('refresh')}</Button>{loading && <p role="status">{text('loading')}</p>}{list?.meta.can_manage && <Button disabled={blocked} onClick={() => { setHistory(null); setError(null); setEditor('new') }}>{text('create')}</Button>}{list?.data.length === 0 && <p>{text('empty')}</p>}
      <ol className="grid min-w-0 gap-3">{list?.data.map((row) => <li key={row.id} className="grid min-w-0 gap-3 rounded-xl border border-slate-700 p-3"><MetricView record={row} /><div className="flex flex-wrap gap-3"><Button disabled={blocked} onClick={() => { setEditor(null); setHistory(row) }}>{text('history')}</Button>{list.meta.can_manage && <Button disabled={blocked} onClick={() => { setHistory(null); setError(null); setEditor(row) }}>{text('edit')}</Button>}</div></li>)}</ol>
      {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={blocked} onPageChange={setPage} />}
      {pending && <div ref={region} role="region" tabIndex={-1} aria-label={text('confirmSave')} className="grid min-w-0 gap-3 rounded-xl border border-amber-400/40 p-3"><p>{text('globalWarning')}</p><label className="flex min-w-0 items-start gap-2"><input type="checkbox" checked={confirmed} onChange={(e) => setConfirmed(e.target.checked)} disabled={busy} />{text('confirmGlobal')}</label><div className="flex flex-wrap gap-3"><Button disabled={!confirmed || busy} onClick={() => void save()}>{text('confirmSave')}</Button><Button disabled={busy} onClick={() => setPending(null)}>{text('cancel')}</Button></div></div>}
      {editor && list?.meta.can_manage && <MetricCatalogueForm key={editor === 'new' ? 'new' : editor.version} record={editor === 'new' ? null : editor} disabled={blocked} errors={error?.fieldErrors ?? {}} onCancel={() => setEditor(null)} onSubmit={propose} />}
      {history && <MetricHistory key={history.id} metric={history.id} disabled={blocked} onClose={() => setHistory(null)} />}
    </>}
  </section>
}
function MetricView({ record: r }: { record: MetricDefinition }) {
  return <div className="grid min-w-0 gap-2 [overflow-wrap:anywhere]"><h3 className="font-bold">{r.title}</h3><p>{r.code} · {r.scope.game_name} · {text(r.status)}</p><p className="whitespace-pre-wrap">{r.definition}</p><p>{text('aggregation_level')} : {text('level.' + r.aggregation_level)} · {text('unit')} : {r.unit} · {text('aggregation')} : {text(r.aggregation)}</p><p className="whitespace-pre-wrap">{text('formula')} : {r.formula}</p><p>{text('zero_denominator')} : {text(r.zero_denominator)} · {text(r.measurement_kind)}</p><p>{text('availability')} : {text(r.availability)}</p><p className="whitespace-pre-wrap">{r.availability_note}</p><p>{text('mode_id')} : {r.scope.mode_name ?? text('all')} · {text('role_id')} : {r.scope.role_name ?? text('all')} · {text('release_id')} : {r.scope.release_name ?? text('all')}</p><p className="text-sm text-slate-400">{text('version')} : v{r.number} · {r.version}</p></div>
}
function MetricHistory({ metric, disabled, onClose }: { metric: string; disabled: boolean; onClose: () => void }) {
  const [page, setPage] = useState(1), [list, setList] = useState<Page<MetricDefinition> | null>(null), [error, setError] = useState<ReturnType<typeof apiError> | null>(null)
  useEffect(() => { const controller = new AbortController(), load = async () => {
    setList(null); setError(null)
    try { const data = await tenancyRequest<Page<MetricDefinition>>('metric-catalogue/' + metric + '/history?page=' + page + '&per_page=5', { signal: controller.signal }); if (!controller.signal.aborted) setList(data) }
    catch (e) { if (!controller.signal.aborted) setError(apiError(e)) }
  }; void load(); return () => controller.abort() }, [metric, page])
  return <section aria-label={text('history')} className="grid min-w-0 gap-3"><h3 className="font-bold">{text('history')}</h3><Button disabled={disabled} onClick={onClose}>{text('closeHistory')}</Button>{error && <Alert tone="error" title={error.message} />}{!list && !error && <p role="status">{text('loading')}</p>}<ol className="grid min-w-0 gap-3">{list?.data.map((r) => <li key={r.version} className="grid min-w-0 gap-2 border-b border-slate-700 pb-3"><MetricView record={r} /><p className="whitespace-pre-wrap [overflow-wrap:anywhere]">{r.reason} · {r.occurred_at}</p></li>)}</ol>{list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={disabled} onPageChange={setPage} />}</section>
}
