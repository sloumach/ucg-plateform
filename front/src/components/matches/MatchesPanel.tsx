import { useState } from 'react'
import type { ActiveOrganization, Page } from '../../api/tenancy'
import { calendarDate } from '../../api/calendar'
import { matchStatuses, matchText as text, type MatchRecord, type MatchPage, type MatchStatusEvent } from '../../api/matches'
import { usePartnershipList } from '../partnerships/usePartnershipList'
import { usePeopleMutation } from '../people/usePeopleMutation'
import { SelectField } from '../people/peopleForms'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { FormField } from '../FormField'
import { Button } from '../Button'
import { MatchForm } from './MatchForm'
import { MatchNotebook } from './MatchNotebook'
import { MatchResults } from './MatchResults'
import { MatchScores } from './MatchScores'
import { MatchLineups } from './MatchLineups'
import type { MatchParticipant } from '../../api/matches'

type Props = { active: ActiveOrganization; disabled: boolean; onInvalid: () => void }
export function MatchesPanel({ active, disabled, onInvalid }: Props) {
  const [open, setOpen] = useState(false), [page, setPage] = useState(1), [refresh, setRefresh] = useState(0)
  const [filters, setFilters] = useState({ kind: '', status: '', management: false })
  const [editor, setEditor] = useState<MatchRecord | 'new' | null>(null), [transition, setTransition] = useState<MatchRecord | null>(null), [history, setHistory] = useState<MatchRecord | null>(null)
  const [notebook, setNotebook] = useState<MatchRecord | null>(null)
  const [results, setResults] = useState<MatchRecord | null>(null)
  const [scores, setScores] = useState<MatchRecord | null>(null)
  const [lineups, setLineups] = useState<{ item: MatchRecord; participant: MatchParticipant } | null>(null)
  const permissions = active.context?.permissions ?? [], allowed = permissions.includes('sports.read'), canWrite = permissions.includes('sports.write')
  const root = 'tenants/' + active.context!.organization_id + '/matches', zone = active.context!.timezone
  const params = new URLSearchParams({ page: String(page), per_page: '10', ...(filters.kind ? { kind: filters.kind } : {}), ...(filters.status ? { status: filters.status } : {}), ...(filters.management ? { management: '1' } : {}) })
  const { list, loading, error } = usePartnershipList<MatchRecord, MatchPage>(root + '?' + params, active, open && allowed, onInvalid, refresh)
  const mutation = usePeopleMutation<MatchRecord>(active, () => { setEditor(null); setTransition(null); setHistory(null); setNotebook(null); setResults(null); setScores(null); setLineups(null); setRefresh((n) => n + 1) }, onInvalid)
  const blocked = disabled || mutation.blocked
  if (!allowed) return null
  return <section aria-label={text('title')} className="grid min-w-0 gap-4 rounded-2xl border border-white/10 p-4">
    <h2 className="text-xl font-bold">{text('title')}</h2><p className="text-sm text-slate-400">{text('hint')}</p>
    <Button disabled={blocked} onClick={() => { setOpen(!open); setNotebook(null); setResults(null); setScores(null); setLineups(null); setEditor(null); setTransition(null); setHistory(null) }}>{text(open ? 'close' : 'open')}</Button>
    {open && <>
      {mutation.feedback}
      <p className="text-sm text-amber-200">{text('resultHint')}</p>
      <form aria-label={text('filters')} onSubmit={(event) => {
        event.preventDefault(); const values = new FormData(event.currentTarget)
        setFilters({ kind: String(values.get('kind') ?? ''), status: String(values.get('status') ?? ''), management: canWrite && values.has('management') })
        setPage(1); setNotebook(null); setResults(null); setScores(null); setLineups(null); setEditor(null); setTransition(null); setHistory(null)
      }} className="grid min-w-0 gap-3 sm:grid-cols-2">
        <SelectField name="kind" title={text('kind')} disabled={blocked}><option value="">{text('all')}</option><option value="scrim">{text('kind.scrim')}</option><option value="official">{text('kind.official')}</option></SelectField>
        <SelectField name="status" title={text('status')} disabled={blocked}><option value="">{text('all')}</option>{matchStatuses.map((status) => <option key={status} value={status}>{text('status.' + status)}</option>)}</SelectField>
        {canWrite && <FormField name="management" label={text('management')} type="checkbox" disabled={blocked} />}
        <div className="flex flex-wrap gap-3 sm:col-span-2"><Button type="submit" disabled={blocked}>{text('filter')}</Button><Button disabled={blocked || loading} onClick={() => { setNotebook(null); setResults(null); setScores(null); setLineups(null); setRefresh((n) => n + 1) }}>{text('refresh')}</Button></div>
      </form>
      {filters.management && <p className="text-sm text-amber-200">{text('managementHint')}</p>}
      {canWrite && <Button disabled={blocked} onClick={() => { setNotebook(null); setResults(null); setScores(null); setLineups(null); setEditor('new'); setTransition(null); setHistory(null) }}>{text('create')}</Button>}
      {loading && <p role="status">{text('loading')}</p>}
      {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
      {list?.data.length === 0 && <p>{text('empty')}</p>}
      <ol className="grid min-w-0 gap-3">{list?.data.map((item) => <li key={item.id} className="grid min-w-0 gap-2 rounded-xl border border-slate-700 p-3">
        <h3 className="font-bold [overflow-wrap:anywhere]">{item.title}</h3>
        <p className="[overflow-wrap:anywhere]">{item.game_name} · {text('kind.' + item.kind)} · {text('format.' + item.format)}</p>
        <p>{text('status.' + item.status)} · {text('visibility.' + item.visibility)}</p>
        <p className="[overflow-wrap:anywhere]">{calendarDate(item.starts_at, zone)} → {calendarDate(item.ends_at, zone)}</p>
        <p className="text-sm text-slate-400 [overflow-wrap:anywhere]">{text('origin')} : {item.origin_timezone}</p>
        <p className="[overflow-wrap:anywhere]">{text('responsible')} : {item.responsible_label}</p>
        <ol className="grid min-w-0 gap-2">{item.participants.map((p) => <li key={p.id} className="grid min-w-0 gap-2 [overflow-wrap:anywhere]">
          <span>{p.slot}. {text('source.' + p.kind)} · {p.label}</span>
          {p.kind === 'team' && <Button disabled={blocked} onClick={() => { setNotebook(null); setResults(null); setScores(null); setLineups({ item, participant: p }); setEditor(null); setTransition(null); setHistory(null) }}>{text('lineups')} · {p.label}</Button>}
        </li>)}</ol>
        <div className="flex flex-wrap gap-3">
          <Button disabled={blocked} onClick={() => { setNotebook(item); setResults(null); setScores(null); setLineups(null); setEditor(null); setTransition(null); setHistory(null) }}>{text('notebook.title')}</Button>
          <Button disabled={blocked} onClick={() => { setNotebook(null); setResults(item); setScores(null); setLineups(null); setEditor(null); setTransition(null); setHistory(null) }}>{text('results.title')}</Button>
          <Button disabled={blocked} onClick={() => { setNotebook(null); setResults(null); setScores(item); setLineups(null); setEditor(null); setTransition(null); setHistory(null) }}>{text('scores.title')}</Button>
          <Button disabled={blocked} onClick={() => { setNotebook(null); setResults(null); setScores(null); setLineups(null); setHistory(item); setEditor(null); setTransition(null) }}>{text('history')}</Button>
          {canWrite && item.editable && <Button disabled={blocked} onClick={() => { setNotebook(null); setResults(null); setScores(null); setLineups(null); setEditor(item); setHistory(null); setTransition(null) }}>{text('edit')}</Button>}
          {canWrite && item.available_transitions.length > 0 && <Button disabled={blocked} onClick={() => { setNotebook(null); setResults(null); setScores(null); setLineups(null); setTransition(item); setEditor(null); setHistory(null) }}>{text('transition')}</Button>}
        </div>
      </li>)}</ol>
      {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={blocked || loading} onPageChange={(next) => { setPage(next); setNotebook(null); setResults(null); setScores(null); setLineups(null); setEditor(null); setTransition(null); setHistory(null) }} />}
      {editor && <MatchForm key={editor === 'new' ? 'new' : editor.id + editor.version} {...{ active, root, onInvalid }} item={editor === 'new' ? null : editor} disabled={blocked}
        errors={mutation.errors(root + (editor === 'new' ? '' : '/' + editor.id))} onCancel={() => setEditor(null)}
        onSubmit={(event, body) => mutation.propose(event, text('save'), root + (editor === 'new' ? '' : '/' + editor.id), { ...body, ...(editor === 'new' ? {} : { version: editor.version }) }, editor !== 'new')} />}
      {transition && <form key={transition.id + transition.version} aria-label={text('transitionForm')} className="grid min-w-0 gap-3 rounded-xl border border-amber-400/30 p-3" onSubmit={(event) => {
        const values = new FormData(event.currentTarget)
        mutation.propose(event, text('transition') + ' : ' + text('status.' + String(values.get('status') ?? '')), root + '/' + transition.id + '/transitions', { version: transition.version, status: String(values.get('status') ?? ''), reason: String(values.get('reason') ?? '').trim() })
      }}>
        <h3 className="font-bold [overflow-wrap:anywhere]">{text('transition')} · {transition.title}</h3>
        <SelectField name="status" title={text('targetStatus')} required disabled={blocked} error={mutation.errors(root + '/' + transition.id + '/transitions').status?.[0]}>
          {transition.available_transitions.map((status) => <option key={status} value={status}>{text('status.' + status)}</option>)}
        </SelectField>
        <FormField name="reason" label={text('reason')} minLength={10} maxLength={1000} required disabled={blocked} error={mutation.errors(root + '/' + transition.id + '/transitions').reason?.[0]} />
        <p className="text-sm text-slate-400">{text('reasonHint')}</p>
        <div className="flex flex-wrap gap-3"><Button type="submit" disabled={blocked}>{text('transition')}</Button><Button disabled={blocked} onClick={() => setTransition(null)}>{text('cancel')}</Button></div>
      </form>}
      {history && <MatchHistory key={history.id + history.version} {...{ active, root, onInvalid }} item={history} management={filters.management} disabled={blocked} onClose={() => setHistory(null)} />}
      {notebook && <MatchNotebook key={notebook.id + active.revision} {...{ active, root, onInvalid }} item={notebook} disabled={blocked} management={filters.management} onClose={() => setNotebook(null)} />}
      {results && <MatchResults key={results.id + results.version} {...{ active, root, onInvalid }} item={results} disabled={blocked} management={filters.management}
        onClose={() => { setNotebook(null); setResults(null) }} onSaved={() => { setNotebook(null); setResults(null); setRefresh((n) => n + 1) }} />}
      {scores && <MatchScores key={scores.id + scores.version} {...{ active, root, onInvalid }} item={scores} disabled={blocked} management={filters.management}
        onClose={() => setScores(null)} onSaved={() => { setNotebook(null); setResults(null); setScores(null); setRefresh((n) => n + 1) }} />}
      {lineups && <MatchLineups key={lineups.item.id + lineups.item.version + lineups.participant.id} {...{ active, root, onInvalid }} item={lineups.item} participant={lineups.participant}
        disabled={blocked} management={filters.management} onClose={() => setLineups(null)} onSaved={() => { setNotebook(null); setResults(null); setScores(null); setLineups(null); setRefresh((n) => n + 1) }} />}
    </>}
  </section>
}

function MatchHistory({ active, root, item, management, disabled, onInvalid, onClose }: Props & { root: string; item: MatchRecord; management: boolean; onClose: () => void }) {
  const [page, setPage] = useState(1)
  const { list, loading, error } = usePartnershipList<MatchStatusEvent, Page<MatchStatusEvent>>(root + '/' + item.id + '/history?page=' + page + '&per_page=10' + (management ? '&management=1' : ''), active, true, onInvalid)
  return <section aria-label={text('history')} className="grid min-w-0 gap-3 rounded-xl border border-slate-700 p-3">
    <h3 className="font-bold [overflow-wrap:anywhere]">{text('history')} · {item.title}</h3>
    <Button disabled={disabled} onClick={onClose}>{text('closeHistory')}</Button>
    {loading && <p role="status">{text('loading')}</p>}
    {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
    {list?.data.length === 0 && <p>{text('emptyHistory')}</p>}
    <ol className="grid min-w-0 gap-3">{list?.data.map((event) => <li key={event.id} className="grid min-w-0 gap-1 rounded-lg border border-slate-700 p-3">
      <p>{event.from_status ? text('status.' + event.from_status) : text('initial')} → {text('status.' + event.to_status)}</p>
      <p className="text-sm text-slate-400">{calendarDate(event.occurred_at, active.context!.timezone)}</p>
      {event.reason && <p className="whitespace-pre-wrap [overflow-wrap:anywhere]">{event.reason}</p>}
    </li>)}</ol>
    {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={disabled || loading} onPageChange={setPage} />}
  </section>
}
