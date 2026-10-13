import { useEffect, useState, type FormEvent } from 'react'
import { tenancyRequest, type ActiveOrganization, type Page } from '../../api/tenancy'
import { isAbortError } from '../../api/errors'
import { teamsPath, type Team } from '../../api/teams'
import { MAX_LINEUP_ENTRIES, lineupText as text, type LineupChoice, type TeamLineup } from '../../api/teamLineups'
import type { TeamMembership } from '../../api/teamMemberships'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { SelectField } from '../people/peopleForms'
import { apiError, isoDate, localDate, shouldReloadContext } from '../people/peopleFormValues'
import { usePeopleMutation } from '../people/usePeopleMutation'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'

export function TeamLineupPanel({ active, team, disabled, onInvalid, onClose }: {
  active: ActiveOrganization; team: Team; disabled: boolean; onInvalid: () => void; onClose: () => void
}) {
  const org = active.context?.organization_id, revision = active.revision ?? undefined
  const allowed = active.context?.permissions.includes('sports.read') ?? false
  const manage = allowed && (active.context?.permissions.includes('teams.manage') ?? false)
  const edit = manage && team.status !== 'archived'
  const path = org ? teamsPath(org, team.id) + '/lineups' : ''
  const [list, setList] = useState<Page<TeamLineup> | null>(null), [page, setPage] = useState(1), [refresh, setRefresh] = useState(0)
  const [filters, setFilters] = useState<Record<string, string>>({}), [editor, setEditor] = useState<TeamLineup | 'new' | null>(null)
  const [loading, setLoading] = useState(false), [error, setError] = useState<ReturnType<typeof apiError> | null>(null)
  const mutation = usePeopleMutation<TeamLineup>(active, () => { setEditor(null); setRefresh((value) => value + 1) }, onInvalid)
  useEffect(() => {
    if (!org || !allowed) return
    const controller = new AbortController()
    const load = async () => {
      setLoading(true); setList(null); setEditor(null); setError(null)
      try {
        const result = await tenancyRequest<Page<TeamLineup>>(path + '?' + new URLSearchParams({ per_page: '10', page: String(page), ...filters }), { revision, signal: controller.signal })
        if (!controller.signal.aborted) setList(result)
      } catch (failure) {
        if (controller.signal.aborted || isAbortError(failure)) return
        const next = apiError(failure); setError(next); if (shouldReloadContext(next)) onInvalid()
      } finally { if (!controller.signal.aborted) setLoading(false) }
    }
    void load(); return () => controller.abort()
  }, [org, allowed, revision, path, page, filters, refresh, onInvalid])
  if (!org || !allowed) return null
  const blocked = disabled || loading || mutation.blocked
  return <section aria-label={text('title') + ' ' + team.name} className="grid min-w-0 grid-cols-1 gap-4 rounded-xl border border-cyan-400/30 p-4">
    <h3 className="font-bold [overflow-wrap:anywhere]">{text('title')} · {team.name}</h3>
    <p>{text('hint')}</p><p className="text-sm text-slate-400">{text('referenceHint')}</p>
    <Button disabled={mutation.blocked} onClick={onClose}>{text('close')}</Button>
    {loading && <p role="status">{text('loading')}</p>}
    {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}{mutation.feedback}
    <form aria-label={text('filter')} className="grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-2" onSubmit={(event) => {
      event.preventDefault(); setFilters(Object.fromEntries([...new FormData(event.currentTarget)].filter(([, value]) => value !== '').map(([key, value]) => [key, String(value)]))); setPage(1)
    }}>
      <SelectField name="status" title={text('status')} disabled={blocked}><option value="">{text('all')}</option>{['draft', 'finalized', 'cancelled'].map((status) => <option key={status} value={status}>{text('status.' + status)}</option>)}</SelectField>
      <SelectField name="purpose" title={text('purpose')} disabled={blocked}><option value="">{text('all')}</option>{['match', 'registration'].map((purpose) => <option key={purpose} value={purpose}>{text('purpose.' + purpose)}</option>)}</SelectField>
      <Button type="submit" disabled={blocked}>{text('filter')}</Button>
    </form>
    {edit && <Button disabled={blocked} onClick={() => setEditor('new')}>{text('create')}</Button>}
    {!manage && <p>{text('readOnly')}</p>}
    {list?.data.length === 0 && <p>{text('empty')}</p>}
    {list && <ul className="grid min-w-0 grid-cols-1 gap-3">{list.data.map((lineup) => <li key={lineup.id} className="grid min-w-0 grid-cols-1 gap-3 rounded-xl border border-slate-700 p-3">
      <h4 className="font-bold [overflow-wrap:anywhere]">{lineup.label}</h4>
      <p>{text('purpose.' + lineup.purpose)} · {text('status.' + lineup.status)} · {new Date(lineup.effective_at).toLocaleString('fr')}</p>
      <p className="[overflow-wrap:anywhere]">{lineup.team_name} · {lineup.game_name} · {lineup.season}</p>
      <ol className="grid min-w-0 grid-cols-1 gap-2">{lineup.entries.map((entry) => <li className="[overflow-wrap:anywhere]" key={entry.id}>{entry.position}. {entry.pseudonym} · {text('slot.' + entry.slot_key)}</li>)}</ol>
      {lineup.finalized_at && <p className="text-sm">{text('finalizedBy')} {lineup.finalized_by} · {new Date(lineup.finalized_at).toLocaleString('fr')}</p>}
      {manage && lineup.status === 'draft' && <>
        {edit && <Button disabled={blocked} onClick={() => setEditor(lineup)}>{text('edit')}</Button>}
        {edit && <form aria-label={text('finalize') + ' ' + lineup.label} onSubmit={(event) => mutation.propose(event, text('finalize') + ' ' + lineup.label, path + '/' + lineup.id, { version: lineup.version, action: 'finalize', team_version: team.version }, true)}>
          <p className="text-sm text-amber-200">{text('finalizeHint')}</p><Button type="submit" disabled={blocked}>{text('finalize')}</Button>
        </form>}
        <form aria-label={text('cancel') + ' ' + lineup.label} onSubmit={(event) => mutation.propose(event, text('cancel') + ' ' + lineup.label, path + '/' + lineup.id, { version: lineup.version, action: 'cancel' }, true)}>
          <Button type="submit" disabled={blocked}>{text('cancel')}</Button>
        </form>
      </>}
    </li>)}</ul>}
    {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={blocked} onPageChange={setPage} />}
    {editor && edit && <LineupForm key={editor === 'new' ? 'new' : editor.id + editor.version} active={active} team={team} lineup={editor === 'new' ? null : editor} disabled={blocked}
      onInvalid={onInvalid} onCancel={() => setEditor(null)} errors={mutation.errors(path + (editor === 'new' ? '' : '/' + editor.id))}
      onSubmit={(event, body) => mutation.propose(event, text('save'), path + (editor === 'new' ? '' : '/' + editor.id), body, editor !== 'new')} />}
  </section>
}

