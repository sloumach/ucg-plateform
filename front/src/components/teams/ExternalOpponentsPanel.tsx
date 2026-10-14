import { useEffect, useId, useState, type FormEvent } from 'react'
import { tenancyRequest, type ActiveOrganization, type Page } from '../../api/tenancy'
import { isAbortError } from '../../api/errors'
import type { OrganizationGame } from '../../api/gameCatalogue'
import { opponentKinds, opponentText as text, opponentsPath, type ExternalOpponent, type OpponentSnapshot } from '../../api/externalOpponents'
import { t } from '../../i18n/fr'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { apiError, changedValues, localDate, shouldReloadContext } from '../people/peopleFormValues'
import { SelectField } from '../people/peopleForms'
import { usePeopleMutation } from '../people/usePeopleMutation'

type Props = { active: ActiveOrganization; disabled: boolean; onInvalid: () => void }
export function ExternalOpponentsPanel({ active, disabled, onInvalid }: Props) {
  const org = active.context?.organization_id, revision = active.revision ?? undefined
  const allowed = active.context?.permissions.includes('sports.read') ?? false
  const canManage = allowed && (active.context?.permissions.includes('teams.manage') ?? false)
  const [open, setOpen] = useState(false), [page, setPage] = useState(1), [refresh, setRefresh] = useState(0)
  const [filters, setFilters] = useState<Record<string, string>>({})
  const [list, setList] = useState<Page<ExternalOpponent> | null>(null), [games, setGames] = useState<OrganizationGame[]>([])
  const [editor, setEditor] = useState<ExternalOpponent | 'new' | null>(null)
  const [history, setHistory] = useState<ExternalOpponent | null>(null)
  const [loading, setLoading] = useState(false), [error, setError] = useState<ReturnType<typeof apiError> | null>(null)
  const mutation = usePeopleMutation<ExternalOpponent>(active, () => { setEditor(null); setRefresh((value) => value + 1) }, onInvalid)
  useEffect(() => {
    if (!org || !allowed || !open) return
    const controller = new AbortController()
    const load = async () => {
      setLoading(true); setList(null); setEditor(null); setHistory(null); setError(null)
      const query = new URLSearchParams({ per_page: '10', page: String(page), ...filters })
      await Promise.all([
        tenancyRequest<Page<ExternalOpponent>>(opponentsPath(org) + '?' + query, { revision, signal: controller.signal }),
        tenancyRequest<Page<OrganizationGame>>('tenants/' + org + '/games?per_page=100&page=1', { revision, signal: controller.signal }),
      ]).then(([next, catalogue]) => {
        if (!controller.signal.aborted) { setList(next); setGames(catalogue.data) }
      }).catch((failure: unknown) => {
        if (controller.signal.aborted || isAbortError(failure)) return
        const next = apiError(failure); setError(next); if (shouldReloadContext(next)) onInvalid()
      }).finally(() => { if (!controller.signal.aborted) setLoading(false) })
    }
    void load()
    return () => controller.abort()
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
      <p className="text-sm text-slate-400 [overflow-wrap:anywhere]">{text('private')}</p>
      {!canManage && <p>{text('readOnly')}</p>}
      {loading && <p role="status">{text('loading')}</p>}
      {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
      {mutation.feedback}
      <form aria-label={text('search')} onSubmit={filter} className="grid min-w-0 gap-3 sm:grid-cols-2">
        <FormField name="q" label={text('q')} maxLength={80} disabled={blocked} />
        <SelectField name="kind" title={text('kind')} disabled={blocked}><option value="">{text('all')}</option>{opponentKinds.map((kind) => <option key={kind} value={kind}>{text('kind.' + kind)}</option>)}</SelectField>
        <SelectField name="status" title={text('status')} disabled={blocked}><option value="">{text('all')}</option>{(['active', 'archived'] as const).map((status) => <option key={status} value={status}>{text('status.' + status)}</option>)}</SelectField>
        <SelectField name="game_id" title={text('game')} disabled={blocked}><option value="">{text('all')}</option>{games.map((game) => <option key={game.id} value={game.id}>{game.name}</option>)}</SelectField>
        <SelectField name="sort" title={text('sort')} disabled={blocked}><option value="name">{text('sort.name')}</option><option value="newest">{text('sort.newest')}</option></SelectField>
        <Button type="submit" disabled={blocked}>{text('search')}</Button>
      </form>
      {canManage && <Button disabled={blocked} onClick={() => { setHistory(null); setEditor('new') }}>{text('create')}</Button>}
      {list?.data.length === 0 && <p>{text('empty')}</p>}
      {list && <ul className="grid min-w-0 gap-3 sm:grid-cols-2">{list.data.map((opponent) => <li key={opponent.id} className="grid min-w-0 gap-2 rounded-xl border border-slate-700 p-3">
        <h3 className="font-bold [overflow-wrap:anywhere]">{opponent.name}</h3>
        <p className="[overflow-wrap:anywhere]">{text('kind.' + opponent.kind)} · {text('status.' + opponent.status)}{opponent.game_name && ' · ' + opponent.game_name}</p>
        {opponent.organization_name && <p className="[overflow-wrap:anywhere]">{text('organization_name')} : {opponent.organization_name}</p>}
        {opponent.short_name && <p className="[overflow-wrap:anywhere]">{opponent.short_name}</p>}
        <Source data={opponent} />
        <Button disabled={blocked} onClick={() => { setEditor(null); setHistory(opponent) }}>{text('history')}</Button>
        {canManage && opponent.status === 'active' && <>
          <Button disabled={blocked} onClick={() => { setHistory(null); setEditor(opponent) }}>{text('edit')}</Button>
          <form aria-label={text('archive') + ' ' + opponent.name} onSubmit={(event) => mutation.propose(event, text('archive') + ' ' + opponent.name,
            opponentsPath(org, opponent.id), { version: opponent.version, status: 'archived', confirm_public_data: true }, true)}>
            <Button type="submit" disabled={blocked}>{text('archive')}</Button>
          </form>
        </>}
      </li>)}</ul>}
      {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={blocked} onPageChange={setPage} />}
      {editor && <OpponentForm key={editor === 'new' ? 'new' : editor.id + editor.version} opponent={editor === 'new' ? null : editor}
        games={games.filter((game) => game.enabled)} disabled={blocked} errors={mutation.errors(opponentsPath(org, editor === 'new' ? undefined : editor.id))}
        onCancel={() => setEditor(null)} onSubmit={(event) => {
          const opponent = editor === 'new' ? null : editor
          const body = changedValues(event.currentTarget, opponent, ['observed_at'])
          delete body.confirm_public_data
          // Consent alone is not a change to a reference.
          if (opponent && Object.keys(body).length === 0) {
            mutation.propose(event, text('save'), opponentsPath(org, opponent.id), { version: opponent.version }, true); return
          }
          mutation.propose(event, text('save'), opponentsPath(org, opponent?.id),
            { ...body, ...(opponent ? { version: opponent.version } : {}), confirm_public_data: new FormData(event.currentTarget).has('confirm_public_data') }, !!opponent)
        }} />}
      {history && <OpponentHistory key={history.id + history.version} active={active} opponent={history} disabled={blocked}
        onInvalid={onInvalid} onClose={() => setHistory(null)} />}
      <p className="text-sm text-slate-400 [overflow-wrap:anywhere]">{text('archiveHint')}</p>
    </>}
  </section>
}

function OpponentForm({ opponent, games, disabled, errors, onSubmit, onCancel }: {
  opponent: ExternalOpponent | null; games: OrganizationGame[]; disabled: boolean; errors: Record<string, string[]>
  onSubmit: (event: FormEvent<HTMLFormElement>) => void; onCancel: () => void
}) {
  return <form noValidate aria-label={text('save')} onSubmit={onSubmit} className="grid min-w-0 gap-3 rounded-xl border border-cyan-400/30 p-4 sm:grid-cols-2">
    {opponent ? <p className="sm:col-span-2 [overflow-wrap:anywhere]">{opponent.key} · {text('kind.' + opponent.kind)}. {text('stable')}</p> : <>
      <FormField name="key" label={text('key')} required maxLength={80} disabled={disabled} error={errors.key?.[0]} />
      <SelectField name="kind" title={text('kind')} defaultValue="team" disabled={disabled} error={errors.kind?.[0]}>{opponentKinds.map((kind) => <option key={kind} value={kind}>{text('kind.' + kind)}</option>)}</SelectField>
      <SelectField name="game_id" title={text('game')} defaultValue="" disabled={disabled} error={errors.game_id?.[0]}><option value="">{text('noGame')}</option>{games.map((game) => <option key={game.id} value={game.id}>{game.name}</option>)}</SelectField>
    </>}
    <FormField name="name" label={text('name')} defaultValue={opponent?.name ?? ''} required maxLength={120} disabled={disabled} error={errors.name?.[0]} />
    <FormField name="short_name" label={text('short_name')} defaultValue={opponent?.short_name ?? ''} maxLength={24} disabled={disabled} error={errors.short_name?.[0]} />
    <FormField name="organization_name" label={text('organization_name')} defaultValue={opponent?.organization_name ?? ''} maxLength={120} disabled={disabled} error={errors.organization_name?.[0]} />
    <FormField name="source_label" label={text('source_label')} defaultValue={opponent?.source_label ?? ''} required maxLength={160} disabled={disabled} error={errors.source_label?.[0]} />
    <FormField name="source_url" label={text('source_url')} defaultValue={opponent?.source_url ?? ''} type="url" maxLength={1000} disabled={disabled} error={errors.source_url?.[0]} />
    <FormField name="observed_at" label={text('observed_at')} type="datetime-local" step="1" defaultValue={localDate(opponent?.observed_at)} required disabled={disabled} error={errors.observed_at?.[0]} />
    <PublicConsent disabled={disabled} error={errors.confirm_public_data?.[0]} />
    <div className="flex flex-wrap gap-3 sm:col-span-2"><Button type="submit" disabled={disabled}>{text('save')}</Button><Button disabled={disabled} onClick={onCancel}>{t('tenant.cancel')}</Button></div>
  </form>
}

function OpponentHistory({ active, opponent, disabled, onInvalid, onClose }: Props & { opponent: ExternalOpponent; onClose: () => void }) {
  const org = active.context!.organization_id, revision = active.revision ?? undefined
  const path = opponentsPath(org, opponent.id) + '/snapshots'
  const canManage = active.context!.permissions.includes('teams.manage') && opponent.status === 'active'
  const [page, setPage] = useState(1), [refresh, setRefresh] = useState(0)
  const [list, setList] = useState<Page<OpponentSnapshot> | null>(null), [loading, setLoading] = useState(false)
  const [error, setError] = useState<ReturnType<typeof apiError> | null>(null)
  const mutation = usePeopleMutation<OpponentSnapshot>(active, () => { setPage(1); setRefresh((value) => value + 1) }, onInvalid)
  useEffect(() => {
    const controller = new AbortController()
    const load = async () => {
      setLoading(true); setList(null); setError(null)
      await tenancyRequest<Page<OpponentSnapshot>>(path + '?per_page=10&page=' + page, { revision, signal: controller.signal }).then((next) => {
        if (!controller.signal.aborted) setList(next)
      }).catch((failure: unknown) => {
        if (controller.signal.aborted || isAbortError(failure)) return
        const next = apiError(failure); setError(next); if (shouldReloadContext(next)) onInvalid()
      }).finally(() => { if (!controller.signal.aborted) setLoading(false) })
    }
    void load()
    return () => controller.abort()
  }, [path, revision, page, refresh, onInvalid])
  const blocked = disabled || loading || mutation.blocked
  const errors = mutation.errors(path)
  return <section aria-label={text('history')} className="grid min-w-0 gap-3 rounded-xl border border-slate-700 p-4">
    <h3 className="font-bold [overflow-wrap:anywhere]">{text('history')} · {opponent.name}</h3>
    <Button disabled={disabled || mutation.blocked} onClick={onClose}>{text('closeHistory')}</Button>
    {loading && <p role="status">{text('loading')}</p>}
    {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
    {mutation.feedback}
    <p className="text-sm text-slate-400 [overflow-wrap:anywhere]">{text('snapshotHint')}</p>
    {canManage && <form noValidate aria-label={text('capture')} className="grid min-w-0 gap-3" onSubmit={(event) => {
      const values = new FormData(event.currentTarget)
      mutation.propose(event, text('capture') + ' ' + opponent.name, path, { version: opponent.version, context_label: String(values.get('context_label') ?? ''),
        confirm_public_data: values.has('confirm_public_data') })
    }}>
      <FormField name="context_label" label={text('context_label')} required maxLength={120} disabled={blocked} error={errors.context_label?.[0]} />
      <PublicConsent disabled={blocked} error={errors.confirm_public_data?.[0]} />
      <Button type="submit" disabled={blocked}>{text('capture')}</Button>
    </form>}
    {list?.data.length === 0 && <p>{text('noSnapshots')}</p>}
    {list && <ul className="grid min-w-0 gap-3">{list.data.map((snapshot) => <li key={snapshot.id} className="grid min-w-0 gap-2 rounded-xl border border-slate-700 p-3">
      <h4 className="font-bold [overflow-wrap:anywhere]">{snapshot.context_label} · {snapshot.name}</h4>
      <p className="[overflow-wrap:anywhere]">{text('kind.' + snapshot.kind)}{snapshot.game_name && ' · ' + snapshot.game_name}</p>
      {snapshot.organization_name && <p className="[overflow-wrap:anywhere]">{snapshot.organization_name}</p>}
      {snapshot.short_name && <p className="[overflow-wrap:anywhere]">{snapshot.short_name}</p>}
      <Source data={snapshot} />
      <p className="text-sm text-slate-400 [overflow-wrap:anywhere]">{text('captured_at')} : {formatDate(snapshot.captured_at)}</p>
    </li>)}</ul>}
    {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={blocked} onPageChange={setPage} />}
  </section>
}

function PublicConsent({ disabled, error }: { disabled: boolean; error?: string }) {
  const id = useId()
  return <div className="grid min-w-0 gap-2 sm:col-span-2">
    <label className="flex min-w-0 items-start gap-2"><input name="confirm_public_data" type="checkbox" disabled={disabled} aria-invalid={error ? true : undefined} aria-describedby={error ? id : undefined} className="mt-1 shrink-0" /><span className="[overflow-wrap:anywhere]">{text('confirm_public_data')}</span></label>
    {error && <p id={id} role="alert" className="text-sm text-rose-200">{error}</p>}
  </div>
}
function Source({ data }: { data: Pick<ExternalOpponent, 'source_label' | 'source_url' | 'observed_at'> }) {
  return <>
    <p className="[overflow-wrap:anywhere]">{text('source_label')} : {data.source_label}</p>
    {data.source_url && <p className="text-sm text-slate-400 [overflow-wrap:anywhere]">{data.source_url}</p>}
    <p className="text-sm text-slate-400 [overflow-wrap:anywhere]">{text('observed_at')} : {formatDate(data.observed_at)}</p>
  </>
}
function formatDate(value: string) {
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat('fr', { dateStyle: 'short', timeStyle: 'short' }).format(date)
}
