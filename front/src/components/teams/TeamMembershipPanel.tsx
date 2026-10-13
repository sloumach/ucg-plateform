import { useEffect, useState, type FormEvent } from 'react'
import { tenancyRequest, type ActiveOrganization, type Page } from '../../api/tenancy'
import { listPeople, type Person } from '../../api/people'
import { isAbortError } from '../../api/errors'
import { teamsPath, type Team } from '../../api/teams'
import { rosterText as text, sportingRoles, type TeamMembership, type TeamMovement } from '../../api/teamMemberships'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { SelectField } from '../people/peopleForms'
import { apiError, isoDate, localDate, shouldReloadContext } from '../people/peopleFormValues'
import { usePeopleMutation } from '../people/usePeopleMutation'
type Action = 'close' | 'cancel' | 'transfer' | 'promotion'
type Editor = 'new' | { period: TeamMembership; action: Action } | null
export function TeamMembershipPanel({ active, team, disabled, onInvalid, onClose }: {
  active: ActiveOrganization; team: Team; disabled: boolean; onInvalid: () => void; onClose: () => void
}) {
  const org = active.context?.organization_id, revision = active.revision ?? undefined
  const allowed = active.context?.permissions.includes('sports.read') ?? false
  const manage = allowed && team.status !== 'archived' && (active.context?.permissions.includes('teams.manage') ?? false)
  const path = org ? teamsPath(org, team.id) : ''
  const [page, setPage] = useState(1), [eventPage, setEventPage] = useState(1), [refresh, setRefresh] = useState(0)
  const [filters, setFilters] = useState<Record<string, string>>({}), [editor, setEditor] = useState<Editor>(null)
  const [list, setList] = useState<Page<TeamMembership> | null>(null), [events, setEvents] = useState<Page<TeamMovement> | null>(null)
  const [loading, setLoading] = useState(false), [error, setError] = useState<ReturnType<typeof apiError> | null>(null)
  const [clock, setClock] = useState(() => Date.now())
  const mutation = usePeopleMutation<TeamMembership>(active, () => { setEditor(null); setRefresh((value) => value + 1) }, onInvalid)
  useEffect(() => {
    if (!org || !allowed) return
    const controller = new AbortController()
    const load = async () => {
      setLoading(true); setList(null); setEvents(null); setEditor(null); setError(null)
      try {
        const [members, history] = await Promise.all([
          tenancyRequest<Page<TeamMembership>>(path + '/memberships?' + new URLSearchParams({ per_page: '10', page: String(page), ...filters }), { revision, signal: controller.signal }),
          tenancyRequest<Page<TeamMovement>>(path + '/movements?per_page=10&page=' + eventPage, { revision, signal: controller.signal }),
        ])
        if (!controller.signal.aborted) { setList(members); setEvents(history); setClock(Date.now()) }
      } catch (failure) {
        if (controller.signal.aborted || isAbortError(failure)) return
        const next = apiError(failure); setError(next); if (shouldReloadContext(next)) onInvalid()
      } finally { if (!controller.signal.aborted) setLoading(false) }
    }
    void load(); return () => controller.abort()
  }, [org, allowed, revision, path, page, eventPage, filters, refresh, onInvalid])
  if (!org || !allowed) return null
  const blocked = disabled || loading || mutation.blocked
  function search(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const entries = Object.fromEntries(new FormData(event.currentTarget))
    setFilters(Object.fromEntries(Object.entries(entries).filter(([, value]) => value !== '').map(([key, value]) => [key, key === 'at' ? isoDate(String(value)) ?? '' : String(value)])))
    setPage(1)
  }
  return <section aria-label={text('title') + ' ' + team.name} className="grid min-w-0 grid-cols-1 gap-4 rounded-xl border border-cyan-400/30 p-4">
    <h3 className="font-bold [overflow-wrap:anywhere]">{text('title')} · {team.name}</h3>
    <p>{text('rules')}</p><p className="text-sm text-slate-400">{text('rightsHint')}</p>
    <Button disabled={mutation.blocked} onClick={onClose}>{text('closePanel')}</Button>
    {loading && <p role="status">{text('loading')}</p>}
    {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}{mutation.feedback}
    <form aria-label={text('filter')} onSubmit={search} className="grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-2">
      <SelectField name="role_key" title={text('role')} disabled={blocked}><option value="">{text('all')}</option>{sportingRoles.map((role) => <option key={role} value={role}>{text('role.' + role)}</option>)}</SelectField>
      <FormField name="at" label={text('at')} type="datetime-local" step="1" disabled={blocked} />
      <Button type="submit" disabled={blocked}>{text('filter')}</Button>
    </form>
    {manage && <Button disabled={blocked} onClick={() => setEditor('new')}>{text('add')}</Button>}
    {!manage && <p>{text('readOnly')}</p>}
    {list?.data.length === 0 && <p>{text('empty')}</p>}
    {list && <ul className="grid min-w-0 grid-cols-1 gap-3">{list.data.map((period) => {
      const future = new Date(period.starts_at).getTime() > clock
      const editable = period.status === 'active' && (!period.ends_at || new Date(period.ends_at).getTime() > clock)
      return <li key={period.id} className="grid min-w-0 grid-cols-1 gap-2 rounded-xl border border-slate-700 p-3">
        <h4 className="font-bold [overflow-wrap:anywhere]">{period.pseudonym ?? period.person_id} · {text('role.' + period.role_key)}</h4>
        <p className="[overflow-wrap:anywhere]">{new Date(period.starts_at).toLocaleString('fr')} → {period.ends_at ? new Date(period.ends_at).toLocaleString('fr') : text('noEnd')}</p>
        <p>{text('status.' + period.status)} · {period.effective ? text('effective') : text('notEffective')}</p>
        {manage && editable && <div className="flex flex-wrap gap-2">
          <Button disabled={blocked} onClick={() => setEditor({ period, action: future ? 'cancel' : 'close' })}>{text(future ? 'cancel' : 'close')}</Button>
          {!future && <Button disabled={blocked} onClick={() => setEditor({ period, action: 'transfer' })}>{text('transfer')}</Button>}
          {!future && team.kind === 'academy' && <Button disabled={blocked} onClick={() => setEditor({ period, action: 'promotion' })}>{text('promotion')}</Button>}
        </div>}
      </li>
    })}</ul>}
    {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={blocked} onPageChange={setPage} />}
    {editor && manage && <MembershipForm key={editor === 'new' ? 'new' : editor.period.id + editor.action} editor={editor} team={team} active={active} disabled={blocked}
      onInvalid={onInvalid} errors={mutation.errors(path + '/memberships' + (editor === 'new' ? '' : '/' + editor.period.id))} onCancel={() => setEditor(null)}
      onSubmit={(event, body) => mutation.propose(event, text(editor === 'new' ? 'add' : editor.action), path + '/memberships' + (editor === 'new' ? '' : '/' + editor.period.id), body, editor !== 'new')} />}
    <h4 className="font-bold">{text('history')}</h4>
    {events?.data.length === 0 && <p>{text('noHistory')}</p>}
    {events && <ul className="grid min-w-0 grid-cols-1 gap-2">{events.data.map((movement) => <li className="text-sm [overflow-wrap:anywhere]" key={movement.id}>
      {text('action.' + movement.action)} · {text('reason.' + movement.reason_key)} · {new Date(movement.effective_at).toLocaleString('fr')} · {text('author')} {movement.actor_user_id}
      <span> · {movement.team_name}{movement.destination_team_name && ' → ' + movement.destination_team_name}</span>
    </li>)}</ul>}
    {events && <Pagination currentPage={events.meta.current_page} totalPages={events.meta.last_page} disabled={blocked} onPageChange={setEventPage} />}
  </section>
}
function MembershipForm({ editor, team, active, disabled, errors, onSubmit, onCancel, onInvalid }: {
  editor: Exclude<Editor, null>; team: Team; active: ActiveOrganization; disabled: boolean; errors: Record<string, string[]>
  onSubmit: (event: FormEvent<HTMLFormElement>, body: Record<string, unknown>) => void; onCancel: () => void; onInvalid: () => void
}) {
  const creating = editor === 'new', moving = !creating && ['transfer', 'promotion'].includes(editor.action)
  const org = active.context!.organization_id, revision = active.revision ?? undefined
  const [choices, setChoices] = useState<Page<Person | Team> | null>(null), [query, setQuery] = useState(''), [page, setPage] = useState(1)
  const [selected, setSelected] = useState(''), [loading, setLoading] = useState(false), [error, setError] = useState<ReturnType<typeof apiError> | null>(null)
  const [initialDate] = useState(() => localDate(new Date().toISOString()))
  useEffect(() => {
    if (!creating && !moving) return
    const controller = new AbortController()
    const load = async () => {
      setLoading(true); setChoices(null); setSelected(''); setError(null)
      try {
        const result = creating ? await listPeople(org, { view: 'sports', ...(query ? { q: query } : {}) }, { perPage: 10, page, revision, signal: controller.signal })
          : await tenancyRequest<Page<Team>>(teamsPath(org) + '?' + new URLSearchParams({ per_page: '10', page: String(page), game_id: team.game_id,
            ...(query ? { q: query } : {}), ...(!creating && editor.action === 'promotion' ? { kind: 'first' } : {}) }), { revision, signal: controller.signal })
        if (!controller.signal.aborted) setChoices(result)
      } catch (failure) {
        if (controller.signal.aborted || isAbortError(failure)) return
        const next = apiError(failure); setError(next); if (shouldReloadContext(next)) onInvalid()
      } finally { if (!controller.signal.aborted) setLoading(false) }
    }
    void load(); return () => controller.abort()
  }, [org, revision, creating, moving, editor, team.game_id, query, page, onInvalid])
  const blocked = disabled || loading
  const options = choices?.data.filter((choice) => choice.status !== 'former' && choice.status !== 'archived' && (creating || choice.id !== team.id)) ?? []
  return <div className="grid min-w-0 grid-cols-1 gap-3">
    {(creating || moving) && <form aria-label={text('lookup')} onSubmit={(event) => { event.preventDefault(); setQuery(String(new FormData(event.currentTarget).get('q') ?? '')); setPage(1) }} className="flex min-w-0 flex-wrap items-end gap-3">
      <FormField name="q" label={text('prefix')} maxLength={80} disabled={disabled} /><Button type="submit" disabled={blocked}>{text('lookup')}</Button>
    </form>}
    {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
    {choices && <Pagination currentPage={choices.meta.current_page} totalPages={choices.meta.last_page} disabled={blocked} onPageChange={setPage} />}
    <form aria-label={text('save')} className="grid min-w-0 grid-cols-1 gap-3 rounded-xl border border-cyan-400/30 p-4 sm:grid-cols-2" onSubmit={(event) => {
      event.preventDefault()
      const fields = Object.fromEntries(new FormData(event.currentTarget)), body: Record<string, unknown> = { reason_key: fields.reason_key }
      if (creating) Object.assign(body, { person_id: selected, role_key: fields.role_key, starts_at: isoDate(String(fields.starts_at)), ends_at: isoDate(String(fields.ends_at ?? '')) })
      else {
        Object.assign(body, { version: editor.period.version, action: editor.action })
        if (fields.effective_at) body.effective_at = isoDate(String(fields.effective_at))
        if (moving) {
          const destination = options.find((choice) => choice.id === selected)
          Object.assign(body, { destination_team_id: selected, destination_version: destination?.version, role_key: fields.role_key })
        }
      }
      onSubmit(event, body)
    }}>
      {!creating && <p className="sm:col-span-2 [overflow-wrap:anywhere]">{editor.period.pseudonym ?? editor.period.person_id} · {text(editor.action)}</p>}
      {(creating || moving) && <SelectField name={creating ? 'person_id' : 'destination_team_id'} title={text(creating ? 'person' : 'target')} value={selected} onChange={setSelected} disabled={blocked} error={errors[creating ? 'person_id' : 'destination_team_id']?.[0]}>
        <option value="">{loading ? text('loading') : text('choose')}</option>{options.map((choice) => <option key={choice.id} value={choice.id}>{'name' in choice ? choice.name + ' · ' + choice.season : choice.pseudonym}</option>)}
      </SelectField>}
      {(creating || moving) && <SelectField name="role_key" title={text('role')} defaultValue={creating ? 'player' : editor.period.role_key} disabled={blocked} error={errors.role_key?.[0]}>{sportingRoles.map((role) => <option key={role} value={role}>{text('role.' + role)}</option>)}</SelectField>}
      {creating ? <>
        <FormField name="starts_at" label={text('starts_at')} type="datetime-local" step="1" defaultValue={initialDate} required disabled={blocked} error={errors.starts_at?.[0]} />
        <FormField name="ends_at" label={text('ends_at')} type="datetime-local" step="1" disabled={blocked} error={errors.ends_at?.[0]} />
      </> : editor.action !== 'cancel' && <FormField name="effective_at" label={text('effective_at')} type="datetime-local" step="1" defaultValue={editor.action === 'close' ? initialDate : ''}
        required={editor.action === 'close'} hint={moving ? text('immediate') : undefined} disabled={blocked} error={errors.effective_at?.[0]} />}
      <SelectField name="reason_key" title={text('reason')} defaultValue={creating ? 'initial' : editor.action === 'close' ? 'end_of_period' : editor.action === 'cancel' ? 'other' : editor.action} disabled={blocked} error={errors.reason_key?.[0]}>
        {(creating ? ['initial', 'sporting_decision', 'other'] : ['sporting_decision', 'promotion', 'transfer', 'end_of_period', 'other']).map((reason) => <option key={reason} value={reason}>{text('reason.' + reason)}</option>)}
      </SelectField>
      {moving && <p className="sm:col-span-2">{text('moveHint')}</p>}
      <div className="flex flex-wrap gap-3 sm:col-span-2"><Button type="submit" disabled={blocked || ((creating || moving) && !selected)}>{text('save')}</Button><Button disabled={disabled} onClick={onCancel}>{text('back')}</Button></div>
    </form>
  </div>
}
