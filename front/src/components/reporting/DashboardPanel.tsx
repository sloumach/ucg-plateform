import { useState, type FormEvent } from 'react'
import { type ActiveOrganization } from '../../api/tenancy'
import { dashboardViews, dashboardText as text, type DashboardCatalogue, type DashboardCard, type DashboardCardDefinition } from '../../api/dashboards'
import { useDashboardRead } from './useDashboardRead'
import { DashboardIndicatorPanel } from './DashboardIndicatorPanel'
import { ReportExportPanel } from './ReportExportPanel'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { Alert } from '../feedback/Alert'
import { Pagination } from '../data/Pagination'
import { SelectField } from '../people/peopleForms'

type Props = { active: ActiveOrganization; disabled: boolean; onInvalid: () => void }
type Query = { view: string; from: string; to: string; revision: number }

export function DashboardPanel(props: Props) {
  const context = props.active.context
  if (!dashboardViews.some((view) => context?.permissions.includes('reporting.' + view))) return null
  return <Dashboards key={context?.organization_id + ':' + context?.actor_user_id + ':' + props.active.revision + ':' + context?.permissions.join(',')} {...props} />
}
function Dashboards({ active, disabled, onInvalid }: Props) {
  const [open, setOpen] = useState(false), [catalogueRevision, setCatalogueRevision] = useState(0), [view, setView] = useState('')
  const [query, setQuery] = useState<Query | null>(null)
  const [today] = useState(() => new Intl.DateTimeFormat('sv-SE', { timeZone: active.context?.timezone ?? 'UTC' }).format(new Date()))
  const [end] = useState(() => { const date = new Date(today + 'T12:00:00Z'); date.setUTCDate(date.getUTCDate() + 6); return date.toISOString().slice(0, 10) })
  const root = 'tenants/' + active.context?.organization_id + '/dashboards'
  const catalogue = useDashboardRead<DashboardCatalogue>(open ? root : null, active, onInvalid, catalogueRevision)
  const selected = catalogue.data?.views.find((entry) => entry.id === view) ?? catalogue.data?.views[0]
  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); const form = new FormData(event.currentTarget)
    if (selected) setQuery({ view: selected.id, from: String(form.get('from')), to: String(form.get('to')), revision: (query?.revision ?? 0) + 1 })
  }
  return <section aria-label={text('title')} className="grid min-w-0 gap-4 rounded-xl border border-slate-700 p-4">
    <h2 className="font-bold">{text('title')}</h2><p>{text('hint')}</p>
    <Button disabled={disabled} onClick={() => { setOpen(!open); setQuery(null); setCatalogueRevision((revision) => revision + 1) }}>{text(open ? 'close' : 'open')}</Button>
    {open && <>{catalogue.loading && <p role="status">{text('loading')}</p>}{catalogue.error && <Alert tone="error" title={catalogue.error.message} requestId={catalogue.error.requestId} />}
      {catalogue.error && <Button disabled={disabled} onClick={() => setCatalogueRevision((revision) => revision + 1)}>{text('retry')}</Button>}
      {catalogue.data && <><form aria-label={text('filters')} onSubmit={submit} onChange={() => setQuery(null)} className="grid min-w-0 gap-3">
        <SelectField name="view" title={text('view')} value={selected?.id ?? ''} disabled={disabled} onChange={(id) => { setView(id); setQuery(null) }}>
          {catalogue.data.views.map((entry) => <option key={entry.id} value={entry.id}>{entry.label}</option>)}
        </SelectField><p>{text('timezone')} : {catalogue.data.timezone} · {text('limit')} : {catalogue.data.max_days}</p>
        <FormField name="from" label={text('from')} type="date" defaultValue={today} required disabled={disabled} />
        <FormField name="to" label={text('to')} type="date" defaultValue={end} required disabled={disabled} />
        <p>{text('periodHint')}</p><Button type="submit" disabled={disabled || !selected}>{text('show')}</Button>
      </form>
      {selected?.cards.length === 0 && <p>{text('noCards')}</p>}
      {query && query.view === selected?.id && <section aria-label={text('results')} className="grid min-w-0 gap-4">
        <h3 className="font-bold">{selected.label}</h3><Button disabled={disabled} onClick={() => setQuery({ ...query, revision: query.revision + 1 })}>{text('refresh')}</Button>
        <div className="grid min-w-0 gap-4 xl:grid-cols-2">{selected.cards.map((card) => <Card key={card.id + ':' + JSON.stringify(query)} {...{ active, disabled, onInvalid, root, card, query }} />)}</div>
        <ReportExportPanel active={active} disabled={disabled} onInvalid={onInvalid} cards={selected.cards} query={query} />
      </section>}</>}
    </>}
  </section>
}
function Card({ active, disabled, onInvalid, root, card, query }: Props & { root: string; card: DashboardCardDefinition; query: Query }) {
  const [page, setPage] = useState(1), [refresh, setRefresh] = useState(0), [details, setDetails] = useState(!card.indicator_supported)
  const params = new URLSearchParams({ view: query.view, from: query.from, to: query.to, page: String(page) })
  const result = useDashboardRead<DashboardCard>(card.status === 'available' && details ? root + '/' + card.id + '?' + params.toString() : null, active, onInvalid, refresh)
  const blocked = disabled || result.loading
  return <article aria-label={card.label} className="grid min-w-0 content-start gap-3 rounded-xl border border-slate-700 p-4 [overflow-wrap:anywhere]">
    <h4 className="font-bold">{card.label}</h4><p>{card.definition}</p><p>{text('scope')} : {text('scope.' + card.scope)}</p>
    <p>{card.period_kind === 'window' ? text('window') + ' : ' + query.from + ' → ' + query.to + ' · ' + active.context?.timezone : text(card.indicator_supported ? 'indicatorSnapshot' : 'snapshot')}</p>
    {card.indicator_supported && <><DashboardIndicatorPanel active={active} disabled={disabled} onInvalid={onInvalid} root={root + '/' + card.id + '/indicator'} query={query} /><Button disabled={disabled} onClick={() => setDetails(!details)}>{text(details ? 'hideDetails' : 'showDetails')}</Button></>}
    {card.status === 'unavailable' && <p role="status">{card.reason ?? text('unavailable')}</p>}
    {result.loading && <p role="status">{text('loading')}</p>}
    {result.error && <><Alert tone="error" title={result.error.message} requestId={result.error.requestId} />
      {Object.entries(result.error.fieldErrors).map(([field, messages]) => <p key={field} role="alert">{messages.join(' ')}</p>)}</>}
    {result.data && <><p>{text('captured')} : {date(result.data.captured_at, active.context?.timezone)}</p>
      {result.data.status === 'unavailable' ? <p>{result.data.reason}</p> : <><p className="text-xl font-bold">{text('total')} : {result.data.total}</p>
        {result.data.items.length === 0 && <p>{text('empty')}</p>}
        <ul className="grid min-w-0 gap-3">{result.data.items.map((row) => <li key={row.id} className="grid min-w-0 gap-1 rounded-xl border border-slate-700 p-3">
          <p className="font-medium">{row.label}</p><p>{state(row.state)}</p>{row.at && <p>{date(row.at, active.context?.timezone)}</p>}
          {row.value !== null && <p>{text('value')} : {row.value}</p>}
        </li>)}</ul>
        {result.data.meta && <Pagination currentPage={result.data.meta.current_page} totalPages={result.data.meta.last_page} disabled={blocked} onPageChange={setPage} />}
        {result.data.meta && result.data.meta.current_page > result.data.meta.last_page && <Button disabled={blocked} onClick={() => setPage(1)}>{text('firstPage')}</Button>}
      </>}</>}
    {card.status === 'available' && details && <Button disabled={blocked} onClick={() => setRefresh((value) => value + 1)}>{text(result.error ? 'retry' : 'refreshCard')}</Button>}
  </article>
}
function date(value: string, timezone = 'UTC') { return value.length === 10 ? value : new Date(value).toLocaleString('fr-FR', { timeZone: timezone }) }
function state(value: string) {
  const known = ['active', 'unlinked', 'scheduled', 'cancelled', 'pending', 'present', 'absent', 'available', 'unavailable', 'proposed', 'confirmed', 'live', 'finished', 'validated', 'archived', 'signed', 'queued', 'stale', 'completed', 'failed', 'uploaded', 'previewed', 'processing', 'promised', 'delivered']
  return known.includes(value) ? text('state.' + value) : value
}
