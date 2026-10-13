import { useEffect, useState } from 'react'
import { tenancyRequest, type ActiveOrganization, type Page } from '../../api/tenancy'
import { eligibilityPath, eligibilityText as text, type EligibilityRuleset, type EligibilityReport } from '../../api/teamEligibility'
import { getOrganizationGame, type OrganizationGameDetail } from '../../api/gameCatalogue'
import { teamsPath, type Team } from '../../api/teams'
import type { TeamLineup } from '../../api/teamLineups'
import { isAbortError } from '../../api/errors'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { SelectField } from '../people/peopleForms'
import { apiError, isoDate, shouldReloadContext } from '../people/peopleFormValues'
import { usePeopleMutation } from '../people/usePeopleMutation'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'

type Props = { active: ActiveOrganization; team: Team; disabled: boolean; onInvalid: () => void; onClose: () => void }

function useRules(active: ActiveOrganization, team: Team, onInvalid: () => void, activeOnly: boolean, refresh = 0) {
  const [list, setList] = useState<Page<EligibilityRuleset> | null>(null), [page, setPage] = useState(1)
  const [error, setError] = useState<ReturnType<typeof apiError> | null>(null), [loading, setLoading] = useState(false)
  const org = active.context!.organization_id, revision = active.revision ?? undefined
  useEffect(() => {
    const controller = new AbortController()
    const load = async () => {
      setLoading(true); setList(null); setError(null)
      try {
        const result = await tenancyRequest<Page<EligibilityRuleset>>(eligibilityPath(org) + '?' + new URLSearchParams({ game_id: team.game_id, page: String(page), per_page: '10', ...(activeOnly ? { status: 'active' } : {}) }), { revision, signal: controller.signal })
        if (!controller.signal.aborted) setList(result)
      } catch (failure) {
        if (controller.signal.aborted || isAbortError(failure)) return
        const next = apiError(failure); setError(next); if (shouldReloadContext(next)) onInvalid()
      } finally { if (!controller.signal.aborted) setLoading(false) }
    }
    void load(); return () => controller.abort()
  }, [org, revision, team.game_id, page, refresh, activeOnly, onInvalid])
  return { list, page, setPage, error, loading }
}

export function TeamEligibilityRulesPanel({ active, team, disabled, onInvalid, onClose }: Props) {
  const [refresh, setRefresh] = useState(0), [create, setCreate] = useState(false)
  const rules = useRules(active, team, onInvalid, false, refresh)
  const org = active.context!.organization_id, path = eligibilityPath(org)
  const manage = active.context!.permissions.includes('teams.manage')
  const mutation = usePeopleMutation<EligibilityRuleset>(active, () => { setCreate(false); setRefresh((value) => value + 1) }, onInvalid)
  const blocked = disabled || rules.loading || mutation.blocked
  return <section aria-label={text('title')} className="grid min-w-0 grid-cols-1 gap-3 rounded-xl border border-cyan-400/30 p-4 [overflow-wrap:anywhere]">
    <h4 className="font-bold">{text('title')}</h4><p>{text('hint')}</p>
    <Button disabled={mutation.blocked} onClick={onClose}>{text('close')}</Button>
    {rules.loading && <p role="status">{text('loading')}</p>}
    {rules.error && <Alert tone="error" title={rules.error.message} requestId={rules.error.requestId} />}{mutation.feedback}
    {rules.list?.data.length === 0 && <p>{text('empty')}</p>}
    <ul className="grid min-w-0 grid-cols-1 gap-3">{rules.list?.data.map((rule) => <li key={rule.id} className="grid min-w-0 grid-cols-1 gap-2 rounded-xl border border-slate-700 p-3">
      <h5 className="font-bold [overflow-wrap:anywhere]">{rule.name} · {text(rule.status)}</h5>
      <p>{text('min_starters')} : {rule.min_starters} · {text('max_starters')} : {rule.max_starters} · {text('max_substitutes')} : {rule.max_substitutes}</p>
      <p className="[overflow-wrap:anywhere]">{text('platform')} : {rule.platform_key ?? text('noPlatform')} · {text('regions')} : {rule.allowed_regions.join(', ') || '—'} · {text('ranks')} : {rule.allowed_ranks.join(', ') || '—'}</p>
      <p>{text('min_age')} : {rule.min_age ?? '—'} · {text('max_age')} : {rule.max_age ?? '—'} · {text('days')} : {rule.observation_max_days}</p>
      {rule.lock_at && <p>{text('lock')} : {new Date(rule.lock_at).toLocaleString('fr')}</p>}
      {manage && rule.status === 'active' && <form onSubmit={(event) => mutation.propose(event, text('archive') + ' ' + rule.name, path + '/' + rule.id, { version: rule.version, action: 'archive' }, true)}>
        <Button disabled={blocked} type="submit">{text('archive')}</Button>
      </form>}
    </li>)}</ul>
    {rules.list && <Pagination currentPage={rules.list.meta.current_page} totalPages={rules.list.meta.last_page} disabled={blocked} onPageChange={rules.setPage} />}
    {manage && <Button disabled={blocked} onClick={() => setCreate((value) => !value)}>{text('create')}</Button>}
    {create && <RulesForm active={active} team={team} disabled={blocked} errors={mutation.errors(path)} onInvalid={onInvalid}
      onSubmit={(event, body) => mutation.propose(event, text('create'), path, body)} />}
  </section>
}

