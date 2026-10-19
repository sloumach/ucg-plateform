import { useEffect, useState, type FormEvent } from 'react'
import { tenancyRequest, type Page } from '../../api/tenancy'
import type { ApiFieldErrors } from '../../api/contracts'
import type { CatalogueEntry } from '../../api/gameCatalogue'
import { metricAggregations, metricLevels, metricAvailability, metricText as text, type MetricDefinition } from '../../api/metricCatalogue'
import { apiError } from '../people/peopleFormValues'
import { FormField } from '../FormField'
import { SelectField } from '../people/peopleForms'
import { Button } from '../Button'
import { Alert } from '../feedback/Alert'
import { Pagination } from '../data/Pagination'
export function MetricCatalogueForm({ record, disabled, errors, onCancel, onSubmit }: { record: MetricDefinition | null; disabled: boolean; errors: ApiFieldErrors; onCancel: () => void; onSubmit: (e: FormEvent<HTMLFormElement>, body: Record<string, unknown>) => void }) {
  const [game, setGame] = useState(record?.game_id ?? ''), [mode, setMode] = useState(record?.mode_id ?? ''), [role, setRole] = useState(record?.role_id ?? ''), [release, setRelease] = useState(record?.release_id ?? '')
  const [aggregation, setAggregation] = useState(record?.aggregation ?? 'sum'), [zero, setZero] = useState(record?.zero_denominator ?? 'not_applicable')
  const field = (key: string, value: string | undefined, limit: number) => <FormField name={key} label={text(key === 'title' ? 'titleField' : key)} defaultValue={value ?? ''} required maxLength={limit} minLength={key === 'reason' ? 10 : undefined} disabled={disabled} error={errors[key]?.[0]} />
  return <form aria-label={text('save')} className="grid min-w-0 gap-3" onSubmit={(e) => {
    const f = new FormData(e.currentTarget), value = (key: string) => String(f.get(key) ?? '').trim()
    const ids = (key: string) => value(key).split(/[\s,;]+/).filter(Boolean).map((id) => id.toLowerCase())
    const recipe = aggregation === 'ratio' && (value('ratio_numerator') || value('ratio_denominator')) ? { numerator: ids('ratio_numerator'), denominator: ids('ratio_denominator') } : null
    const originalRecipe = record?.ratio_components ? { numerator: record.ratio_components.numerator.map((component) => component.metric_id), denominator: record.ratio_components.denominator.map((component) => component.metric_id) } : null
    const recipeInput = record && aggregation === 'ratio' && JSON.stringify(recipe) === JSON.stringify(originalRecipe) ? {} : { ratio_components: recipe }
    onSubmit(e, { ...(record ? { version: record.version } : { code: value('code'), game_id: game }), mode_id: mode || null, role_id: role || null, release_id: release || null, title: value('title'), definition: value('definition'), unit: value('unit'), aggregation, ...recipeInput, aggregation_level: value('aggregation_level'), formula: value('formula'), zero_denominator: zero, measurement_kind: value('measurement_kind'), availability: value('availability'), availability_note: value('availability_note'), status: value('status'), reason: value('reason') })
  }}>
    <p className="text-sm text-slate-400">{text('identityHint')}</p>
    {record ? <p className="[overflow-wrap:anywhere]">{record.code} · {record.scope.game_name}</p> : <>{field('code', undefined, 64)}<MetricReference kind="game" name="game_id" value={game} onChange={(v) => { setGame(v); setMode(''); setRole(''); setRelease('') }} disabled={disabled} error={errors.game_id?.[0]} required /></>}
    {field('title', record?.title, 160)}{field('definition', record?.definition, 4000)}{field('unit', record?.unit, 40)}
    <SelectField name="aggregation" title={text('aggregation')} value={aggregation} onChange={(v) => { const next = v as MetricDefinition['aggregation']; setAggregation(next); setZero(next === 'ratio' ? 'unavailable' : 'not_applicable') }} disabled={disabled} error={errors.aggregation?.[0]}>{metricAggregations.map((v) => <option key={v} value={v}>{text(v)}</option>)}</SelectField>
    <SelectField name="aggregation_level" title={text('aggregation_level')} defaultValue={record?.aggregation_level ?? 'game'} disabled={disabled} error={errors.aggregation_level?.[0]}>{metricLevels.map((v) => <option key={v} value={v}>{text('level.' + v)}</option>)}</SelectField>
    {field('formula', record?.formula, 2000)}
    {aggregation === 'ratio' && <div className="grid min-w-0 gap-3"><p>{text('componentsHint')}</p>
      <FormField name="ratio_numerator" label={text('ratio_numerator')} defaultValue={record?.ratio_components?.numerator.map((component) => component.metric_id).join(',') ?? ''} maxLength={150} disabled={disabled} error={errors.ratio_components?.[0]} />
      <FormField name="ratio_denominator" label={text('ratio_denominator')} defaultValue={record?.ratio_components?.denominator.map((component) => component.metric_id).join(',') ?? ''} maxLength={150} disabled={disabled} />
    </div>}
    <SelectField name="zero_denominator" title={text('zero_denominator')} value={zero} onChange={(v) => setZero(v as MetricDefinition['zero_denominator'])} disabled={disabled} error={errors.zero_denominator?.[0]}>{(aggregation === 'ratio' ? ['unavailable', 'zero'] : ['not_applicable']).map((v) => <option key={v} value={v}>{text(v)}</option>)}</SelectField>
    <SelectField name="measurement_kind" title={text('measurement_kind')} defaultValue={record?.measurement_kind ?? 'objective'} disabled={disabled} error={errors.measurement_kind?.[0]}>{['objective', 'subjective'].map((v) => <option key={v} value={v}>{text(v)}</option>)}</SelectField>
    <SelectField name="availability" title={text('availability')} defaultValue={record?.availability ?? 'unavailable'} disabled={disabled} error={errors.availability?.[0]}>{metricAvailability.map((v) => <option key={v} value={v}>{text(v)}</option>)}</SelectField>{field('availability_note', record?.availability_note, 2000)}
    {!game && <p>{text('chooseGame')}</p>}{game && <>
      <MetricReference key={'mode:' + game} kind="mode" name="mode_id" game={game} value={mode} onChange={setMode} disabled={disabled} error={errors.mode_id?.[0]} fallback={record?.scope.mode_name} />
      <MetricReference key={'role:' + game} kind="role" name="role_id" game={game} value={role} onChange={setRole} disabled={disabled} error={errors.role_id?.[0]} fallback={record?.scope.role_name} />
      <MetricReference key={'release:' + game} kind="release" name="release_id" game={game} value={release} onChange={setRelease} disabled={disabled} error={errors.release_id?.[0]} fallback={record?.scope.release_name} />
    </>}
    <SelectField name="status" title={text('status')} defaultValue={record?.status ?? 'active'} disabled={disabled} error={errors.status?.[0]}>{['active', 'retired'].map((v) => <option key={v} value={v}>{text(v)}</option>)}</SelectField>{field('reason', undefined, 1000)}
    <div className="flex flex-wrap gap-3"><Button type="submit" disabled={disabled || !game}>{text('save')}</Button><Button disabled={disabled} onClick={onCancel}>{text('cancel')}</Button></div>
  </form>
}
export function MetricReference({ kind, name, game, value, onChange, disabled, error, fallback, required = false }: { kind: 'game' | 'mode' | 'role' | 'release'; name: string; game?: string; value: string; onChange: (v: string) => void; disabled: boolean; error?: string; fallback?: string | null; required?: boolean }) {
  const [page, setPage] = useState(1), [list, setList] = useState<Page<CatalogueEntry> | null>(null), [failure, setFailure] = useState<ReturnType<typeof apiError> | null>(null)
  useEffect(() => {
    const controller = new AbortController(), load = async () => {
      setList(null); setFailure(null)
      try { const data = await tenancyRequest<Page<CatalogueEntry>>('game-catalogue?kind=' + kind + '&per_page=10&page=' + page + (game ? '&game_id=' + game : ''), { signal: controller.signal }); if (!controller.signal.aborted) setList(data) }
      catch (e) { if (!controller.signal.aborted) setFailure(apiError(e)) }
    }; void load(); return () => controller.abort()
  }, [kind, game, page])
  return <div className="grid min-w-0 gap-2"><SelectField name={name} title={text(name)} value={value} onChange={onChange} required={required} disabled={disabled || !list} error={error}><option value="">{required ? '—' : text('all')}</option>{value && !list?.data.some((e) => e.id === value) && <option value={value}>{fallback ?? value}</option>}{list?.data.map((e) => <option key={e.id} value={e.id} disabled={!e.active}>{e.name}</option>)}</SelectField>
    {failure && <Alert tone="error" title={failure.message} />}{!list && !failure && <p role="status">{text('loading')}</p>}{list?.data.length === 0 && <p>{text('noReference')}</p>}{list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={disabled} onPageChange={setPage} />}
  </div>
}
