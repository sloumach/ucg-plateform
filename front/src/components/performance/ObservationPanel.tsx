import { useState } from 'react'
import type { ActiveOrganization } from '../../api/tenancy'
import { observationText as text, observationSources, type StatisticalObservation } from '../../api/observations'
import { metricText } from '../../api/metricCatalogue'
import { usePeopleMutation } from '../people/usePeopleMutation'
import { usePartnershipList } from '../partnerships/usePartnershipList'
import { SelectField } from '../people/peopleForms'
import { Button } from '../Button'
import { Alert } from '../feedback/Alert'
import { Pagination } from '../data/Pagination'
import { ObservationForm } from './ObservationForm'

type Props = { active: ActiveOrganization; disabled: boolean; onInvalid: () => void }
export function ObservationPanel({ active, disabled, onInvalid }: Props) {
  const [open, setOpen] = useState(false), [page, setPage] = useState(1), [source, setSource] = useState(''), [status, setStatus] = useState('active'), [refresh, setRefresh] = useState(0)
  const [editing, setEditing] = useState<StatisticalObservation | 'new' | null>(null), [history, setHistory] = useState<StatisticalObservation | null>(null)
  const permissions = active.context?.permissions ?? [], allowed = permissions.includes('sports.read') && permissions.includes('sports.statistics.read'), create = permissions.includes('sports.write') && permissions.includes('sports.statistics.write'), correct = permissions.includes('sports.statistics.correct')
  const root = 'tenants/' + active.context?.organization_id + '/observations'
  const { list, loading, error } = usePartnershipList<StatisticalObservation>(root + '?per_page=10&page=' + page + '&status=' + status + (source ? '&source_kind=' + source : ''), active, open && allowed, onInvalid, refresh)
  const mutation = usePeopleMutation<StatisticalObservation>(active, () => { setEditing(null); setHistory(null); setRefresh((n) => n + 1) }, onInvalid), blocked = disabled || mutation.blocked
  if (!allowed) return null
  return <section aria-label={text('title')} className="grid min-w-0 gap-4 rounded-2xl border border-white/15 p-4"><h2 className="font-bold">{text('title')}</h2><p>{text('hint')}</p><Button disabled={blocked} onClick={() => { setOpen(!open); setEditing(null); setHistory(null) }}>{text(open ? 'close' : 'open')}</Button>
    {open && <>{mutation.feedback}<div className="grid min-w-0 gap-3"><SelectField name="source_filter" title={text('source_kind')} value={source} onChange={(v) => { setSource(v); setPage(1); setEditing(null); setHistory(null) }} disabled={blocked}><option value="">{text('all')}</option>{observationSources.map((s) => <option key={s} value={s}>{text(s)}</option>)}</SelectField><SelectField name="status_filter" title={text('status')} value={status} onChange={(v) => { setStatus(v); setPage(1); setEditing(null); setHistory(null) }} disabled={blocked}>{['active', 'voided'].map((s) => <option key={s} value={s}>{text(s)}</option>)}</SelectField></div>
      <Button disabled={blocked || loading} onClick={() => { setEditing(null); setHistory(null); setRefresh((n) => n + 1) }}>{text('refresh')}</Button>
      {loading && <p role="status">{text('loading')}</p>}{error && <Alert tone="error" title={error.message} requestId={error.requestId} />}{list?.data.length === 0 && <p>{text('empty')}</p>}
      {list && create && !editing && <Button disabled={blocked} onClick={() => { setEditing('new'); setHistory(null) }}>{text('create')}</Button>}
      {editing && ((editing === 'new' && create) || (editing !== 'new' && correct)) && <ObservationForm key={editing === 'new' ? 'new' : editing.version} {...{ active, root, onInvalid }} record={editing === 'new' ? null : editing} disabled={blocked} errors={mutation.errors(root + (editing === 'new' ? '' : '/' + editing.id))} onCancel={() => setEditing(null)} onSubmit={(event, body) => mutation.propose(event, text(editing === 'new' ? 'create' : 'correct'), root + (editing === 'new' ? '' : '/' + editing.id), body, editing !== 'new')} />}
      <ul className="grid min-w-0 gap-3">{list?.data.map((row) => <li key={row.id} className="grid min-w-0 gap-3 rounded-xl border border-slate-700 p-3"><ObservationView row={row} />{correct && <Button disabled={blocked} onClick={() => { setEditing(row); setHistory(null) }}>{text('correct')}</Button>}<Button disabled={blocked} onClick={() => { setEditing(null); setHistory(row) }}>{text('history')}</Button></li>)}</ul>
      {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={blocked || loading} onPageChange={(n) => { setPage(n); setEditing(null); setHistory(null) }} />}
      {history && <ObservationHistory key={history.id} {...{ active, root, onInvalid }} record={history} disabled={blocked} onClose={() => setHistory(null)} />}
    </>}
  </section>
}
function ObservationView({ row }: { row: StatisticalObservation }) {
  return <div className="grid min-w-0 gap-2 whitespace-pre-wrap [overflow-wrap:anywhere]"><h3 className="font-bold">{row.person_label ?? text('hiddenPerson')} · {row.context.metric.code} · {row.context.metric.title}</h3><p>{text('value')} : {row.availability === 'available' ? row.value : text('unavailable')} · {row.context.metric.unit} · {text(row.status)}</p>{row.missing_reason && <p>{row.missing_reason}</p>}<p>{text('source_label')} : {row.source_label} · {text(row.source_kind)} · {text(row.acquired_via)}</p><p>{row.period_start} → {row.period_end} · {text('observed_on')} : {row.observed_on} · {text('sample_size')} : {row.sample_size}</p><p>{text('metricVersion')} : v{row.context.metric.number} · {metricText('level.' + row.context.metric.aggregation_level)} · {metricText(row.context.metric.measurement_kind)}</p><p>{metricText('mode_id')} : {row.context.game_context.mode_name ?? '—'} · {metricText('role_id')} : {row.context.game_context.role_name ?? '—'} · {metricText('release_id')} : {row.context.game_context.release_name ?? '—'}</p><p>{text('formula')} : {row.context.metric.formula}</p>{(['region', 'season', 'map', 'opponent', 'round_number', 'session_label'] as const).map((f) => row.context[f] != null && <p key={f}>{text(f)} : {row.context[f]}</p>)}<p>{text('revision')} {row.number} · {row.occurred_at}</p>{row.notes && <p>{row.notes}</p>}<p>{text('reason')} : {row.reason}</p></div>
}
function ObservationHistory({ active, root, record, disabled, onInvalid, onClose }: Props & { root: string; record: StatisticalObservation; onClose: () => void }) {
  const [page, setPage] = useState(1), { list, loading, error } = usePartnershipList<StatisticalObservation>(root + '/' + record.id + '/history?per_page=5&page=' + page, active, true, onInvalid)
  return <section aria-label={text('history')} className="grid min-w-0 gap-3"><h3 className="font-bold">{text('history')}</h3><Button disabled={disabled} onClick={onClose}>{text('closeHistory')}</Button>{loading && <p role="status">{text('loading')}</p>}{error && <Alert tone="error" title={error.message} />}{list?.data.map((row) => <div key={row.version} className="rounded-xl border border-slate-700 p-3"><ObservationView row={row} /></div>)}{list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} onPageChange={setPage} disabled={disabled || loading} />}</section>
}