function RulesForm({ active, team, disabled, errors, onInvalid, onSubmit }: {
  active: ActiveOrganization; team: Team; disabled: boolean; errors: Record<string, string[]>; onInvalid: () => void
  onSubmit: (event: React.FormEvent<HTMLFormElement>, body: Record<string, unknown>) => void
}) {
  const [game, setGame] = useState<OrganizationGameDetail | null>(null), [platform, setPlatform] = useState('')
  const [error, setError] = useState<ReturnType<typeof apiError> | null>(null)
  const org = active.context!.organization_id, revision = active.revision ?? undefined
  useEffect(() => {
    const controller = new AbortController()
    void getOrganizationGame(org, team.game_id, revision, controller.signal).then((result) => { if (!controller.signal.aborted) setGame(result.data) }).catch((failure: unknown) => {
      if (controller.signal.aborted || isAbortError(failure)) return
      const next = apiError(failure); setError(next); if (shouldReloadContext(next)) onInvalid()
    })
    return () => controller.abort()
  }, [org, team.game_id, revision, onInvalid])
  return <form aria-label={text('create')} className="grid min-w-0 grid-cols-1 gap-3" onSubmit={(event) => {
    event.preventDefault(); const data = new FormData(event.currentTarget)
    const number = (key: string) => data.get(key) ? Number(data.get(key)) : null
    onSubmit(event, { game_id: team.game_id, name: data.get('name'), min_starters: number('min_starters'), max_starters: number('max_starters'), max_substitutes: number('max_substitutes'),
      platform_key: platform || null, allowed_regions: platform ? data.getAll('allowed_regions').map(String) : [], allowed_ranks: platform ? String(data.get('allowed_ranks') ?? '').split(/\r?\n/).map((rank) => rank.trim()).filter(Boolean) : [],
      min_age: number('min_age'), max_age: number('max_age'), observation_max_days: number('observation_max_days'), lock_at: isoDate(String(data.get('lock_at') ?? '')) })
  }}>
    {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
    <FormField name="name" label={text('name')} required maxLength={120} disabled={disabled} error={errors.name?.[0]} />
    {(['min_starters', 'max_starters', 'max_substitutes', 'min_age', 'max_age', 'observation_max_days'] as const).map((key) => <FormField key={key} name={key} label={text(key === 'observation_max_days' ? 'days' : key)} type="number"
      min={key.endsWith('age') || key === 'max_substitutes' ? 0 : 1} max={key.endsWith('age') ? 120 : key === 'observation_max_days' ? 3650 : key === 'max_substitutes' ? 49 : 50}
      defaultValue={key === 'min_starters' ? 1 : key === 'max_starters' ? 5 : key === 'max_substitutes' ? 2 : key === 'observation_max_days' ? 30 : ''}
      required={!key.endsWith('age')} disabled={disabled} error={errors[key]?.[0]} />)}
    <p>{text('ageHint')}</p><p>{text('declaredHint')}</p>
    <SelectField name="platform_key" title={text('platform')} value={platform} onChange={setPlatform} disabled={disabled || !game} error={errors.platform_key?.[0]}>
      <option value="">{text('noPlatform')}</option>{game?.effective_options.platform.map((choice) => <option key={choice.key} value={choice.key}>{choice.name}</option>)}
    </SelectField>
    {platform && <><fieldset className="grid min-w-0 grid-cols-1 gap-2"><legend className="[overflow-wrap:anywhere]">{text('regions')}</legend>
      {game?.effective_options.region.map((choice) => <label key={choice.key} className="flex min-w-0 gap-2 [overflow-wrap:anywhere]"><input type="checkbox" name="allowed_regions" value={choice.key} disabled={disabled} /><span>{choice.name}</span></label>)}
      {errors.allowed_regions && <p role="alert">{errors.allowed_regions[0]}</p>}
    </fieldset><label className="grid min-w-0 grid-cols-1 gap-2">{text('ranks')}<textarea name="allowed_ranks" rows={3} maxLength={2592} disabled={disabled} className="min-w-0 w-full rounded-xl border border-white/15 bg-slate-900 p-3" /></label></>}
    <FormField name="lock_at" label={text('lock')} type="datetime-local" step="1" disabled={disabled} error={errors.lock_at?.[0]} />
    {Object.entries(errors).filter(([key]) => key.includes('.')).map(([key, values]) => <p role="alert" key={key}>{values[0]}</p>)}
    <Button type="submit" disabled={disabled || !game}>{text('create')}</Button>
  </form>
}

export function LineupEligibilityPanel({ active, team, lineup, disabled, onInvalid, onClose, onBound }: Props & { lineup: TeamLineup; onBound: () => void }) {
  const [list, setList] = useState<Page<EligibilityReport> | null>(null), [page, setPage] = useState(1), [refresh, setRefresh] = useState(0)
  const [error, setError] = useState<ReturnType<typeof apiError> | null>(null), [loading, setLoading] = useState(false), [choice, setChoice] = useState('')
  const rules = useRules(active, team, onInvalid, true)
  const org = active.context!.organization_id, revision = active.revision ?? undefined
  const path = teamsPath(org, team.id) + '/lineups/' + lineup.id
  const manage = active.context!.permissions.includes('teams.manage') && lineup.status === 'draft'
  const mutation = usePeopleMutation<EligibilityReport | TeamLineup>(active, (result) => {
    if ('entries' in result) onBound(); else setRefresh((value) => value + 1)
  }, onInvalid)
  useEffect(() => {
    const controller = new AbortController()
    const load = async () => {
      setLoading(true); setList(null); setError(null)
      try {
        const result = await tenancyRequest<Page<EligibilityReport>>(path + '/eligibility?' + new URLSearchParams({ page: String(page), per_page: '10' }), { revision, signal: controller.signal })
        if (!controller.signal.aborted) setList(result)
      } catch (failure) {
        if (controller.signal.aborted || isAbortError(failure)) return
        const next = apiError(failure); setError(next); if (shouldReloadContext(next)) onInvalid()
      } finally { if (!controller.signal.aborted) setLoading(false) }
    }
    void load(); return () => controller.abort()
  }, [path, revision, page, refresh, onInvalid])
  const blocked = disabled || loading || mutation.blocked
  const selected = rules.list?.data.find((rule) => rule.id === choice)
  return <section aria-label={text('reports') + ' ' + lineup.label} className="grid min-w-0 grid-cols-1 gap-3 rounded-xl border border-cyan-400/30 p-3 [overflow-wrap:anywhere]">
    <h5 className="font-bold [overflow-wrap:anywhere]">{text('reports')} · {lineup.label}</h5>
    <Button disabled={mutation.blocked} onClick={onClose}>{text('closeReports')}</Button>
    <p>{text('declaredHint')}</p><p>{text('ageHint')}</p>
    {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}{mutation.feedback}
    {loading && <p role="status">{text('loading')}</p>}
    {!lineup.ruleset_id && <p>{text('noRule')}</p>}
    {manage && !lineup.ruleset_id && <form className="grid min-w-0 grid-cols-1 gap-3" onSubmit={(event) => {
      if (selected) mutation.propose(event, text('bind'), path + '/ruleset-binding', { version: lineup.version, ruleset_id: selected.id, ruleset_version: selected.version }); else event.preventDefault()
    }}>
      <p>{text('bindingHint')}</p>
      {rules.error && <Alert tone="error" title={rules.error.message} requestId={rules.error.requestId} />}
      <SelectField name="ruleset_id" title={text('choose')} value={choice} onChange={setChoice} disabled={blocked || rules.loading}>
        <option value="">{text('choose')}</option>{rules.list?.data.map((rule) => <option key={rule.id} value={rule.id}>{rule.name}</option>)}
      </SelectField>
      {rules.list && <Pagination currentPage={rules.list.meta.current_page} totalPages={rules.list.meta.last_page} disabled={blocked} onPageChange={(next) => { setChoice(''); rules.setPage(next) }} />}
      <Button type="submit" disabled={blocked || !selected}>{text('bind')}</Button>
    </form>}
    {lineup.ruleset_id && <p className="[overflow-wrap:anywhere]">{lineup.ruleset_name} {lineup.lock_at ? ' · ' + text('lock') + ' : ' + new Date(lineup.lock_at).toLocaleString('fr') : ''}</p>}
    {manage && lineup.ruleset_id && <form onSubmit={(event) => mutation.propose(event, text('evaluate'), path + '/eligibility', { version: lineup.version })}>
      <p>{text('previewHint')}</p><Button type="submit" disabled={blocked}>{text('evaluate')}</Button>
    </form>}
    {list?.data.length === 0 && <p>{text('noReports')}</p>}
    <ul className="grid min-w-0 grid-cols-1 gap-3">{list?.data.map((report) => <li key={report.id} className="grid min-w-0 grid-cols-1 gap-2 rounded-xl border border-slate-700 p-3">
      <h6 className="font-bold">{text(report.stage)} · {text(report.status)}</h6>
      <p>{new Date(report.evaluated_at).toLocaleString('fr')} · {report.actor_user_id}</p>
      <ul className="grid min-w-0 grid-cols-1 gap-2">{report.checks.map((check, index) => <li className="[overflow-wrap:anywhere]" key={index}>
        {text(check.status)} · {check.message}{check.person_id ? ' · ' + (lineup.entries.find((entry) => entry.person_id === check.person_id)?.pseudonym ?? check.person_id) : ''}
      </li>)}</ul>
    </li>)}</ul>
    {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={blocked} onPageChange={setPage} />}
  </section>
}
