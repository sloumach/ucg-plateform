import { useEffect, useState, type FormEvent } from 'react'
import { tenancyRequest, type ActiveOrganization, type Page } from '../../api/tenancy'
import type { OrganizationGame } from '../../api/gameCatalogue'
import { isAbortError } from '../../api/errors'
import { teamKinds, teamLabel as text, teamStatuses, teamsPath, type Team } from '../../api/teams'
import { t } from '../../i18n/fr'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { apiError, changedValues, shouldReloadContext } from '../people/peopleFormValues'
import { SelectField } from '../people/peopleForms'
import { usePeopleMutation } from '../people/usePeopleMutation'
import { TeamMembershipPanel } from './TeamMembershipPanel'
import { rosterText } from '../../api/teamMemberships'
export function TeamsPanel({ active, disabled, onInvalid }: { active: ActiveOrganization; disabled: boolean; onInvalid: () => void }) {
  const org = active.context?.organization_id, revision = active.revision ?? undefined
  const allowed = active.context?.permissions.includes('sports.read') ?? false
  const canManage = allowed && (active.context?.permissions.includes('teams.manage') ?? false)
  const [open, setOpen] = useState(false), [page, setPage] = useState(1), [refresh, setRefresh] = useState(0)
  const [filters, setFilters] = useState<Record<string, string>>({})
  const [list, setList] = useState<Page<Team> | null>(null), [games, setGames] = useState<OrganizationGame[]>([])
  const [editor, setEditor] = useState<Team | 'new' | null>(null)
  const [roster, setRoster] = useState<Team | null>(null)
  const [loading, setLoading] = useState(false), [error, setError] = useState<ReturnType<typeof apiError> | null>(null)
  const mutation = usePeopleMutation<Team>(active, () => { setEditor(null); setRefresh((value) => value + 1) }, onInvalid)
  useEffect(() => {
    if (!org || !allowed || !open) return
    const controller = new AbortController()
    const load = async () => {
      setLoading(true); setList(null); setEditor(null); setRoster(null); setError(null)
      const query = new URLSearchParams({ per_page: '10', page: String(page), ...filters })
      try {
        const [next, catalogue] = await Promise.all([
          tenancyRequest<Page<Team>>(teamsPath(org) + '?' + query, { revision, signal: controller.signal }),
          tenancyRequest<Page<OrganizationGame>>('tenants/' + org + '/games?per_page=100&page=1', { revision, signal: controller.signal }),
        ])
        if (!controller.signal.aborted) { setList(next); setGames(catalogue.data) }
      } catch (failure) {
        if (controller.signal.aborted || isAbortError(failure)) return
        const next = apiError(failure); setError(next); if (shouldReloadContext(next)) onInvalid()
      } finally { if (!controller.signal.aborted) setLoading(false) }
    }
    void load(); return () => controller.abort()
  }, [org, revision, allowed, open, page, filters, refresh, onInvalid])
  if (!org || !allowed) return null
  const blocked = disabled || loading || mutation.blocked
  function filter(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setFilters(Object.fromEntries([...new FormData(event.currentTarget)].filter(([, value]) => value !== '').map(([key, value]) => [key, String(value)])))
    setPage(1)
  }
  return <section aria-label={text('title')} className="grid min-w-0 gap-4 rounded-xl border border-slate-700 p-4">
    <Button disabled={disabled || mutation.blocked} onClick={() => setOpen((value) => !value)}>{open ? text('close') : text('title')}</Button>
    {open && <>
      {!canManage && <p>{text('readOnly')}</p>}
      {loading && <p role="status">{text('loading')}</p>}
      {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
      {mutation.feedback}
      <form aria-label={text('search')} onSubmit={filter} className="grid min-w-0 gap-3 sm:grid-cols-2">
        <FormField name="q" label={text('q')} maxLength={80} disabled={blocked} />
        <FormField name="season" label={text('season')} maxLength={64} disabled={blocked} />
        <SelectField name="kind" title={text('kind')} disabled={blocked}><option value="">{text('all')}</option>{teamKinds.map((kind) => <option key={kind} value={kind}>{text('kind.' + kind)}</option>)}</SelectField>
        <SelectField name="status" title={text('status')} disabled={blocked}><option value="">{text('all')}</option>{teamStatuses.map((status) => <option key={status} value={status}>{text('status.' + status)}</option>)}</SelectField>
        <SelectField name="game_id" title={text('game')} disabled={blocked}><option value="">{text('all')}</option>{games.map((game) => <option key={game.id} value={game.id}>{game.name}</option>)}</SelectField>
        <SelectField name="sort" title={text('sort')} disabled={blocked}><option value="name">{text('sort.name')}</option><option value="newest">{text('sort.newest')}</option></SelectField>
        <Button type="submit" disabled={blocked}>{text('search')}</Button>
      </form>
      {canManage && <Button disabled={blocked || !games.some((game) => game.enabled)} onClick={() => setEditor('new')}>{text('create')}</Button>}
      {canManage && !loading && !games.some((game) => game.enabled) && <p>{text('noGames')}</p>}
      <p className="text-sm text-slate-400">{text('private')}</p>
      {list?.data.length === 0 && <p>{text('empty')}</p>}
      {list && <ul className="grid min-w-0 gap-3 sm:grid-cols-2">{list.data.map((team) => <li key={team.id} className="grid min-w-0 gap-2 rounded-xl border border-slate-700 p-3">
        <h3 className="break-words font-bold [overflow-wrap:anywhere]">{team.name}</h3>
        <p className="break-words [overflow-wrap:anywhere]">{team.game_name} · {team.season} · {text('kind.' + team.kind)} · {text('status.' + team.status)}</p>
        {team.custom_category && <p>{team.custom_category}</p>}{team.public_description && <p className="break-words [overflow-wrap:anywhere]">{team.public_description}</p>}
        <p className="break-words text-sm text-slate-400 [overflow-wrap:anywhere]">{team.slug}{team.short_name && ' · ' + team.short_name}{team.color && ' · ' + team.color}</p>
        <Button disabled={blocked} onClick={() => { setEditor(null); setRoster(team) }}>{rosterText('title')}</Button>
        {canManage && team.status !== 'archived' && <>
          <Button disabled={blocked} onClick={() => setEditor(team)}>{text('edit')}</Button>
          <form aria-label={text('archive') + ' ' + team.name} onSubmit={(event) => mutation.propose(event, text('archive') + ' ' + team.name, teamsPath(org, team.id), { version: team.version, status: 'archived' }, true)}>
            <Button type="submit" disabled={blocked}>{text('archive')}</Button>
          </form>
        </>}
      </li>)}</ul>}
      {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={blocked} onPageChange={setPage} />}
      {roster && <TeamMembershipPanel key={roster.id + roster.version} active={active} team={roster} disabled={blocked} onInvalid={onInvalid} onClose={() => setRoster(null)} />}
      {editor && <TeamForm key={editor === 'new' ? 'new' : editor.id + editor.version} team={editor === 'new' ? null : editor}
        games={games.filter((game) => game.enabled)} disabled={blocked} errors={mutation.errors(teamsPath(org, editor === 'new' ? undefined : editor.id))}
        onCancel={() => setEditor(null)} onSubmit={(event) => {
          const team = editor === 'new' ? null : editor
          const body = changedValues(event.currentTarget, team)
          mutation.propose(event, text('save'), teamsPath(org, team?.id), { ...body, ...(team ? { version: team.version } : {}) }, !!team)
        }} />}
      <p className="text-sm text-slate-400">{text('archiveHint')}</p>
    </>}
  </section>
}
function TeamForm({ team, games, disabled, errors, onSubmit, onCancel }: {
  team: Team | null; games: OrganizationGame[]; disabled: boolean; errors: Record<string, string[]>
  onSubmit: (event: FormEvent<HTMLFormElement>) => void; onCancel: () => void
}) {
  const [kind, setKind] = useState(team?.kind ?? 'first')
  const [category, setCategory] = useState(team?.custom_category ?? '')
  return <form aria-label={text('save')} onSubmit={onSubmit} className="grid min-w-0 gap-3 rounded-xl border border-cyan-400/30 p-4 sm:grid-cols-2">
    {team ? <p className="break-words sm:col-span-2 [overflow-wrap:anywhere]">{team.game_name} · {team.slug} · {team.season}. {text('stable')}</p> : <>
      <SelectField name="game_id" title={text('game')} disabled={disabled} defaultValue="" error={errors.game_id?.[0]}><option value="">{text('chooseGame')}</option>{games.map((game) => <option value={game.id} key={game.id}>{game.name}</option>)}</SelectField>
      <FormField name="slug" label={text('slug')} required maxLength={80} disabled={disabled} error={errors.slug?.[0]} />
      <FormField name="season" label={text('season')} required maxLength={64} disabled={disabled} error={errors.season?.[0]} />
    </>}
    <FormField name="name" label={text('name')} defaultValue={team?.name ?? ''} required maxLength={120} disabled={disabled} error={errors.name?.[0]} />
    <SelectField name="kind" title={text('kind')} value={kind} disabled={disabled} onChange={(value) => { setKind(value as Team['kind']); if (value !== 'custom') setCategory('') }} error={errors.kind?.[0]}>
      {teamKinds.map((value) => <option key={value} value={value}>{text('kind.' + value)}</option>)}
    </SelectField>
    {kind === 'custom' ? <FormField name="custom_category" label={text('custom_category')} value={category} onChange={(event) => setCategory(event.target.value)} maxLength={80} disabled={disabled}
      required error={errors.custom_category?.[0]} /> : <input type="hidden" name="custom_category" value="" />}
    <FormField name="short_name" label={text('short_name')} defaultValue={team?.short_name ?? ''} maxLength={16} disabled={disabled} error={errors.short_name?.[0]} />
    <FormField name="color" label={text('color')} defaultValue={team?.color ?? ''} maxLength={7} disabled={disabled} error={errors.color?.[0]} />
    <FormField name="public_description" label={text('public_description')} defaultValue={team?.public_description ?? ''} maxLength={1000} disabled={disabled} error={errors.public_description?.[0]} />
    <SelectField name="status" title={text('status')} defaultValue={team?.status ?? 'draft'} disabled={disabled}>
      {teamStatuses.filter((value) => value !== 'archived').map((value) => <option key={value} value={value}>{text('status.' + value)}</option>)}
    </SelectField>
    <div className="flex flex-wrap gap-3 sm:col-span-2"><Button type="submit" disabled={disabled}>{text('save')}</Button><Button disabled={disabled} onClick={onCancel}>{t('tenant.cancel')}</Button></div>
  </form>
}
