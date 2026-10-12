import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react'
import { getPerson, listPeople, type PeopleFilters, type PeopleView, type Person } from '../../api/people'
import { getGameProfileCatalogue, type GameProfileCatalogue } from '../../api/gameProfiles'
import type { ActiveOrganization, Page } from '../../api/tenancy'
import { isAbortError } from '../../api/errors'
import { t } from '../../i18n/fr'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { DataTable, type DataTableColumn } from '../data/DataTable'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { AsyncState } from '../feedback/AsyncState'
import { usePeopleMutation } from './usePeopleMutation'
import { PersonField, SelectField } from './peopleForms'
import { apiError, changedValues, label, personAdminFields, personSportsFields, personStatuses, shouldReloadContext } from './peopleFormValues'
import { GameProfilesPanel } from './GameProfilesPanel'

export function PeoplePanel({ active, disabled: parentDisabled, onInvalid }: {
  active: ActiveOrganization; disabled: boolean; onInvalid: () => void
}) {
  const context = active.context
  const organizationId = context?.organization_id
  const revision = active.revision ?? undefined
  const sports = Boolean(context?.permissions.includes('sports.read'))
  const administrative = Boolean(context?.permissions.includes('administrative.read'))
  const sportsWrite = Boolean(context?.permissions.includes('sports.write'))
  const administrativeWrite = Boolean(context?.permissions.includes('administrative.write'))
  const canRead = sports || administrative
  const [filters, setFilters] = useState<PeopleFilters>({ view: 'general', sort: 'id', direction: 'asc' })
  const [page, setPage] = useState(1)
  const [data, setData] = useState<Page<Person> | null>(null)
  const [catalogue, setCatalogue] = useState<GameProfileCatalogue['data'] | null>(null)
  const [person, setPerson] = useState<Person | null>(null)
  const [editing, setEditing] = useState<Person | null | undefined>(undefined)
  const [view, setView] = useState<PeopleView>('general')
  const [loading, setLoading] = useState(canRead)
  const [error, setError] = useState<ReturnType<typeof apiError> | null>(null)
  const [refresh, setRefresh] = useState(0)
  const read = useRef<AbortController | null>(null)
  const generation = useRef(0)
  const alive = useRef(true)
  const details = useRef<HTMLElement>(null)
  const onSaved = () => { setEditing(undefined); setPerson(null); setRefresh((value) => value + 1) }
  const mutation = usePeopleMutation(active, onSaved, onInvalid)

  const load = useCallback(async () => {
    if (!organizationId || !canRead) return
    const current = ++generation.current
    read.current?.abort()
    const controller = new AbortController()
    read.current = controller
    setLoading(true); setData(null); setPerson(null); setEditing(undefined); setError(null)
    try {
      const [people, references] = await Promise.all([
        listPeople(organizationId, filters, { page, perPage: 10, revision, signal: controller.signal }),
        sports ? getGameProfileCatalogue(organizationId, revision, controller.signal) : null,
      ])
      if (!alive.current || current !== generation.current || controller.signal.aborted) return
      setData(people); setCatalogue(references?.data ?? null)
    } catch (failure) {
      if (!alive.current || current !== generation.current || controller.signal.aborted || isAbortError(failure)) return
      const next = apiError(failure); setError(next)
      if (shouldReloadContext(next)) onInvalid()
    } finally { if (alive.current && current === generation.current && !controller.signal.aborted) setLoading(false) }
  }, [organizationId, canRead, sports, filters, page, revision, onInvalid])

  const cancelReads = useCallback(() => { generation.current++; read.current?.abort() }, [])

  useEffect(() => {
    let disposed = false
    alive.current = true
    void Promise.resolve().then(() => { if (!disposed) void load() })
    return () => { disposed = true; alive.current = false; cancelReads() }
  }, [load, refresh, cancelReads])
  useEffect(() => { if (person || editing !== undefined) details.current?.focus() }, [person, editing])

  async function open(id: string) {
    if (!organizationId) return
    const current = ++generation.current
    read.current?.abort()
    const controller = new AbortController(); read.current = controller
    setPerson(null); setEditing(undefined); setError(null); setLoading(true)
    try {
      const result = await getPerson(organizationId, id, revision, controller.signal)
      if (alive.current && current === generation.current && !controller.signal.aborted) { setPerson(result.data); setView('general') }
    } catch (failure) {
      if (alive.current && current === generation.current && !controller.signal.aborted && !isAbortError(failure)) {
        const next = apiError(failure); setError(next)
        if (shouldReloadContext(next)) onInvalid()
      }
    } finally { if (alive.current && current === generation.current && !controller.signal.aborted) setLoading(false) }
  }
  if (!context || (!canRead && !sportsWrite && !administrativeWrite)) return null
  const disabled = parentDisabled || loading || mutation.blocked
  const visibleSports = sports && filters.view !== 'administrative'
  const visibleAdmin = administrative && filters.view !== 'sports'
  const columns: DataTableColumn<Person>[] = [
    ...(visibleSports ? [
      { key: 'pseudonym', header: label('pseudonym'), render: (row: Person) => row.pseudonym },
      { key: 'status', header: label('status'), render: (row: Person) => row.status ? label('status.' + row.status) : label('none') },
      { key: 'profiles', header: label('profileCount'), render: (row: Person) => row.game_profiles_count ?? 0 },
    ] : []),
    ...(visibleAdmin ? [
      { key: 'name', header: label('last_name'), render: (row: Person) => [row.first_name, row.last_name].filter(Boolean).join(' ') || label('none') },
      { key: 'email', header: label('contact_email'), render: (row: Person) => row.contact_email || label('none') },
    ] : []),
    { key: 'open', header: label('id'), render: (row) => <Button disabled={disabled} onClick={() => void open(row.id)}>{t('people.open', { name: row.pseudonym ?? row.last_name ?? row.id })}</Button> },
  ]
  function apply(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const values = Object.fromEntries(new FormData(event.currentTarget))
    const next = Object.fromEntries(Object.entries(values).filter(([, value]) => value !== '')) as PeopleFilters
    setPage(1); setFilters(next)
  }
  const fields = [...(sports && view !== 'administrative' ? personSportsFields : []), ...(administrative && view !== 'sports' ? personAdminFields : [])]
  const path = 'tenants/' + organizationId + '/people' + (editing ? '/' + editing.id : '')
  const editorFields = [
    ...((editing === null ? sportsWrite : sports && sportsWrite) ? personSportsFields : []),
    ...((editing === null ? administrativeWrite : administrative && administrativeWrite) ? personAdminFields : []),
  ]

  return <section aria-labelledby={'people-' + organizationId} aria-busy={loading} className="grid min-w-0 grid-cols-1 gap-4 rounded-2xl border border-white/10 p-5">
    <div className="flex flex-wrap items-center justify-between gap-3">
      <h3 id={'people-' + organizationId} className="text-xl font-bold">{t('people.title')}</h3>
      {canRead && <Button disabled={disabled} onClick={() => setRefresh((value) => value + 1)}>{t('people.refresh')}</Button>}
      {sportsWrite && <Button disabled={disabled} onClick={() => { setPerson(null); setEditing(null) }}>{t('people.create')}</Button>}
    </div>
    {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
    {mutation.feedback}
    {!canRead && <p>{t('people.noRead')}</p>}
    {canRead && <form key={JSON.stringify(filters)} noValidate aria-label={t('people.apply')} onSubmit={apply} className="grid min-w-0 grid-cols-1 gap-3 md:grid-cols-2">
      <SelectField name="view" title={label('view')} defaultValue={filters.view ?? 'general'} disabled={disabled}>
        <option value="general">{label('general')}</option>{sports && <option value="sports">{label('sports')}</option>}{administrative && <option value="administrative">{label('administrative')}</option>}
      </SelectField>
      {sports && <>
        <FormField name="q" label={label('search')} hint={label('searchHint')} defaultValue={filters.q ?? ''} error={error?.fieldErrors.q?.[0]} disabled={disabled} maxLength={80} />
        <SelectField name="status" title={label('status')} defaultValue={filters.status ?? ''} disabled={disabled}><option value="">{label('all')}</option>{personStatuses.map((status) => <option key={status} value={status}>{label('status.' + status)}</option>)}</SelectField>
        <FormField name="language" label={label('language')} defaultValue={filters.language ?? ''} disabled={disabled} error={error?.fieldErrors.language?.[0]} />
        <SelectField name="game_key" title={label('game')} defaultValue={filters.game_key ?? ''} disabled={disabled}><option value="">{label('all')}</option>{catalogue?.games.map((game) => <option key={game.key} value={game.key}>{game.name}</option>)}</SelectField>
        <SelectField name="platform_key" title={label('platform')} defaultValue={filters.platform_key ?? ''} disabled={disabled}><option value="">{label('all')}</option>{Array.from(new Map(catalogue?.games.flatMap((game) => game.platforms.map((platform) => [platform.key, platform] as const))).values()).map((platform) => <option key={platform.key} value={platform.key}>{platform.name}</option>)}</SelectField>
      </>}
      {administrative && <SelectField name="account_linked" title={label('accountFilter')} defaultValue={filters.account_linked ?? ''} disabled={disabled}><option value="">{label('all')}</option><option value="linked">{label('linked')}</option><option value="unlinked">{label('unlinked')}</option></SelectField>}
      <SelectField name="sort" title={label('sort')} defaultValue={filters.sort ?? 'id'} disabled={disabled}><option value="id">{label('id')}</option>{sports && <><option value="pseudonym">{label('pseudonym')}</option><option value="status">{label('status')}</option></>}</SelectField>
      <SelectField name="direction" title={label('direction')} defaultValue={filters.direction ?? 'asc'} disabled={disabled}><option value="asc">{label('asc')}</option><option value="desc">{label('desc')}</option></SelectField>
      <div className="flex flex-wrap gap-3 md:col-span-2"><Button type="submit" disabled={disabled}>{label('apply')}</Button><Button disabled={disabled} onClick={() => { setPage(1); setFilters({ view: 'general', sort: 'id', direction: 'asc' }) }}>{label('reset')}</Button></div>
    </form>}
    {loading && <AsyncState variant="loading" title={label('loading')} />}
    {data && <><DataTable caption={label('title')} columns={columns} rows={data.data} rowKey={(row) => row.id} emptyTitle={label('empty')} emptyDescription={label('emptyHint')} />
      <Pagination currentPage={data.meta.current_page} totalPages={data.meta.last_page} disabled={disabled} onPageChange={setPage} /></>}
    {(person || editing !== undefined) && <section ref={details} tabIndex={-1} aria-label={label('edit')} className="grid min-w-0 gap-4 rounded-xl border border-cyan-400/30 p-4">
      <div className="flex flex-wrap gap-3"><Button disabled={disabled} onClick={() => { setPerson(null); setEditing(undefined) }}>{label('close')}</Button>
        {person && ((sports && sportsWrite) || (administrative && administrativeWrite)) && <Button disabled={disabled} onClick={() => setEditing(person)}>{label('edit')}</Button>}
      </div>
      {editing !== undefined ? <form key={editing?.version ?? 'new'} noValidate aria-label={label('save')} className="grid min-w-0 grid-cols-1 gap-3 md:grid-cols-2" onSubmit={(event) => {
        const values = changedValues(event.currentTarget, editing)
        mutation.propose(event, label('save') + ' — ' + (editing?.pseudonym ?? values.pseudonym ?? editing?.id ?? ''), path, { ...values, ...(editing ? { version: editing.version } : {}) }, editing !== null)
      }}>
        {editorFields.map((name) => <PersonField key={name} name={name} value={editing?.[name]} language={context.language} timezone={context.timezone} disabled={disabled} error={mutation.errors(path)[name]?.[0]} />)}
        <p className="text-sm text-slate-400 md:col-span-2">{label('statusHint')} {editorFields.includes('user_id') && label('linkHint')}</p>
        <div className="flex flex-wrap gap-3"><Button type="submit" disabled={disabled}>{label('save')}</Button><Button disabled={disabled} onClick={() => setEditing(undefined)}>{t('tenant.cancel')}</Button></div>
      </form> : person && <>
        <SelectField name="detail_view" title={label('view')} value={view} onChange={(value) => setView(value as PeopleView)} disabled={disabled}>
          <option value="general">{label('general')}</option>{sports && <option value="sports">{label('sports')}</option>}{administrative && <option value="administrative">{label('administrative')}</option>}
        </SelectField>
        <dl className="grid min-w-0 grid-cols-1 gap-3 md:grid-cols-2">{fields.filter((name) => Object.hasOwn(person, name)).map((name) => <div key={name} className="min-w-0"><dt className="text-sm text-slate-400">{label(name)}</dt><dd className="whitespace-pre-wrap break-words">{name === 'status' ? label('status.' + person.status) : String(person[name] ?? label('none'))}</dd></div>)}</dl>
        {sports && view !== 'administrative' && <GameProfilesPanel key={person.id} active={active} personId={person.id} catalogue={catalogue} disabled={disabled} onInvalid={onInvalid} />}
      </>}
    </section>}
  </section>
}
