import { useEffect, useState } from 'react'
import { tenancyRequest, type ActiveOrganization } from '../../api/tenancy'
import type { ApiSuccessResponse } from '../../api/contracts'
import type { ExternalCompetition } from '../../api/externalCompetitions'
import { safeCompetitionUrl } from '../../api/externalCompetitions'
import { awardInput, outcomeText as text, type CompetitionOutcome, type OutcomeResult, type OutcomeStream } from '../../api/competitionOutcomes'
import { calendarDate } from '../../api/calendar'
import { apiError, shouldReloadContext } from '../people/peopleFormValues'
import { usePeopleMutation } from '../people/usePeopleMutation'
import { usePartnershipList } from '../partnerships/usePartnershipList'
import { Button } from '../Button'
import { Alert } from '../feedback/Alert'
import { Pagination } from '../data/Pagination'
import { CompetitionOutcomeForm } from './CompetitionOutcomeForm'
type Props = { active: ActiveOrganization; root: string; item: ExternalCompetition; stream: OutcomeStream; disabled: boolean; onInvalid: () => void; onClose: () => void }
export function CompetitionOutcomePanel({ active, root, item, stream, disabled, onInvalid, onClose }: Props) {
  const [record, setRecord] = useState<CompetitionOutcome | null>(null), [loaded, setLoaded] = useState(false), [editing, setEditing] = useState(false), [history, setHistory] = useState(false), [refresh, setRefresh] = useState(0)
  const [error, setError] = useState<ReturnType<typeof apiError> | null>(null)
  const path = root + '/' + item.id + '/outcomes/' + stream, permissions = active.context?.permissions ?? []
  const allowed = ['sports.read', 'sports.competitions.read', ...(stream === 'awards' ? ['financial.read'] : [])].every((p) => permissions.includes(p)), writable = allowed && permissions.includes(stream === 'awards' ? 'financial.write' : 'sports.competitions.write') && item.status === 'active'
  useEffect(() => {
    const controller = new AbortController(); if (!allowed) return () => controller.abort()
    const load = async () => {
      setLoaded(false); setRecord(null); setError(null)
      try { const result = await tenancyRequest<ApiSuccessResponse<CompetitionOutcome | null>>(path, { signal: controller.signal, revision: active.revision ?? undefined }); if (!controller.signal.aborted) { setRecord(result.data); setLoaded(true) } }
      catch (failure) { if (!controller.signal.aborted) { const next = apiError(failure); setError(next); if (shouldReloadContext(next)) onInvalid() } }
    }; void load()
    return () => controller.abort()
  }, [path, allowed, active.revision, onInvalid, refresh])
  const mutation = usePeopleMutation<CompetitionOutcome>(active, () => { setEditing(false); setHistory(false); setRefresh((n) => n + 1) }, onInvalid), blocked = disabled || mutation.blocked
  if (!allowed) return null
  return <section aria-label={text(stream)} className="grid min-w-0 gap-3 rounded-xl border border-cyan-400/30 p-3"><h3 className="font-bold [overflow-wrap:anywhere]">{text(stream)} · {item.name}</h3><p className="text-sm text-slate-400">{text(stream === 'sport' ? 'hint' : 'financialHint')}</p>
    <Button disabled={blocked} onClick={onClose}>{text('close')}</Button>{mutation.feedback}{!loaded && !error && <p role="status">{text('loading')}</p>}{error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
    <Button disabled={blocked} onClick={() => { setEditing(false); setHistory(false); setRefresh((n) => n + 1) }}>{text('refresh')}</Button>
    {loaded && !record && <p>{text('empty')}</p>}{record && <OutcomeView key={record.version + ':' + refresh} record={record} active={active} path={path} onInvalid={onInvalid} disabled={blocked} />}
    {loaded && writable && !editing && <Button disabled={blocked} onClick={() => { setEditing(true); setHistory(false) }}>{text('edit')}</Button>}
    {editing && loaded && writable && <CompetitionOutcomeForm key={record?.version ?? 'new'} {...{ active, path, stream, record, onInvalid }} disabled={blocked} errors={mutation.errors(path)} onCancel={() => setEditing(false)} onSubmit={(event, body) => mutation.propose(event, text('save'), path, { ...body, competition_version: item.version }, true)} />}
    {record && <Button disabled={blocked} onClick={() => { setEditing(false); setHistory(!history) }}>{text('history')}</Button>}{history && <OutcomeHistory {...{ active, path, onInvalid }} disabled={blocked} />}
  </section>
}
function OutcomeView({ record, active, path, onInvalid, disabled }: { record: CompetitionOutcome; active: ActiveOrganization; path?: string; onInvalid: () => void; disabled: boolean }) {
  const p = record.payload, source = safeCompetitionUrl(p.source_url)
  return <div className="grid min-w-0 gap-2 [overflow-wrap:anywhere]"><p>{text('source')} : {p.source}</p><p>{text('observed_on')} : {p.observed_on}</p>{source && <a className="break-all text-cyan-300 underline" href={source} target="_blank" rel="noopener noreferrer" referrerPolicy="no-referrer">{text('sourceLink')}</a>}
    {p.notes && <p className="whitespace-pre-wrap">{p.notes}</p>}
    {record.stream === 'sport' ? <><p>{text('place')} : {p.place ?? text('unknown')} · {text('field_size')} : {p.field_size ?? text('unknown')}</p><h4 className="font-bold">{text('matches')}</h4>{record.matches.length === 0 && <p>{text('noMatches')}</p>}<ol className="grid min-w-0 gap-3">{record.matches.map((l) => <li key={l.reference_id}><p>{l.match?.title ?? text('hidden')}</p>{path && l.match && <OutcomeMatchResult {...{ active, onInvalid, disabled }} path={path + '/matches/' + l.reference_id + '/result'} />}</li>)}</ol></> : <>
      {(['announced_minor', 'received_minor'] as const).map((key) => <p key={key}>{text(key)} : {p[key] == null ? text('unknown') : awardInput(p[key], p.currency!) + ' ' + p.currency}</p>)}<p>{text('received_on')} : {p.received_on ?? text('unknown')}</p>
    </>}
  </div>
}
function OutcomeMatchResult({ active, path, disabled, onInvalid }: { active: ActiveOrganization; path: string; disabled: boolean; onInvalid: () => void }) {
  const [open, setOpen] = useState(false), [result, setResult] = useState<OutcomeResult | null>(null), [error, setError] = useState<ReturnType<typeof apiError> | null>(null)
  useEffect(() => {
    if (!open) return
    const controller = new AbortController()
    const load = async () => {
      setResult(null); setError(null)
      try { const result = await tenancyRequest<ApiSuccessResponse<OutcomeResult>>(path, { signal: controller.signal, revision: active.revision ?? undefined }); if (!controller.signal.aborted) setResult(result.data) }
      catch (failure) { if (!controller.signal.aborted) { const next = apiError(failure); setError(next); if (shouldReloadContext(next)) onInvalid() } }
    }; void load()
    return () => controller.abort()
  }, [open, path, active.revision, onInvalid])
  return <div className="grid min-w-0 gap-2"><Button disabled={disabled} onClick={() => setOpen(!open)}>{text('result')}</Button>{open && <>{!result && !error && <p role="status">{text('loading')}</p>}{error && <Alert tone="error" title={error.message} />}{result && <><p>{text(result.approved ? 'approved' : 'unapproved')} · {calendarDate(result.observed_at, active.context!.timezone)}</p>{result.approved && result.outcome && <p>{text(result.outcome)}</p>}{result.approved && result.winners?.length > 0 && <p>{text('winners')} : {result.winners.map((p) => p.label).join(', ')}</p>}{result.approved && result.series?.standings && <ul aria-label={text('series')}>{result.series.standings.map((p) => <li key={p.participant_id}>{p.label} : {p.score}</li>)}</ul>}</>}</>}</div>
}
function OutcomeHistory({ active, path, disabled, onInvalid }: { active: ActiveOrganization; path: string; disabled: boolean; onInvalid: () => void }) {
  const [page, setPage] = useState(1), { list, loading, error } = usePartnershipList<CompetitionOutcome>(path + '/history?page=' + page + '&per_page=5', active, true, onInvalid)
  return <section aria-label={text('history')} className="grid min-w-0 gap-3"><h4 className="font-bold">{text('history')}</h4>{loading && <p role="status">{text('loading')}</p>}{error && <Alert tone="error" title={error.message} />}
    <ol className="grid min-w-0 gap-3">{list?.data.map((row) => <li key={row.version} className="grid min-w-0 gap-2 border-b border-slate-700 pb-3"><p>v{row.number} · {calendarDate(row.occurred_at, active.context!.timezone)}</p><p className="whitespace-pre-wrap [overflow-wrap:anywhere]">{row.reason}</p><OutcomeView record={row} {...{ active, onInvalid, disabled }} /></li>)}</ol>
    {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={disabled || loading} onPageChange={setPage} />}
  </section>
}
