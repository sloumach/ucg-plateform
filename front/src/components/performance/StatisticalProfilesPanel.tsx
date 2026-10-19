import { useEffect, useState, type FormEvent } from 'react'
import { tenancyRequest, type ActiveOrganization } from '../../api/tenancy'
import type { ApiSuccessResponse } from '../../api/contracts'
import type { MetricDefinition } from '../../api/metricCatalogue'
import { observationSources, observationText } from '../../api/observations'
import { profileText as text, type ProfileContext, type ProfileGroup, type StatisticalProfileResponse } from '../../api/statisticalProfiles'
import { isAbortError } from '../../api/errors'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { Alert } from '../feedback/Alert'
import { Pagination } from '../data/Pagination'
import { SelectField } from '../people/peopleForms'
import { apiError, shouldReloadContext } from '../people/peopleFormValues'
import { usePartnershipList } from '../partnerships/usePartnershipList'
import { MetricReference } from './MetricCatalogueForm'
import { CalculationResult } from './StatisticalCalculationsPanel'

type Props = { active: ActiveOrganization; disabled: boolean; onInvalid: () => void }
type Query = { params: string; token: number }
type Loaded = { query: Query; data?: StatisticalProfileResponse; error?: ReturnType<typeof apiError> }

export function StatisticalProfilesPanel(props: Props) {
  const permissions = props.active.context?.permissions ?? []
  if (!permissions.includes('sports.read') || !permissions.includes('sports.statistics.read')) return null
  return <Profiles key={props.active.context?.organization_id + ':' + props.active.context?.actor_user_id + ':' + props.active.revision} {...props} />
}
function Profiles({ active, disabled, onInvalid }: Props) {
  const [open, setOpen] = useState(false), [metricPage, setMetricPage] = useState(1), [peoplePage, setPeoplePage] = useState(1)
  const [metric, setMetric] = useState<MetricDefinition | null>(null), [person, setPerson] = useState(''), [second, setSecond] = useState('')
  const [mode, setMode] = useState(''), [role, setRole] = useState(''), [release, setRelease] = useState('')
  const [query, setQuery] = useState<Query | null>(null), [loaded, setLoaded] = useState<Loaded | null>(null)
  const [today] = useState(() => new Date().toISOString().slice(0, 10))
  const root = 'tenants/' + active.context?.organization_id + '/statistical-profiles'
  const people = usePartnershipList<{ id: string; label: string }>(root + '/people?page=' + peoplePage, active, open, onInvalid)
  const metrics = usePartnershipList<MetricDefinition>('metric-catalogue?per_page=10&page=' + metricPage, active, open, onInvalid)
  const current = loaded?.query === query ? loaded : null, data = current?.data, error = current?.error, loading = query !== null && current === null
  const blocked = disabled || loading
  useEffect(() => {
    if (!query) return
    const controller = new AbortController()
    void tenancyRequest<ApiSuccessResponse<StatisticalProfileResponse>>(root + '?' + query.params, { revision: active.revision ?? undefined, signal: controller.signal })
      .then((response) => { if (!controller.signal.aborted) setLoaded({ query, data: response.data }) })
      .catch((failure: unknown) => {
        if (controller.signal.aborted || isAbortError(failure)) return
        const next = apiError(failure); setLoaded({ query, error: next }); if (shouldReloadContext(next)) onInvalid()
      })
    return () => controller.abort()
  }, [query, root, active.revision, onInvalid])
  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const params = new URLSearchParams(), values = new FormData(event.currentTarget)
    params.set('metric_id', metric?.id ?? ''); params.set('metric_version', metric?.version ?? '')
    params.append('people[]', person); if (second) params.append('people[]', second)
    for (const field of ['from', 'to', 'source_kind', 'source_label', 'season', 'region', 'map', 'opponent']) {
      const value = String(values.get(field) ?? '').trim(); if (value) params.set(field, value)
    }
    for (const [key, value] of Object.entries({ mode_id: mode, role_id: role, release_id: release })) if (value) params.set(key, value)
    setQuery({ params: params.toString(), token: Date.now() })
  }
  function page(field: string, value: number) {
    if (!query) return
    const params = new URLSearchParams(query.params); params.set(field, String(value)); setQuery({ params: params.toString(), token: Date.now() })
  }
  const month = today.slice(0, 8) + '01'
  return <section aria-label={text('title')} className="grid min-w-0 gap-4 rounded-xl border border-slate-700 p-4">
    <h2 className="font-bold">{text('title')}</h2><Button disabled={disabled} onClick={() => { setOpen(!open); setQuery(null); setLoaded(null) }}>{text(open ? 'close' : 'open')}</Button>
    {open && <><p>{text('hint')}</p>
      <form aria-label={text('filters')} onSubmit={submit} onChange={() => { setQuery(null); setLoaded(null) }} className="grid min-w-0 gap-3">
        <SelectField name="profile_metric" title={text('metric')} value={metric?.id ?? ''} disabled={blocked || metrics.loading} required onChange={(id) => { setMetric(metrics.list?.data.find((entry) => entry.id === id) ?? null); setMode(''); setRole(''); setRelease('') }}>
          <option value="">—</option>{metric && !metrics.list?.data.some((row) => row.id === metric.id) && <option value={metric.id}>{metric.code}</option>}
          {metrics.list?.data.map((row) => <option key={row.id} value={row.id}>{row.scope.game_name} · {row.code}</option>)}
        </SelectField>
        {metrics.error && <Alert tone="error" title={metrics.error.message} />}{metrics.list && <Pagination currentPage={metrics.list.meta.current_page} totalPages={metrics.list.meta.last_page} disabled={blocked || metrics.loading} onPageChange={setMetricPage} />}
        {metric && <MetricVersion key={metric.id} {...{ active, metric, onInvalid }} disabled={blocked} onChange={(row) => { setMetric(row); setMode(''); setRole(''); setRelease('') }} />}
        {([['person', person, setPerson], ['second', second, setSecond]] as const).map(([name, value, change]) => <SelectField key={name} name={name} title={text(name)} value={value} onChange={change} disabled={blocked || people.loading} required={name === 'person'}>
          <option value="">{name === 'person' ? '—' : text('noComparison')}</option>{value && !people.list?.data.some((entry) => entry.id === value) && <option value={value}>{value}</option>}
          {people.list?.data.map((entry) => <option key={entry.id} value={entry.id} disabled={entry.id === (name === 'person' ? second : person)}>{entry.label}</option>)}
        </SelectField>)}
        {people.error && <Alert tone="error" title={people.error.message} />}{people.list && <Pagination currentPage={people.list.meta.current_page} totalPages={people.list.meta.last_page} disabled={blocked || people.loading} onPageChange={setPeoplePage} />}
        {people.list?.data.length === 0 && <p>{text('noPeople')}</p>}
        <FormField name="from" type="date" label={text('from')} defaultValue={month} required disabled={blocked} />
        <FormField name="to" type="date" label={text('to')} defaultValue={today} required disabled={blocked} />
        <SelectField name="source_kind" title={text('source_kind')} defaultValue="" disabled={blocked}><option value="">{text('all')}</option>{observationSources.map((source) => <option key={source} value={source}>{observationText(source)}</option>)}</SelectField>
        {['source_label', 'season', 'region', 'map', 'opponent'].map((name) => <FormField key={name} name={name} label={text(name)} maxLength={name === 'source_label' ? 500 : ['season', 'region'].includes(name) ? 80 : 160} disabled={blocked} />)}
        {metric && <div className="grid min-w-0 gap-3"><MetricReference key={'mode:' + metric.id} kind="mode" name="mode_id" game={metric.game_id} value={mode} onChange={setMode} disabled={blocked} />
          <MetricReference key={'role:' + metric.id} kind="role" name="role_id" game={metric.game_id} value={role} onChange={setRole} disabled={blocked} />
          <MetricReference key={'release:' + metric.id} kind="release" name="release_id" game={metric.game_id} value={release} onChange={setRelease} disabled={blocked} />
        </div>}
        <Button type="submit" disabled={blocked || !metric || !person}>{text('show')}</Button>
      </form>
      {loading && <p role="status">{text('loading')}</p>}{error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
      {error && Object.entries(error.fieldErrors).map(([key, messages]) => <p key={key} role="alert">{messages.join(' ')}</p>)}
      {data && <section aria-label={text('results')} className="grid min-w-0 gap-4 [overflow-wrap:anywhere]">
        <p>{data.metric.scope.game_name} · {data.metric.code} · v{data.metric.number} · {data.metric.unit}</p>
        <p>{text('generated')} : {date(data.generated_at)} · {text('live')}</p><p>{text('window')} : {String(data.filters.from)} → {String(data.filters.to)}</p>
        <Button disabled={blocked} onClick={() => query && setQuery({ ...query, token: Date.now() })}>{text('refresh')}</Button>
        <div className="grid min-w-0 gap-4 xl:grid-cols-2">{data.profiles.map((profile) => <article key={profile.person_id} className="grid min-w-0 content-start gap-3 rounded-xl border border-slate-700 p-3">
          <h3 className="font-bold">{profile.person_label}</h3><p>{text('sourceCount')} : {profile.source_count}</p>
          <p>{text('observed')} : {profile.latest_observed_on ?? text('unknown')} · {text('updated')} : {date(profile.last_updated_at)}</p>
          {profile.source_count === 0 && <p>{text('empty')}</p>}
          <h4>{text('groups')}</h4>{profile.groups.data.map((group) => <Group key={group.key} group={group} />)}
          <Pagination currentPage={profile.groups.meta.current_page} totalPages={profile.groups.meta.last_page} disabled={blocked} onPageChange={(value) => page('page', value)} />
          <h4>{text('timeline')}</h4><p>{text('timelineHint')}</p><ol className="grid min-w-0 gap-3">{profile.timeline.data.map((row) => <li key={row.id} className="grid min-w-0 gap-2 rounded-xl border border-slate-700 p-3">
            <p>{row.metric_code} · v{row.metric_number} · {row.metric_unit} · {row.period_start} → {row.period_end}</p><Context context={row.context} labels={row.context_labels} />
            <p>{text('value')} : {row.status === 'voided' ? text('voided') : row.availability === 'available' ? row.value : text('unavailable')} · {text('sample')} : {row.sample_size}</p>
            <p>{text('observed')} : {row.observed_on} · {text('updated')} : {date(row.last_updated_at)}</p>
          </li>)}</ol><Pagination currentPage={profile.timeline.meta.current_page} totalPages={profile.timeline.meta.last_page} disabled={blocked} onPageChange={(value) => page('timeline_page', value)} />
        </article>)}</div>
        {data.comparison && <section aria-label={text('comparison')} className="grid min-w-0 gap-3"><h3 className="font-bold">{text('comparison')}</h3><p>{text('comparisonHint')}</p>
          {data.comparison.meta.total === 0 && <p>{text('empty')}</p>}
          {data.comparison.data.map((pair) => <article key={pair.key} className="grid min-w-0 gap-3 rounded-xl border border-slate-700 p-3"><Context context={pair.context} labels={pair.context_labels} />
            <p>{pair.comparable ? text('comparable') : text(pair.reason ?? 'unavailable')}</p>
            {pair.comparable && <p>{text('difference')} ({data.profiles[0].person_label} − {data.profiles[1].person_label}) : {pair.difference}</p>}
            {pair.unequal_samples && <p>{text('unequalSamples')}</p>}
            <p>{data.profiles[0].person_label} · {text('sample')} : {pair.left?.result?.sample_size ?? text('unknown')} · {text('window')} : {pair.left ? pair.left.period_start + ' → ' + pair.left.period_end : text('unknown')}</p>
            <p>{data.profiles[1].person_label} · {text('sample')} : {pair.right?.result?.sample_size ?? text('unknown')} · {text('window')} : {pair.right ? pair.right.period_start + ' → ' + pair.right.period_end : text('unknown')}</p>
          </article>)}<Pagination currentPage={data.comparison.meta.current_page} totalPages={data.comparison.meta.last_page} disabled={blocked} onPageChange={(value) => page('page', value)} />
        </section>}
      </section>}
    </>}
  </section>
}
function Group({ group }: { group: ProfileGroup }) {
  return <section className="grid min-w-0 gap-2 rounded-xl border border-slate-700 p-3"><Context context={group.context} labels={group.context_labels} />
    <p>{text('window')} : {group.period_start} → {group.period_end} · {text('periodCount')} : {group.period_count}</p>
    {group.issue ? <p>{text(group.issue)}</p> : group.result && <CalculationResult result={group.result} />}
    <p>{text('observed')} : {group.latest_observed_on} · {text('updated')} : {date(group.last_updated_at)}</p>
  </section>
}
function Context({ context, labels }: { context: ProfileContext; labels: ProfileContext }) {
  return <dl className="grid min-w-0 gap-1">{Object.entries(context).map(([key, value]) => <div key={key} className="min-w-0 [overflow-wrap:anywhere]"><dt className="inline font-medium">{text(key)} : </dt><dd className="inline">{key === 'source_kind' && value ? observationText(value) : labels[key] ?? value ?? text('unknown')}</dd></div>)}</dl>
}
function date(value: string | null) { return value ? new Date(value).toLocaleString('fr-FR') : text('unknown') }
function MetricVersion({ active, metric, onInvalid, disabled, onChange }: { active: ActiveOrganization; metric: MetricDefinition; onInvalid: () => void; disabled: boolean; onChange: (row: MetricDefinition) => void }) {
  const [page, setPage] = useState(1)
  const history = usePartnershipList<MetricDefinition>('metric-catalogue/' + metric.id + '/history?per_page=10&page=' + page, active, true, onInvalid)
  return <div className="grid min-w-0 gap-2"><SelectField name="profile_version" title={text('version')} value={metric.version} disabled={disabled || history.loading} onChange={(version) => { const row = history.list?.data.find((row) => row.version === version); if (row) onChange(row) }}>
    {!history.list?.data.some((row) => row.version === metric.version) && <option value={metric.version}>v{metric.number}</option>}
    {history.list?.data.map((row) => <option key={row.version} value={row.version}>v{row.number} · {date(row.occurred_at)}</option>)}
  </SelectField>{history.error && <Alert tone="error" title={history.error.message} />}{history.list && <Pagination currentPage={history.list.meta.current_page} totalPages={history.list.meta.last_page} disabled={disabled || history.loading} onPageChange={setPage} />}</div>
}
