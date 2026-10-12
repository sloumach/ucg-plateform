import { useCallback, useEffect, useRef, useState } from 'react'
import type { Person, PeopleView } from '../../api/people'
import { getPersonDeparture, listDepartureItems, listPersonPeriods, personLifecyclePath, type DepartureItem, type PersonPeriod } from '../../api/personLifecycle'
import type { ActiveOrganization } from '../../api/tenancy'
import type { GameProfileCatalogue } from '../../api/gameProfiles'
import { isAbortError } from '../../api/errors'
import { t, type TranslationKey } from '../../i18n/fr'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { AsyncState } from '../feedback/AsyncState'
import { SelectField } from './peopleForms'
import { apiError, changedValues, localDate, shouldReloadContext } from './peopleFormValues'
import { usePeopleMutation } from './usePeopleMutation'
import { GameProfilesPanel } from './GameProfilesPanel'

const roles = ['player', 'substitute', 'captain', 'coach', 'analyst', 'staff']
const lt = (name: string) => t(('lifecycle.' + name) as TranslationKey)
type Data = { periods: Awaited<ReturnType<typeof listPersonPeriods>> | null; items: Awaited<ReturnType<typeof listDepartureItems>> | null; departure: Awaited<ReturnType<typeof getPersonDeparture>>['data'] }

