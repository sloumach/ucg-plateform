import { useEffect, useState, type FormEvent } from 'react'
import { tenancyRequest, type ActiveOrganization } from '../../api/tenancy'
import type { ApiSuccessResponse } from '../../api/contracts'
import type { MetricDefinition } from '../../api/metricCatalogue'
import type { StatisticalObservation } from '../../api/observations'
import { calculationText as text, type StatisticalCalculation, type StatisticalCalculationResult, type StatisticalCalculationRevision } from '../../api/statisticalCalculations'
import { isAbortError } from '../../api/errors'
import { Button } from '../Button'
import { Alert } from '../feedback/Alert'
import { Pagination } from '../data/Pagination'
import { SelectField } from '../people/peopleForms'
import { usePeopleMutation } from '../people/usePeopleMutation'
import { usePartnershipList } from '../partnerships/usePartnershipList'
import { apiError, shouldReloadContext } from '../people/peopleFormValues'

type Props = { active: ActiveOrganization; disabled: boolean; onInvalid: () => void; selected: StatisticalObservation[]; onClear: () => void }
export function StatisticalCalculationsPanel({ active, disabled, onInvalid, selected, onClear }: Props) {
  const [open, setOpen] = useState(false), [page, setPage] = useState(1), [metricPage, setMetricPage] = useState(1), [metric, setMetric] = useState<MetricDefinition | null>(null)
  const [refresh, setRefresh] = useState(0), [detail, setDetail] = useState<string | null>(null)
  const rights = active.context?.permissions ?? [], read = rights.includes('sports.read') && rights.includes('sports.statistics.read'), write = rights.includes('sports.write') && rights.includes('sports.statistics.write')
  const root = 'tenants/' + active.context?.organization_id + '/statistical-calculations'
  const list = usePartnershipList<StatisticalCalculation>(root + '?page=' + page, active, open && read, onInvalid, refresh)
  const metrics = usePartnershipList<MetricDefinition>('metric-catalogue?status=active&per_page=10&page=' + metricPage, active, open && read && write, onInvalid)
  const mutation = usePeopleMutation<StatisticalCalculation>(active, (row) => { setDetail(row.id); setRefresh((n) => n + 1) }, onInvalid)
  const blocked = disabled || mutation.blocked
  if (!read) return null
  function create(event: FormEvent<HTMLFormElement>) {
    mutation.propose(event, text('create'), root, { metric_id: metric?.id, metric_version: metric?.version, observations: selected.map((row) => row.id), confirm: true })
  }
  return <section aria-label={text('title')} className="grid min-w-0 gap-4 rounded-xl border border-slate-700 p-4">
    <h2 className="font-bold">{text('title')}</h2><Button disabled={blocked} onClick={() => setOpen(!open)}>{text(open ? 'close' : 'open')}</Button>
    {open && <><p>{text('hint')}</p>{mutation.feedback}
      {write && <form aria-label={text('create')} onSubmit={create} className="grid min-w-0 gap-3">
        <p>{text('selected')} : {selected.length} / 1000</p><p>{text('selectionHint')}</p>
        <Button disabled={blocked || selected.length === 0} onClick={onClear}>{text('clear')}</Button>
        <SelectField name="metric_id" title={text('metric')} value={metric?.id ?? ''} onChange={(id) => setMetric(metrics.list?.data.find((row) => row.id === id) ?? null)} disabled={blocked || metrics.loading}>
          <option value="">—</option>{metric && !metrics.list?.data.some((row) => row.id === metric.id) && <option value={metric.id}>{metric.code} · v{metric.number}</option>}
          {metrics.list?.data.map((row) => <option key={row.id} value={row.id}>{row.code} · {row.title} · v{row.number}</option>)}
        </SelectField>
        {metrics.error && <Alert tone="error" title={metrics.error.message} />}
        {metrics.list && <Pagination currentPage={metrics.list.meta.current_page} totalPages={metrics.list.meta.last_page} disabled={blocked || metrics.loading} onPageChange={setMetricPage} />}
        {metric?.ratio_components && <div className="grid min-w-0 gap-2 [overflow-wrap:anywhere]"><p>{text('components')}</p>{(['numerator', 'denominator'] as const).map((side) => <ul key={side}>{metric.ratio_components?.[side].map((component) => <li key={component.version}>{text(side)} · {component.code ?? component.metric_id} · {component.version}</li>)}</ul>)}</div>}
        <Button type="submit" disabled={blocked || selected.length === 0 || !metric}>{text('create')}</Button>
      </form>}
      <Button disabled={blocked || list.loading} onClick={() => setRefresh((n) => n + 1)}>{text('refresh')}</Button>
      {list.loading && <p role="status">{text('loading')}</p>}{list.error && <Alert tone="error" title={list.error.message} />}
      {list.list?.data.length === 0 && <p>{text('empty')}</p>}
      <ul className="grid min-w-0 gap-3">{list.list?.data.map((row) => <li key={row.id} className="grid min-w-0 gap-2 rounded-xl border border-slate-700 p-3 [overflow-wrap:anywhere]">
        <p>{row.metric.code} · {row.cohort.source_label} · {text(row.status)}</p><Button disabled={blocked} onClick={() => setDetail(row.id)}>{text('view')} · {row.id.slice(-8)}</Button>
      </li>)}</ul>
      {list.list && <Pagination currentPage={list.list.meta.current_page} totalPages={list.list.meta.last_page} disabled={blocked || list.loading} onPageChange={(next) => { setPage(next); setDetail(null) }} />}
      {detail && <CalculationDetail key={detail + ':' + refresh} {...{ active, onInvalid }} id={detail} root={root} disabled={blocked} write={write}
        onRecalculate={(event, row) => mutation.propose(event, text('recalculate'), root + '/' + row.id + '/recalculate', { version: row.version, confirm: true })} />}
    </>}
  </section>
}
function CalculationDetail({ active, onInvalid, id, root, disabled, write, onRecalculate }: { active: ActiveOrganization; onInvalid: () => void; id: string; root: string; disabled: boolean; write: boolean; onRecalculate: (event: FormEvent<HTMLFormElement>, row: StatisticalCalculation) => void }) {
  const [row, setRow] = useState<StatisticalCalculation | null>(null), [error, setError] = useState<ReturnType<typeof apiError> | null>(null), [page, setPage] = useState(1), [history, setHistory] = useState(false), [historyPage, setHistoryPage] = useState(1)
  const revisions = usePartnershipList<StatisticalCalculationRevision>(root + '/' + id + '/history?page=' + historyPage, active, history, onInvalid)
  useEffect(() => {
    const controller = new AbortController()
    void tenancyRequest<ApiSuccessResponse<StatisticalCalculation>>(root + '/' + id + '?page=' + page, { revision: active.revision ?? undefined, signal: controller.signal }).then((response) => { if (!controller.signal.aborted) setRow(response.data) }).catch((failure: unknown) => {
      if (controller.signal.aborted || isAbortError(failure)) return
      const next = apiError(failure); setError(next); if (shouldReloadContext(next)) onInvalid()
    })
    return () => controller.abort()
  }, [root, id, page, active.revision, onInvalid])
  return <section aria-label={text('detail')} className="grid min-w-0 gap-3 [overflow-wrap:anywhere]"><h3 className="font-bold">{text('detail')}</h3>
    {error && <Alert tone="error" title={error.message} />}{!row && !error && <p role="status">{text('loading')}</p>}
    {row && <><p>{row.metric.code} · v{row.metric.number} · {text(row.status)}</p><p>{row.cohort.source_label} · {row.cohort.person_id}</p>
      {row.result ? <CalculationResult result={row.result} /> : <p role="status">{text('noCurrentResult')}</p>}
      {row.failure_code && <Alert tone="error" title={text(row.failure_code)} />}
      {write && row.can_recalculate && <form aria-label={text('recalculate')} onSubmit={(event) => onRecalculate(event, row)}><Button type="submit" disabled={disabled}>{text('recalculate')}</Button></form>}
      <details><summary>{text('sources')}</summary><ul>{row.sources?.data.map((source) => <li key={source}>{source}</li>)}</ul>
        {row.sources && <Pagination currentPage={row.sources.meta.current_page} totalPages={row.sources.meta.last_page} disabled={disabled} onPageChange={(next) => { setRow(null); setError(null); setPage(next) }} />}</details>
      <Button disabled={disabled} onClick={() => setHistory(!history)}>{text(history ? 'closeHistory' : 'history')}</Button>
      {history && <section aria-label={text('history')} className="grid min-w-0 gap-3"><p>{text('historyHint')}</p>{revisions.error && <Alert tone="error" title={revisions.error.message} />}
        {revisions.list?.data.map((revision) => <article key={revision.id} className="grid min-w-0 gap-2 rounded-xl border border-slate-700 p-3"><p>v{revision.number} · {revision.created_at}</p><CalculationResult result={revision.result} /></article>)}
        {revisions.list && <Pagination currentPage={revisions.list.meta.current_page} totalPages={revisions.list.meta.last_page} disabled={disabled} onPageChange={setHistoryPage} />}
      </section>}
    </>}
  </section>
}
function CalculationResult({ result }: { result: StatisticalCalculationResult }) {
  return <div className="grid min-w-0 gap-2"><p>{text('value')} : {result.availability === 'available' ? result.value : text('unavailable')}</p>
    <p>{text('sample')} : {result.sample_size} · {text('included')} : {result.included_count} / {result.source_count}</p>
    <p>{text('missing')} : {result.missing_count} · {text('voided')} : {result.voided_count} · {text(result.incomplete ? 'incomplete' : 'complete')}</p>
    {result.numerator !== null && <p>{text('numerator')} : {result.numerator} · {text('denominator')} : {result.denominator}</p>}
    {result.reason && <p>{text(result.reason)}</p>}<p>{text('engine')} : {result.engine_version}</p>
  </div>
}
