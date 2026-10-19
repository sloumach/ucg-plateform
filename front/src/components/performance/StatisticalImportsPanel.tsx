import { useEffect, useState, type FormEvent } from 'react'
import { tenancyRequest, type ActiveOrganization } from '../../api/tenancy'
import type { ApiSuccessResponse } from '../../api/contracts'
import { metricText, type MetricDefinition } from '../../api/metricCatalogue'
import { statisticalImportText as text, statisticalCsvHeaders, type StatisticalImport, type StatisticalImportSummary } from '../../api/statisticalImports'
import { observationText } from '../../api/observations'
import { isAbortError } from '../../api/errors'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { Alert } from '../feedback/Alert'
import { Pagination } from '../data/Pagination'
import { SelectField } from '../people/peopleForms'
import { apiError, shouldReloadContext } from '../people/peopleFormValues'
import { usePeopleMutation } from '../people/usePeopleMutation'
import { usePartnershipList } from '../partnerships/usePartnershipList'

type Props = { active: ActiveOrganization; disabled: boolean; onInvalid: () => void }
export function StatisticalImportsPanel({ active, disabled, onInvalid }: Props) {
  const [open, setOpen] = useState(false), [page, setPage] = useState(1), [selected, setSelected] = useState<string | null>(null), [rowPage, setRowPage] = useState(1), [refresh, setRefresh] = useState(0)
  const allowed = ['sports.read', 'sports.write', 'sports.statistics.read', 'sports.statistics.write', 'sports.statistics.import'].every((right) => active.context?.permissions.includes(right))
  const root = 'tenants/' + active.context?.organization_id + '/statistical-imports'
  const { list, loading, error } = usePartnershipList<StatisticalImportSummary>(root + '?page=' + page, active, open && allowed, onInvalid, refresh)
  const mutation = usePeopleMutation<StatisticalImport>(active, (run) => { setSelected(run.id); setRowPage(1); setRefresh((n) => n + 1) }, onInvalid)
  const blocked = disabled || mutation.blocked
  if (!allowed) return null
  function upload(event: FormEvent<HTMLFormElement>) {
    const body = new FormData(event.currentTarget)
    const input = event.currentTarget.elements.namedItem('file')
    if (input instanceof HTMLInputElement && input.files?.[0]) body.set('file', input.files[0])
    mutation.propose(event, text('upload'), root, body)
  }
  return <section aria-label={text('title')} className="grid min-w-0 gap-4 rounded-xl border border-slate-700 p-4">
    <Button disabled={blocked} onClick={() => { setOpen(!open); setSelected(null) }}>{text(open ? 'close' : 'title')}</Button>
    {open && <><p>{text('hint')}</p><p>{text('private')}</p>{mutation.feedback}
      <form noValidate aria-label={text('upload')} onSubmit={upload} className="grid min-w-0 gap-3">
        <FormField name="file" label={text('file')} type="file" accept=".csv,text/csv" required disabled={blocked} error={mutation.errors(root).file?.[0]} />
        <SelectField name="delimiter" title={text('delimiter')} defaultValue="," disabled={blocked} error={mutation.errors(root).delimiter?.[0]}><option value=",">{text('comma')}</option><option value=";">{text('semicolon')}</option></SelectField>
        <Button type="submit" disabled={blocked}>{text('upload')}</Button>
      </form>
      <details className="min-w-0"><summary>{text('schema')}</summary><p>{text('schemaHint')}</p><code className="block whitespace-pre-wrap [overflow-wrap:anywhere]">{statisticalCsvHeaders.join(',')}</code><p>{text('optional')}</p></details>
      <ImportReferences {...{ active, onInvalid }} disabled={blocked} />
      <Button disabled={blocked || loading} onClick={() => setRefresh((n) => n + 1)}>{text('refresh')}</Button>
      {loading && <p role="status">{text('loading')}</p>}{error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
      {list?.data.length === 0 && <p>{text('empty')}</p>}
      <ul className="grid min-w-0 gap-3">{list?.data.map((run) => <li key={run.id} className="grid min-w-0 gap-2 rounded-xl border border-slate-700 p-3">
        <p>{text(run.status)}</p><p className="[overflow-wrap:anywhere]">{run.created_at} · {text('valid')} : {run.valid_count} · {text('invalid')} : {run.invalid_count}</p>
        <Button disabled={blocked} onClick={() => { setSelected(run.id); setRowPage(1) }}>{text('view')} · {run.id.slice(-8)}</Button>
      </li>)}</ul>
      {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} onPageChange={(n) => { setPage(n); setSelected(null) }} disabled={blocked || loading} />}
      {selected && <ImportPreview key={selected + ':' + rowPage + ':' + refresh} {...{ active, onInvalid }} path={root + '/' + selected + '?page=' + rowPage} disabled={blocked} onPageChange={setRowPage} onSaved={() => setRefresh((n) => n + 1)} />}
    </>}
  </section>
}
function ImportPreview({ active, path, disabled, onInvalid, onPageChange, onSaved }: Props & { path: string; onPageChange: (n: number) => void; onSaved: () => void }) {
  const [run, setRun] = useState<StatisticalImport | null>(null), [error, setError] = useState<ReturnType<typeof apiError> | null>(null)
  const [acknowledge, setAcknowledge] = useState(false)
  const mutation = usePeopleMutation<StatisticalImport>(active, onSaved, onInvalid)
  const blocked = disabled || mutation.blocked
  useEffect(() => {
    const controller = new AbortController()
    void tenancyRequest<ApiSuccessResponse<StatisticalImport>>(path, { revision: active.revision ?? undefined, signal: controller.signal }).then((result) => {
      if (!controller.signal.aborted) setRun(result.data)
    }).catch((failure: unknown) => {
      if (controller.signal.aborted || isAbortError(failure)) return
      const next = apiError(failure); setError(next); if (shouldReloadContext(next)) onInvalid()
    })
    return () => controller.abort()
  }, [path, active.revision, onInvalid])
  return <section aria-label={text('preview')} className="grid min-w-0 gap-3"><h3 className="font-bold">{text('preview')}</h3><p>{text('advisory')}</p>
    {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
    {!run && !error && <p role="status">{text('loading')}</p>}
    {mutation.feedback}
    {run && <><p role="status">{text(run.status)} · {run.processed_count} / {run.row_count} · {text('created')} : {run.counts.created} · {text('duplicate')} : {run.counts.duplicate} · {text('rejected')} : {run.counts.rejected}</p>
      {run.failure_code && <Alert tone="error" title={text(run.failure_code)} />}
      {(run.can_confirm || run.can_resume) && <form aria-label={text('execution')} onSubmit={(event) => mutation.propose(event, text(run.can_confirm ? 'confirm' : 'resume'), path.split('?')[0] + (run.can_confirm ? '/confirm' : '/resume'), { version: run.version, confirm: true, ...(run.can_confirm ? { acknowledge_rejected: acknowledge } : {}) })} className="grid min-w-0 gap-3">
        <p>{text('confirmHint')}</p>
        {run.can_confirm && run.invalid_count > 0 && <label className="flex items-start gap-2"><input type="checkbox" checked={acknowledge} disabled={blocked} onChange={(event) => setAcknowledge(event.target.checked)} />{text('acknowledge')}</label>}
        <Button type="submit" disabled={blocked || (run.can_confirm && run.invalid_count > 0 && !acknowledge)}>{text(run.can_confirm ? 'confirm' : 'resume')}</Button>
      </form>}
      <p>{text('valid')} : {run.valid_count} · {text('invalid')} : {run.invalid_count}</p>
      {run.rows.data.map((row) => <article key={row.line} className="grid min-w-0 gap-2 rounded-xl border border-slate-700 p-3 whitespace-pre-wrap [overflow-wrap:anywhere]">
        {row.outcome && <p>{text(row.outcome.status)}{row.outcome.code ? ' · ' + text(row.outcome.code) : ''}</p>}
        <h4 className="font-bold">{text('line')} {row.line} · {text(row.status)}</h4>
        {row.resolved && <p>{row.resolved.person_label ?? observationText('hiddenPerson')} · {row.resolved.metric.code} · {row.resolved.metric.title} · v{row.resolved.metric.number}</p>}
        <dl className="grid min-w-0 gap-2">{Object.entries(row.values).map(([field, value]) => <div key={field}><dt className="font-semibold">{observationText(field)}</dt><dd>{value === null ? '—' : ['availability', 'source_kind', 'status'].includes(field) ? observationText(String(value)) : value}</dd></div>)}</dl>
        {Object.entries(row.errors).map(([field, messages]) => <p key={field} className="text-rose-200">{field} : {messages.join(' ')}</p>)}
      </article>)}
      <Pagination currentPage={run.rows.meta.current_page} totalPages={run.rows.meta.last_page} onPageChange={onPageChange} disabled={blocked} />
    </>}
  </section>
}
function ImportReferences({ active, disabled, onInvalid }: Props) {
  const [open, setOpen] = useState(false), [peoplePage, setPeoplePage] = useState(1), [metricPage, setMetricPage] = useState(1)
  const people = usePartnershipList<{ id: string; label: string }>('tenants/' + active.context?.organization_id + '/observations/choices?kind=people&page=' + peoplePage, active, open, onInvalid)
  const metrics = usePartnershipList<MetricDefinition>('metric-catalogue?status=active&per_page=10&page=' + metricPage, active, open, onInvalid)
  return <div className="grid min-w-0 gap-3"><Button disabled={disabled} onClick={() => setOpen(!open)}>{text(open ? 'closeReferences' : 'references')}</Button>
    {open && <><p>{text('referencesHint')}</p>{[people.error, metrics.error].map((error, i) => error && <Alert key={i} tone="error" title={error.message} />)}
      {(people.loading || metrics.loading) && <p role="status">{text('loading')}</p>}
      <ul>{people.list?.data.map((person) => <li className="[overflow-wrap:anywhere]" key={person.id}>{person.label} · person_id : {person.id}</li>)}</ul>
      {people.list && <Pagination currentPage={people.list.meta.current_page} totalPages={people.list.meta.last_page} onPageChange={setPeoplePage} disabled={disabled || people.loading} />}
      <ul>{metrics.list?.data.map((metric) => <li className="[overflow-wrap:anywhere]" key={metric.id}>{metric.code} · {metric.title} · {metric.scope.game_name} · metric_id : {metric.id} · metric_version : {metric.version} · {metricText('level.' + metric.aggregation_level)}</li>)}</ul>
      {metrics.list && <Pagination currentPage={metrics.list.meta.current_page} totalPages={metrics.list.meta.last_page} onPageChange={setMetricPage} disabled={disabled || metrics.loading} />}
    </>}
  </div>
}