export function PersonLifecyclePanel({ active, person, view, catalogue, disabled: parentDisabled, onInvalid, onClosed }: {
  active: ActiveOrganization; person: Person; view: PeopleView; catalogue: GameProfileCatalogue['data'] | null
  disabled: boolean; onInvalid: () => void; onClosed: (closed: boolean) => void
}) {
  const org = active.context?.organization_id
  const revision = active.revision ?? undefined
  const permissions = active.context?.permissions ?? []
  const sports = view !== 'administrative' && permissions.includes('sports.read')
  const admin = view !== 'sports' && permissions.includes('administrative.read')
  const [data, setData] = useState<Data | null>(null)
  const [error, setError] = useState<ReturnType<typeof apiError> | null>(null)
  const [periodPage, setPeriodPage] = useState(1)
  const [itemPage, setItemPage] = useState(1)
  const [periodEditor, setPeriodEditor] = useState<PersonPeriod | null | undefined>(undefined)
  const [itemEditor, setItemEditor] = useState<DepartureItem | null | undefined>(undefined)
  const [kind, setKind] = useState('assignment')
  const [loading, setLoading] = useState(true)
  const [refresh, setRefresh] = useState(0)
  const alive = useRef(true)
  const departing = useRef(false)
  const saved = () => {
    setPeriodEditor(undefined); setItemEditor(undefined)
    if (departing.current) onInvalid()
    else setRefresh((value) => value + 1)
  }
  const mutation = usePeopleMutation(active, saved, onInvalid)
  const load = useCallback(async (signal: AbortSignal) => {
    if (!org) return
    setLoading(true); setData(null); setError(null); setPeriodEditor(undefined); setItemEditor(undefined)
    try {
      const options = { revision, signal }
      const [periods, items, departure] = await Promise.all([
        sports ? listPersonPeriods(org, person.id, { ...options, page: periodPage }) : null,
        admin ? listDepartureItems(org, person.id, { ...options, page: itemPage }) : null,
        getPersonDeparture(org, person.id, options),
      ])
      if (signal.aborted || !alive.current) return
      setData({ periods, items, departure: departure.data }); onClosed(departure.data.departed)
    } catch (failure) {
      if (signal.aborted || !alive.current || isAbortError(failure)) return
      const next = apiError(failure); setError(next)
      if (shouldReloadContext(next)) onInvalid()
    } finally { if (!signal.aborted && alive.current) setLoading(false) }
  }, [org, person.id, sports, admin, revision, periodPage, itemPage, onInvalid, onClosed])
  useEffect(() => {
    alive.current = true
    const controller = new AbortController()
    void Promise.resolve().then(() => { if (!controller.signal.aborted) void load(controller.signal) })
    return () => { alive.current = false; controller.abort() }
  }, [load, refresh])
  const disabled = parentDisabled || loading || mutation.blocked
  const closed = Boolean(data?.departure.departed)
  const periodPath = personLifecyclePath(org ?? '', person.id, 'periods') + (periodEditor ? '/' + periodEditor.id : '')
  const itemPath = personLifecyclePath(org ?? '', person.id, 'departure-items') + (itemEditor ? '/' + itemEditor.id : '')
  const departurePath = personLifecyclePath(org ?? '', person.id, 'departure')
  const canDepart = admin && ['sports.write', 'administrative.write', 'memberships.manage', 'invitations.manage'].every((permission) => permissions.includes(permission))
  const periodErrors = mutation.errors(periodPath)
  const itemErrors = mutation.errors(itemPath)

  return <section aria-label={lt('title')} aria-busy={loading} className="grid min-w-0 gap-5">
    <h4 className="text-lg font-semibold">{lt('title')}</h4>
    {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
    {mutation.feedback}
    {loading && <AsyncState variant="loading" title={t('people.loading')} />}
    {data && <>
      {closed && <Alert tone="info" title={t('lifecycle.departed', { date: data.departure.departed_at ? new Date(data.departure.departed_at).toLocaleString('fr') : t('people.none') })}>{lt('closedHint')}</Alert>}
      {closed && admin && data.departure.reason_key && <p>{lt('reason')} : {lt(data.departure.reason_key)}</p>}
      {sports && data.periods && <section aria-label={lt('periods')} className="grid min-w-0 gap-3">
        <h5 className="font-semibold">{lt('periods')}</h5>
        <p className="text-sm text-slate-400">{lt('periodHint')}</p>
        <p role="status">{lt(data.periods.meta.unavailable_now ? 'unavailable' : 'available')}</p>
        {!closed && permissions.includes('sports.write') && <Button disabled={disabled} onClick={() => { setPeriodEditor(null); setKind('assignment') }}>{lt('addPeriod')}</Button>}
        {periodEditor !== undefined && !closed && <form noValidate aria-label={lt('savePeriod')} key={periodEditor?.version ?? 'new'} className="grid min-w-0 grid-cols-1 gap-3 rounded-xl border border-cyan-400/30 p-4 md:grid-cols-2" onSubmit={(event) => {
          departing.current = false
          const values = changedValues(event.currentTarget, periodEditor, ['starts_at', 'ends_at'])
          mutation.propose(event, lt('savePeriod'), periodPath, { ...values, ...(periodEditor ? { version: periodEditor.version } : {}) }, periodEditor !== null)
        }}>
          {periodEditor ? <p>{lt(periodEditor.kind)} · {periodEditor.role_key ? lt(periodEditor.role_key) : ''}</p> : <>
            <SelectField name="kind" title={lt('kind')} value={kind} onChange={setKind} disabled={disabled} error={periodErrors.kind?.[0]}><option value="assignment">{lt('assignment')}</option><option value="unavailability">{lt('unavailability')}</option></SelectField>
            {kind === 'assignment' && <SelectField name="role_key" title={lt('role')} disabled={disabled} error={periodErrors.role_key?.[0]}>{roles.map((role) => <option key={role} value={role}>{lt(role)}</option>)}</SelectField>}
          </>}
          <FormField name="starts_at" label={lt('starts_at')} type="datetime-local" step="1" required defaultValue={localDate(periodEditor?.starts_at)} disabled={disabled} error={periodErrors.starts_at?.[0]} hint={t('tenant.dateHint')} />
          <FormField name="ends_at" label={lt('ends_at')} type="datetime-local" step="1" defaultValue={localDate(periodEditor?.ends_at)} disabled={disabled} error={periodErrors.ends_at?.[0]} />
          <SelectField name="status" title={t('people.recordStatus')} defaultValue={periodEditor?.status ?? 'active'} disabled={disabled} error={periodErrors.status?.[0]}><option value="active">{lt('active')}</option><option value="cancelled">{lt('cancelled')}</option></SelectField>
          <div className="flex flex-wrap gap-3 md:col-span-2"><Button type="submit" disabled={disabled}>{lt('savePeriod')}</Button><Button disabled={disabled} onClick={() => setPeriodEditor(undefined)}>{t('tenant.cancel')}</Button></div>
        </form>}
        {data.periods.data.length === 0 && <p>{lt('periodEmpty')}</p>}
        {data.periods.data.map((period) => <article key={period.id} className="grid min-w-0 gap-2 rounded-xl border border-white/10 p-4">
          <p className="font-semibold">{lt(period.kind)} · {period.role_key ? lt(period.role_key) : ''} · {lt(period.status)}</p>
          <p className="break-words">{new Date(period.starts_at).toLocaleString('fr')} → {period.ends_at ? new Date(period.ends_at).toLocaleString('fr') : t('tenant.noEnd')}</p>
          {period.effective && <p>{lt('effective')}</p>}
          {!closed && period.status === 'active' && permissions.includes('sports.write') && <Button disabled={disabled} onClick={() => setPeriodEditor(period)}>{lt('editPeriod')} · {period.role_key ? lt(period.role_key) : lt(period.kind)}</Button>}
        </article>)}
        <Pagination currentPage={data.periods.meta.current_page} totalPages={data.periods.meta.last_page} disabled={disabled} onPageChange={setPeriodPage} />
      </section>}
      {admin && data.items && <section aria-label={lt('items')} className="grid min-w-0 gap-3">
        <h5 className="font-semibold">{lt('items')}</h5><p>{t('lifecycle.pending', { count: data.items.meta.pending_items_count })}</p>
        {permissions.includes('administrative.write') && <Button disabled={disabled} onClick={() => setItemEditor(null)}>{lt('addItem')}</Button>}
        {itemEditor !== undefined && <form noValidate key={itemEditor?.version ?? 'new'} aria-label={lt('saveItem')} className="grid min-w-0 grid-cols-1 gap-3 rounded-xl border border-cyan-400/30 p-4 md:grid-cols-2" onSubmit={(event) => {
          departing.current = false
          const values = changedValues(event.currentTarget, itemEditor, ['due_at'])
          mutation.propose(event, lt('saveItem'), itemPath, { ...values, ...(itemEditor ? { version: itemEditor.version } : {}) }, itemEditor !== null)
        }}>
          {itemEditor ? <p>{lt(itemEditor.kind)}</p> : <SelectField name="kind" title={lt('kind')} disabled={disabled} error={itemErrors.kind?.[0]}><option value="equipment">{lt('equipment')}</option><option value="obligation">{lt('obligation')}</option></SelectField>}
          <FormField name="title" label={lt('itemTitle')} defaultValue={itemEditor?.title ?? ''} required maxLength={120} disabled={disabled} error={itemErrors.title?.[0]} />
          <div className="grid min-w-0 gap-2 md:col-span-2"><label htmlFor={person.id + '-item-notes'}>{lt('notes')}</label><textarea id={person.id + '-item-notes'} name="notes" defaultValue={itemEditor?.notes ?? ''} maxLength={4000} rows={3} disabled={disabled} aria-invalid={Boolean(itemErrors.notes)} aria-describedby={itemErrors.notes ? person.id + '-notes-error' : undefined} className="min-w-0 w-full rounded-xl border border-white/15 bg-slate-900 p-3" />{itemErrors.notes && <p id={person.id + '-notes-error'} className="text-sm text-rose-200">{itemErrors.notes[0]}</p>}</div>
          <SelectField name="status" title={lt('itemStatus')} defaultValue={itemEditor?.status ?? 'open'} disabled={disabled} error={itemErrors.status?.[0]}>{['open', 'resolved', 'waived'].map((status) => <option key={status} value={status}>{lt(status)}</option>)}</SelectField>
          <FormField name="due_at" label={lt('due_at')} type="datetime-local" step="1" defaultValue={localDate(itemEditor?.due_at)} disabled={disabled} error={itemErrors.due_at?.[0]} />
          <div className="flex flex-wrap gap-3 md:col-span-2"><Button type="submit" disabled={disabled}>{lt('saveItem')}</Button><Button disabled={disabled} onClick={() => setItemEditor(undefined)}>{t('tenant.cancel')}</Button></div>
        </form>}
        {data.items.data.length === 0 && <p>{lt('itemsEmpty')}</p>}
        {data.items.data.map((item) => <article key={item.id} className="grid min-w-0 gap-2 rounded-xl border border-white/10 p-4">
          <p className="break-words font-semibold">{item.title} · {lt(item.kind)} · {lt(item.status)}</p><p className="whitespace-pre-wrap break-words">{item.notes}</p>
          {item.due_at && <p>{lt('due_at')} : {new Date(item.due_at).toLocaleString('fr')}</p>}
          {item.completed_at && <p>{lt('completed_at')} : {new Date(item.completed_at).toLocaleString('fr')}</p>}
          {permissions.includes('administrative.write') && <Button disabled={disabled} onClick={() => setItemEditor(item)}>{lt('editItem')} · {item.title}</Button>}
        </article>)}
        <Pagination currentPage={data.items.meta.current_page} totalPages={data.items.meta.last_page} disabled={disabled} onPageChange={setItemPage} />
      </section>}
      {!closed && canDepart && <form noValidate aria-label={lt('departure')} className="grid min-w-0 gap-3 rounded-xl border border-amber-400/30 p-4" onSubmit={(event) => {
        const form = new FormData(event.currentTarget); departing.current = true
        mutation.propose(event, lt('depart') + ' — ' + (person.pseudonym ?? person.last_name ?? person.id), departurePath, { version: person.version, reason_key: form.get('reason_key'), confirm: form.has('confirm'), acknowledge_pending: form.has('acknowledge_pending') })
      }}>
        <h5 className="font-semibold">{lt('departure')}</h5><p className="text-sm text-slate-400">{lt('departureHint')}</p>
        <p>{person.user_id ? t('lifecycle.linkedAccount', { id: person.user_id }) : lt('noAccount')}</p>
        <SelectField name="reason_key" title={lt('reason')} disabled={disabled} error={mutation.errors(departurePath).reason_key?.[0]}>{['resignation', 'end_of_contract', 'decision', 'other'].map((reason) => <option key={reason} value={reason}>{lt(reason)}</option>)}</SelectField>
        <label className="flex items-start gap-3"><input name="confirm" type="checkbox" disabled={disabled} />{lt('confirm')}</label>
        {mutation.errors(departurePath).confirm && <p className="text-rose-200">{mutation.errors(departurePath).confirm[0]}</p>}
        {(data.items?.meta.pending_items_count ?? 0) > 0 && <label className="flex items-start gap-3"><input name="acknowledge_pending" type="checkbox" disabled={disabled} />{lt('pendingConfirm')}</label>}
        <Button type="submit" disabled={disabled}>{lt('depart')}</Button>
      </form>}
      {sports && <GameProfilesPanel active={active} personId={person.id} catalogue={catalogue} disabled={disabled} closed={closed} onInvalid={onInvalid} />}
    </>}
  </section>
}