function LineupForm({ active, team, lineup, disabled, errors, onInvalid, onSubmit, onCancel }: {
  active: ActiveOrganization; team: Team; lineup: TeamLineup | null; disabled: boolean; errors: Record<string, string[]>
  onInvalid: () => void; onSubmit: (event: FormEvent<HTMLFormElement>, body: Record<string, unknown>) => void; onCancel: () => void
}) {
  const [date, setDate] = useState(() => localDate(lineup?.effective_at ?? new Date().toISOString()))
  const [selected, setSelected] = useState<LineupChoice[]>(() => lineup?.entries ?? [])
  const [page, setPage] = useState(1), [choices, setChoices] = useState<Page<TeamMembership> | null>(null)
  const [loading, setLoading] = useState(false), [error, setError] = useState<ReturnType<typeof apiError> | null>(null)
  const org = active.context!.organization_id, revision = active.revision ?? undefined
  const at = isoDate(date), validDate = Boolean(date) && !Number.isNaN(new Date(date).getTime())
  useEffect(() => {
    if (!validDate || !at) return
    const controller = new AbortController()
    const load = async () => {
      setLoading(true); setChoices(null); setError(null)
      try {
        const result = await tenancyRequest<Page<TeamMembership>>(teamsPath(org, team.id) + '/memberships?' + new URLSearchParams({ per_page: '10', page: String(page), at }), { revision, signal: controller.signal })
        if (!controller.signal.aborted) setChoices(result)
      } catch (failure) {
        if (controller.signal.aborted || isAbortError(failure)) return
        const next = apiError(failure); setError(next); if (shouldReloadContext(next)) onInvalid()
      } finally { if (!controller.signal.aborted) setLoading(false) }
    }
    void load(); return () => controller.abort()
  }, [org, team.id, revision, page, at, validDate, onInvalid])
  const options = choices?.data.filter((period) => period.effective && ['player', 'substitute'].includes(period.role_key)) ?? []
  const blocked = disabled || loading
  return <div className="grid min-w-0 grid-cols-1 gap-3 rounded-xl border border-cyan-400/30 p-4">
    <h4 className="font-bold">{text('create')}</h4><p className="text-sm">{text('selectionHint')}</p>
    {lineup && <p className="text-sm text-amber-200">{text('editHint')}</p>}
    <FormField name="effective_at" label={text('effective_at')} type="datetime-local" step="1" value={date} disabled={disabled} required error={errors.effective_at?.[0]}
      onChange={(event) => { setDate(event.target.value); setSelected([]); setChoices(null); setPage(1) }} />
    {loading && <p role="status">{text('rosterLoading')}</p>}{error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
    {choices && options.length === 0 && <p>{text('noChoices')}</p>}
    <ul aria-label={text('lookup')} className="grid min-w-0 grid-cols-1 gap-3">{options.map((period) => {
      const picked = selected.some((choice) => choice.person_id === period.person_id)
      return <li key={period.id}><label className="flex min-w-0 items-start gap-2 [overflow-wrap:anywhere]">
        <input type="checkbox" className="mt-1 shrink-0" disabled={blocked || (!picked && selected.length >= MAX_LINEUP_ENTRIES)} checked={selected.some((choice) => choice.membership_id === period.id)}
          onChange={(event) => setSelected((current) => event.target.checked ? [...current.filter((choice) => choice.person_id !== period.person_id), {
            membership_id: period.id, membership_version: period.version, person_id: period.person_id, pseudonym: period.pseudonym ?? period.person_id,
            membership_role_key: period.role_key as 'player' | 'substitute', slot_key: 'starter',
          }] : current.filter((choice) => choice.membership_id !== period.id))} />
        <span className="min-w-0">{text('choose')} {period.pseudonym ?? period.person_id}</span>
      </label></li>
    })}</ul>
    {choices && <Pagination currentPage={choices.meta.current_page} totalPages={choices.meta.last_page} disabled={blocked} onPageChange={setPage} />}
    <form aria-label={text('save')} className="grid min-w-0 grid-cols-1 gap-3" onSubmit={(event) => {
      event.preventDefault()
      const fields = new FormData(event.currentTarget)
      onSubmit(event, { label: fields.get('label'), effective_at: at, team_version: team.version,
        ...(lineup ? { version: lineup.version, action: 'replace' } : { purpose: fields.get('purpose') }),
        entries: selected.map((choice) => ({ membership_id: choice.membership_id, membership_version: choice.membership_version, slot_key: choice.slot_key })) })
    }}>
      <FormField name="label" label={text('label')} defaultValue={lineup?.label ?? ''} maxLength={120} required disabled={disabled} error={errors.label?.[0]} />
      {!lineup && <SelectField name="purpose" title={text('purpose')} defaultValue="match" disabled={disabled} error={errors.purpose?.[0]}><option value="match">{text('purpose.match')}</option><option value="registration">{text('purpose.registration')}</option></SelectField>}
      <h5 className="font-bold">{text('selected')}</h5>
      {errors.entries && <p role="alert" className="text-sm text-rose-200">{errors.entries[0]}</p>}
      <ol className="grid min-w-0 grid-cols-1 gap-3">{selected.map((choice, index) => <li key={choice.membership_id} className="grid min-w-0 grid-cols-1 gap-2 rounded-xl border border-slate-700 p-3">
        <p className="[overflow-wrap:anywhere]">{index + 1}. {choice.pseudonym}</p>
        <SelectField name={'slot_' + choice.membership_id} title={text('slot') + ' ' + choice.pseudonym} value={choice.slot_key} disabled={disabled}
          onChange={(slot) => setSelected((current) => current.map((entry) => entry.membership_id === choice.membership_id ? { ...entry, slot_key: slot as 'starter' | 'substitute' } : entry))}>
          <option value="starter">{text('slot.starter')}</option><option value="substitute">{text('slot.substitute')}</option>
        </SelectField>
        <Button className="min-w-0 [overflow-wrap:anywhere]" disabled={disabled} onClick={() => setSelected((current) => current.filter((entry) => entry.membership_id !== choice.membership_id))}>{text('remove')} {choice.pseudonym}</Button>
      </li>)}</ol>
      <div className="flex flex-wrap gap-3"><Button type="submit" disabled={blocked || !validDate || !selected.some((choice) => choice.slot_key === 'starter')}>{text('save')}</Button><Button disabled={disabled} onClick={onCancel}>{text('back')}</Button></div>
    </form>
  </div>
}
