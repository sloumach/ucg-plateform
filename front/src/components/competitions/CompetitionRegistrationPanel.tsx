import { useEffect, useState } from 'react'
import { tenancyRequest, type ActiveOrganization } from '../../api/tenancy'
import type { ApiSuccessResponse } from '../../api/contracts'
import { registrationStates, registrationText as text, type CompetitionRegistration, type RegistrationPayload, type RegistrationReminder } from '../../api/competitionRegistrations'
import { safeCompetitionUrl, type ExternalCompetition } from '../../api/externalCompetitions'
import { calendarDate } from '../../api/calendar'
import { apiError, shouldReloadContext } from '../people/peopleFormValues'
import { usePeopleMutation } from '../people/usePeopleMutation'
import { usePartnershipList } from '../partnerships/usePartnershipList'
import { Button } from '../Button'
import { Alert } from '../feedback/Alert'
import { Pagination } from '../data/Pagination'
import { CompetitionRegistrationForm } from './CompetitionRegistrationForm'
type Props = { active: ActiveOrganization; root: string; item: ExternalCompetition; disabled: boolean; onInvalid: () => void; onClose: () => void }
export function CompetitionRegistrationPanel({ active, root, item, disabled, onInvalid, onClose }: Props) {
  const [record, setRecord] = useState<CompetitionRegistration | null>(null), [loaded, setLoaded] = useState(false), [editing, setEditing] = useState(false), [history, setHistory] = useState(false)
  const [error, setError] = useState<ReturnType<typeof apiError> | null>(null), [refresh, setRefresh] = useState(0)
  const path = root + '/' + item.id + '/registration', permissions = active.context?.permissions ?? []
  const allowed = ['sports.read', 'sports.competitions.read', 'sports.registrations.read'].every((p) => permissions.includes(p)), writable = allowed && permissions.includes('sports.registrations.write') && item.status === 'active'
  useEffect(() => {
    const controller = new AbortController()
    if (!allowed) return () => controller.abort()
    const load = async () => { setLoaded(false); setError(null); setRecord(null)
      try { const result = await tenancyRequest<ApiSuccessResponse<CompetitionRegistration | null>>(path, { signal: controller.signal, revision: active.revision ?? undefined }); if (!controller.signal.aborted) { setRecord(result.data); setLoaded(true) } }
      catch (failure) { if (!controller.signal.aborted) { const next = apiError(failure); setError(next); if (shouldReloadContext(next)) onInvalid() } }
    }; void load(); return () => controller.abort()
  }, [path, active.revision, allowed, onInvalid, refresh])
  const mutation = usePeopleMutation<CompetitionRegistration>(active, () => { setEditing(false); setHistory(false); setRefresh((n) => n + 1) }, onInvalid), blocked = disabled || mutation.blocked
  if (!allowed) return null
  return <section aria-label={text('panel')} className="grid min-w-0 gap-3 rounded-xl border border-cyan-400/30 p-3"><h3 className="font-bold [overflow-wrap:anywhere]">{text('panel')} · {item.name}</h3>
    <p className="text-sm text-slate-400">{text('hint')}</p><Button disabled={blocked} onClick={onClose}>{text('close')}</Button>{mutation.feedback}
    {!loaded && !error && <p role="status">{text('loading')}</p>}{error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
    <Button disabled={blocked} onClick={() => { setEditing(false); setRefresh((n) => n + 1) }}>{text('refresh')}</Button>
    {loaded && !record && <p>{text('empty')}</p>}
    {record && <><RegistrationView payload={record.payload} zone={active.context!.timezone} /><p>{text('pending')} : {record.summary.pending} · {text('dueSoon')} : {record.summary.due_soon} · {text('overdue')} : {record.summary.overdue}</p></>}
    {loaded && writable && !editing && <Button disabled={blocked} onClick={() => { setHistory(false); setEditing(true) }}>{text('edit')}</Button>}
    {editing && loaded && writable && <CompetitionRegistrationForm key={record?.version ?? 'new'} {...{ active, path, record, onInvalid }} disabled={blocked} errors={mutation.errors(path)} onCancel={() => setEditing(false)} onSubmit={(event, body) => mutation.propose(event, text('save'), path, { ...body, competition_version: item.version }, true)} />}
    {record && <Button disabled={blocked} onClick={() => { setEditing(false); setHistory(!history) }}>{text('history')}</Button>}
    {history && <RegistrationHistory {...{ active, path, onInvalid }} disabled={blocked} />}
    <RegistrationReminders key={refresh} {...{ active, path, onInvalid }} disabled={blocked} />
  </section>
}
function RegistrationView({ payload, zone }: { payload: RegistrationPayload; zone: string }) {
  const link = safeCompetitionUrl(payload.source_url)
  return <div className="grid min-w-0 gap-3 [overflow-wrap:anywhere]">
    {Object.keys(registrationStates).map((key) => <p key={key}>{text(key)} : {text('state.' + payload[key as keyof typeof registrationStates])}</p>)}
    {payload.external_reference && <p>{text('external_reference')} : {payload.external_reference}</p>}{link && <a className="break-all text-cyan-300 underline" href={link} target="_blank" rel="noopener noreferrer" referrerPolicy="no-referrer">{text('source_url')}</a>}
    {payload.notes && <p className="whitespace-pre-wrap">{payload.notes}</p>}<p className="text-sm text-slate-400">{text('declarative')}</p>
    {(['age', 'residence', 'rank'] as const).map((key) => <p key={key}>{text('requirement.' + key)} : {payload.requirements[key] || text('unspecified')}</p>)}
    <h4 className="font-bold">{text('roster')}</h4><ol>{payload.roster.map((p) => <li key={p.person_id}>{p.label} · {text(p.role)}</li>)}</ol>
    <h4 className="font-bold">{text('items')}</h4><ol className="grid min-w-0 gap-3">{payload.items.map((entry) => <li key={entry.id} className="grid min-w-0 gap-2 rounded-lg border border-slate-700 p-3"><h5 className="font-bold">{entry.title}</h5><p>{text('kind.' + entry.kind)} · {text('state.' + entry.status)}</p><p>{text('responsible')} : {entry.responsible_label || text('unassigned')}</p><p>{text('due')} : {entry.due_at ? calendarDate(entry.due_at, zone) : text('unspecified')}</p>{entry.note && <p className="whitespace-pre-wrap">{entry.note}</p>}{entry.document_version_id && <p>{text('linkedVersion')}</p>}</li>)}</ol>
  </div>
}
function RegistrationHistory({ active, path, disabled, onInvalid }: { active: ActiveOrganization; path: string; disabled: boolean; onInvalid: () => void }) {
  const [page, setPage] = useState(1), { list, loading, error } = usePartnershipList<CompetitionRegistration>(path + '/history?page=' + page + '&per_page=5', active, true, onInvalid)
  return <section aria-label={text('history')} className="grid min-w-0 gap-3"><h4 className="font-bold">{text('history')}</h4>{loading && <p role="status">{text('loading')}</p>}{error && <Alert tone="error" title={error.message} />}
    <ol className="grid min-w-0 gap-3">{list?.data.map((row) => <li key={row.version} className="grid min-w-0 gap-2 border-b border-slate-700 pb-3"><p>v{row.number} · {calendarDate(row.occurred_at, active.context!.timezone)}</p><p className="whitespace-pre-wrap [overflow-wrap:anywhere]">{row.reason}</p><RegistrationView payload={row.payload} zone={active.context!.timezone} /></li>)}</ol>
    {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={disabled || loading} onPageChange={setPage} />}
  </section>
}
function RegistrationReminders({ active, path, disabled, onInvalid }: { active: ActiveOrganization; path: string; disabled: boolean; onInvalid: () => void }) {
  const [page, setPage] = useState(1), { list, loading, error } = usePartnershipList<RegistrationReminder>(path + '/reminders?page=' + page + '&per_page=5', active, true, onInvalid)
  return <section aria-label={text('reminders')} className="grid min-w-0 gap-2"><h4 className="font-bold">{text('reminders')}</h4><p className="text-sm text-slate-400">{text('remindersHint')}</p>{loading && <p role="status">{text('loading')}</p>}{error && <Alert tone="error" title={error.message} />}
    {list?.data.length === 0 && <p>{text('noReminders')}</p>}<ol className="grid min-w-0 gap-2">{list?.data.map((row) => <li key={row.id} className="[overflow-wrap:anywhere]">{row.payload.title} · {text('stage.' + row.stage)} · {calendarDate(row.occurred_at, active.context!.timezone)}</li>)}</ol>
    {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={disabled || loading} onPageChange={setPage} />}
  </section>
}
