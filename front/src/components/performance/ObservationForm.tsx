import { useEffect, useState, type FormEvent } from 'react'
import type { ActiveOrganization, Page } from '../../api/tenancy'
import type { ApiFieldErrors } from '../../api/contracts'
import type { MetricDefinition } from '../../api/metricCatalogue'
import { metricText } from '../../api/metricCatalogue'
import { observationText as text, observationSources, type StatisticalObservation } from '../../api/observations'
import { MetricReference } from './MetricCatalogueForm'
import { usePartnershipList } from '../partnerships/usePartnershipList'
import { SelectField } from '../people/peopleForms'
import { FormField } from '../FormField'
import { Button } from '../Button'
import { Alert } from '../feedback/Alert'
import { Pagination } from '../data/Pagination'

type Props = { active: ActiveOrganization; root: string; record: StatisticalObservation | null; disabled: boolean; errors: ApiFieldErrors; onInvalid: () => void; onCancel: () => void; onSubmit: (event: FormEvent<HTMLFormElement>, body: Record<string, unknown>) => void }
export function ObservationForm({ active, root, record, disabled, errors, onInvalid, onCancel, onSubmit }: Props) {
  const [metric, setMetric] = useState<MetricDefinition | null>(record?.context.metric ?? null), [person, setPerson] = useState(''), [match, setMatch] = useState(''), [part, setPart] = useState('')
  const [mode, setMode] = useState(''), [role, setRole] = useState(''), [release, setRelease] = useState('')
  const [availability, setAvailability] = useState(record?.availability ?? 'available')
  const field = (name: string, value: string | number | null | undefined, required = false, maxLength = 160, type = 'text') => <FormField key={name} name={name} label={text(name)} defaultValue={value ?? ''} disabled={disabled} required={required} error={errors[name]?.[0]} maxLength={maxLength} type={type} />
  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); const data = new FormData(event.currentTarget), body: Record<string, unknown> = {}
    for (const name of ['value', 'missing_reason', 'notes', 'reason', 'status']) body[name] = String(data.get(name) ?? '').trim() || null
    body.availability = availability; body.value = availability === 'available' ? body.value : null; body.missing_reason = availability === 'unavailable' ? body.missing_reason : null
    if (record) body.version = record.version
    else {
      body.mode_id = mode || null; body.role_id = role || null; body.release_id = release || null; body.metric_id = metric?.id; body.metric_version = metric?.version; body.person_id = person; body.match_id = match || null; body.match_game_id = part || null
      for (const name of ['source_kind', 'source_label', 'period_start', 'period_end', 'observed_on', 'region', 'season', 'map', 'opponent', 'session_label']) body[name] = String(data.get(name) ?? '').trim() || null
      body.sample_size = Number(data.get('sample_size')); body.round_number = data.get('round_number') ? Number(data.get('round_number')) : null; body.status = 'active'
    }
    onSubmit(event, body)
  }
  return <form aria-label={text(record ? 'correct' : 'create')} onSubmit={submit} className="grid min-w-0 gap-3">
    {errors.record && <Alert tone="error" title={errors.record[0]} />}
    {!record && <>
      <MetricPicker {...{ active, disabled, onInvalid }} metric={metric} error={errors.metric_id?.[0] ?? errors.metric_version?.[0]} onChange={(m) => { setMetric(m); setMatch(''); setPart(''); setMode(m?.mode_id ?? ''); setRole(m?.role_id ?? ''); setRelease(m?.release_id ?? '') }} />
      <ObservationChoice {...{ active, disabled, onInvalid }} path={root + '/choices?kind=people'} name="person_id" value={person} onChange={setPerson} required error={errors.person_id?.[0]} />
      <SelectField name="source_kind" title={text('source_kind')} defaultValue="training" disabled={disabled} error={errors.source_kind?.[0]}>{observationSources.map((s) => <option key={s} value={s}>{text(s)}</option>)}</SelectField>
      {field('source_label', null, true, 500)}
      {metric && <>
        <MetricReference key={'mode:' + metric.id} kind="mode" name="mode_id" game={metric.game_id} value={mode} onChange={setMode} disabled={disabled || metric.mode_id !== null} error={errors.mode_id?.[0]} fallback={metric.scope.mode_name} />
        <MetricReference key={'role:' + metric.id} kind="role" name="role_id" game={metric.game_id} value={role} onChange={setRole} disabled={disabled || metric.role_id !== null} error={errors.role_id?.[0]} fallback={metric.scope.role_name} />
        <MetricReference key={'release:' + metric.id} kind="release" name="release_id" game={metric.game_id} value={release} onChange={setRelease} disabled={disabled || metric.release_id !== null} error={errors.release_id?.[0]} fallback={metric.scope.release_name} />
        <p>{text('metricVersion')} : {metric.code} · v{metric.number} · {metricText('level.' + metric.aggregation_level)}</p>
        <p className="[overflow-wrap:anywhere]">{metric.formula} · {metricText(metric.measurement_kind)} · {metric.availability_note}</p>
        <ObservationChoice key={'match:' + metric.game_id} {...{ active, disabled, onInvalid }} path={root + '/choices?kind=matches&game_id=' + metric.game_id} name="match_id" value={match} onChange={(id) => { setMatch(id); setPart('') }} required={['match', 'game', 'round'].includes(metric.aggregation_level)} error={errors.match_id?.[0]} />
        {match && <ObservationChoice key={match} {...{ active, disabled, onInvalid }} path={root + '/choices?kind=parts&match_id=' + match} name="match_game_id" value={part} onChange={setPart} required={['game', 'round'].includes(metric.aggregation_level)} error={errors.match_game_id?.[0]} />}
        {metric.aggregation_level === 'round' && field('round_number', null, true, 5, 'number')}{metric.aggregation_level === 'session' && field('session_label', null, true)}
      </>}
      {['period_start', 'period_end', 'observed_on'].map((name) => field(name, null, true, 10, 'date'))}{field('sample_size', 1, true, 7, 'number')}
      {['region', 'season', 'map', 'opponent'].map((name) => field(name, null, false, name === 'region' || name === 'season' ? 80 : 160))}
    </>}
    {record && <p>{text('immutableHint')}</p>}
    <SelectField name="availability" title={text('availability')} value={availability} onChange={(v) => setAvailability(v as 'available' | 'unavailable')} disabled={disabled} error={errors.availability?.[0]}>{['available', 'unavailable'].map((s) => <option key={s} value={s}>{text(s)}</option>)}</SelectField>
    {availability === 'available' ? field('value', record?.value, true, 20) : field('missing_reason', record?.missing_reason, true, 1000)}
    <p className="text-sm text-slate-400">{text('decimalHint')}</p>{field('notes', record?.notes, false, 4000)}
    {record && <SelectField name="status" title={text('status')} defaultValue={record.status} disabled={disabled} error={errors.status?.[0]}>{['active', 'voided'].map((s) => <option key={s} value={s}>{text(s)}</option>)}</SelectField>}
    {field('reason', null, true, 1000)}<div className="flex flex-wrap gap-3"><Button type="submit" disabled={disabled || (!record && (!metric || !person))}>{text('save')}</Button><Button disabled={disabled} onClick={onCancel}>{text('cancel')}</Button></div>
  </form>
}
export function ObservationChoice({ active, path, name, value, onChange, disabled, onInvalid, required = false, error }: { active: ActiveOrganization; path: string; name: string; value: string; onChange: (v: string) => void; disabled: boolean; onInvalid: () => void; required?: boolean; error?: string }) {
  const [page, setPage] = useState(1), { list, loading, error: failure } = usePartnershipList<{ id: string; label: string }>(path + '&page=' + page + '&per_page=10', active, true, onInvalid)
  return <div className="grid min-w-0 gap-2"><SelectField name={name} title={text(name)} value={value} onChange={onChange} disabled={disabled || loading || !list} required={required} error={error}><option value="">{text('none')}</option>{value && !list?.data.some((r) => r.id === value) && <option value={value}>{value}</option>}{list?.data.map((row) => <option key={row.id} value={row.id}>{row.label}</option>)}</SelectField>{failure && <Alert tone="error" title={failure.message} />}{list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} onPageChange={setPage} disabled={disabled || loading} />}</div>
}
function MetricPicker({ active, metric, onChange, disabled, onInvalid, error }: { active: ActiveOrganization; metric: MetricDefinition | null; onChange: (m: MetricDefinition | null) => void; disabled: boolean; onInvalid: () => void; error?: string }) {
  const [page, setPage] = useState(1), { list, loading, error: failure } = usePartnershipList<MetricDefinition, Page<MetricDefinition>>('metric-catalogue?status=active&per_page=10&page=' + page, active, true, onInvalid)
  useEffect(() => { if (list && metric && list.data.some((r) => r.id === metric.id && r.version !== metric.version)) onChange(null) }, [list, metric, onChange])
  return <div className="grid min-w-0 gap-2"><SelectField name="metric_id" title={text('metric_id')} value={metric?.id ?? ''} onChange={(id) => onChange(list?.data.find((r) => r.id === id) ?? null)} required disabled={disabled || loading || !list} error={error}><option value="">{text('none')}</option>{metric && !list?.data.some((r) => r.id === metric.id) && <option value={metric.id}>{metric.title}</option>}{list?.data.map((r) => <option key={r.id} value={r.id} disabled={r.availability === 'unavailable'}>{r.scope.game_name} · {r.code} · {r.title}</option>)}</SelectField>{failure && <Alert tone="error" title={failure.message} />}{list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} onPageChange={setPage} disabled={disabled || loading} />}</div>
}
