import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { tenancyRequest, type ActiveOrganization, type Page } from '../../api/tenancy'
import type { ApiSuccessResponse } from '../../api/contracts'
import { isAbortError } from '../../api/errors'
import { contractText as text, contractsPath, contractTypes, contractStatuses, validationTargets, type ContractRecord, type ContractStatus, type ContractEvent, type ContractPerson } from '../../api/contractRecords'
import { t } from '../../i18n/fr'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { apiError, shouldReloadContext } from '../people/peopleFormValues'
import { SelectField } from '../people/peopleForms'
import { usePeopleMutation } from '../people/usePeopleMutation'
import { ContractDocumentsPanel } from './ContractDocumentsPanel'

type Props = { active: ActiveOrganization; disabled: boolean; onInvalid: () => void }
type PartyInput = { kind: 'person' | 'external'; person_id: string | null; name: string }
type ObligationInput = { title: string; due_on: string | null }
export function ContractsPanel({ active, disabled, onInvalid }: Props) {
  const org = active.context?.organization_id, revision = active.revision ?? undefined
  const allowed = active.context?.permissions.includes('administrative.read') ?? false
  const manage = allowed && (active.context?.permissions.includes('administrative.write') ?? false)
  const validate = active.context?.permissions.includes('administrative.validate') ?? false
  const [open, setOpen] = useState(false), [page, setPage] = useState(1), [refresh, setRefresh] = useState(0)
  const [filters, setFilters] = useState<Record<string, string>>({})
  const [list, setList] = useState<Page<ContractRecord> | null>(null), [detailId, setDetailId] = useState<string | null>(null)
  const [detail, setDetail] = useState<ContractRecord | null>(null), [editing, setEditing] = useState(false)
  const [creating, setCreating] = useState(false), [loading, setLoading] = useState(false), [detailLoading, setDetailLoading] = useState(false)
  const [error, setError] = useState<ReturnType<typeof apiError> | null>(null)
  const invalidate = useCallback(() => { setDetail(null); setDetailId(null); setEditing(false); setCreating(false); setRefresh((value) => value + 1); onInvalid() }, [onInvalid])
  const mutation = usePeopleMutation<ContractRecord>(active, (record) => { setDetail(record); setDetailId(record.id); setCreating(false); setEditing(false); setRefresh((value) => value + 1) }, invalidate)
  useEffect(() => {
    if (!org || !allowed || !open) return
    const controller = new AbortController()
    const load = async () => {
      setLoading(true); setList(null); setError(null)
      try {
        const query = new URLSearchParams({ per_page: '10', page: String(page), ...filters })
        const result = await tenancyRequest<Page<ContractRecord>>(contractsPath(org) + '?' + query, { revision, signal: controller.signal })
        if (!controller.signal.aborted) setList(result)
      } catch (failure) {
        if (controller.signal.aborted || isAbortError(failure)) return
        const next = apiError(failure); setError(next); if (shouldReloadContext(next)) onInvalid()
      } finally { if (!controller.signal.aborted) setLoading(false) }
    }
    void load(); return () => controller.abort()
  }, [org, revision, allowed, open, page, filters, refresh, onInvalid])
  useEffect(() => {
    if (!org || !allowed || !open || !detailId) return
    const controller = new AbortController()
    const load = async () => {
      setDetailLoading(true)
      try {
        const result = await tenancyRequest<ApiSuccessResponse<ContractRecord>>(contractsPath(org, detailId), { revision, signal: controller.signal })
        if (!controller.signal.aborted) setDetail(result.data)
      } catch (failure) {
        if (controller.signal.aborted || isAbortError(failure)) return
        const next = apiError(failure); setError(next); setDetail(null); if (shouldReloadContext(next)) invalidate()
      } finally { if (!controller.signal.aborted) setDetailLoading(false) }
    }
    void load(); return () => controller.abort()
  }, [org, revision, allowed, open, detailId, refresh, invalidate])
  if (!org || !allowed) return null
  const blocked = disabled || loading || detailLoading || mutation.blocked
  const detailPath = detail ? contractsPath(org, detail.id) : ''
  function filter(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setDetail(null); setDetailId(null); setEditing(false); setCreating(false)
    setFilters(Object.fromEntries([...new FormData(event.currentTarget)].filter(([, value]) => value !== '').map(([key, value]) => [key, String(value)]))); setPage(1)
  }
  const save = (event: FormEvent<HTMLFormElement>, body: Record<string, unknown>, record: ContractRecord | null) =>
    mutation.propose(event, text('save'), contractsPath(org!, record?.id), { ...body, ...(record ? { version: record.version } : {}) }, !!record)
  return <section aria-label={text('title')} className="grid min-w-0 gap-4 rounded-xl border border-slate-700 p-4">
    <Button disabled={disabled || mutation.blocked} onClick={() => { setOpen((value) => !value); setDetailId(null); setDetail(null); setCreating(false); setEditing(false) }}>{open ? text('close') : text('title')}</Button>
    {open && <>
      <p className="text-sm text-slate-400 [overflow-wrap:anywhere]">{text('private')}</p>
      {loading || detailLoading ? <p role="status">{text('loading')}</p> : null}
      {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
      {mutation.feedback}
      <form aria-label={text('search')} onSubmit={filter} className="grid min-w-0 gap-3 sm:grid-cols-2">
        <FormField name="q" label={text('q')} maxLength={80} disabled={blocked} />
        <SelectField name="type" title={text('type')} disabled={blocked}><option value="">{text('all')}</option>{contractTypes.map((kind) => <option key={kind} value={kind}>{text('type.' + kind)}</option>)}</SelectField>
        <SelectField name="status" title={text('status')} disabled={blocked}><option value="">{text('all')}</option>{contractStatuses.map((status) => <option key={status} value={status}>{text('status.' + status)}</option>)}</SelectField>
        <Button type="submit" disabled={blocked}>{text('search')}</Button>
      </form>
      {manage && <Button disabled={blocked} onClick={() => { setCreating(true); setEditing(false); setDetail(null); setDetailId(null) }}>{text('create')}</Button>}
      {!manage && <p>{text('readOnly')}</p>}
      {list?.data.length === 0 && <p>{text('empty')}</p>}
      {list && <ul className="grid min-w-0 gap-3 sm:grid-cols-2">{list.data.map((record) => <li key={record.id} className="grid min-w-0 gap-2 rounded-xl border border-slate-700 p-3">
        <h3 className="font-bold [overflow-wrap:anywhere]">{record.title}</h3>
        <p className="[overflow-wrap:anywhere]">{record.reference} · {text('type.' + record.type)} · {text('status.' + record.status)}</p>
        <p className="[overflow-wrap:anywhere]">{text('responsible')} : {record.responsible_name}</p>
        <p>{text('pending')} : {record.pending_obligations_count}</p>
        <Button disabled={blocked} onClick={() => { setCreating(false); setEditing(false); setDetail(null); setDetailId(record.id) }}>{text('detail')}</Button>
      </li>)}</ul>}
      {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={blocked} onPageChange={(next) => { setDetail(null); setDetailId(null); setPage(next) }} />}
      {creating && <ContractForm key="new" active={active} record={null} disabled={blocked} errors={mutation.errors(contractsPath(org))} onInvalid={invalidate} onCancel={() => setCreating(false)} onSave={save} />}
      {detail && <section aria-label={text('detail')} className="grid min-w-0 gap-3 rounded-xl border border-slate-700 p-4">
        <h3 className="font-bold [overflow-wrap:anywhere]">{detail.title}</h3>
        <p>{text('status.' + detail.status)} · {detail.in_force ? text('inForce') : text('notInForce')}</p>
        <p className="text-sm text-slate-400 [overflow-wrap:anywhere]">{text('eligibility')}</p>
        <ContractDocumentsPanel key={detail.id} active={active} record={detail} disabled={blocked} onInvalid={invalidate}
          onChanged={() => { setDetailLoading(true); setEditing(false); setRefresh((value) => value + 1) }} />
        <p className="[overflow-wrap:anywhere]">{text('responsible')} : {detail.responsible_name}</p>
        <p>{text('starts_on')} : {detail.starts_on ?? '—'} · {text('ends_on')} : {detail.ends_on ?? '—'}</p>
        {detail.signed_on && <p className="[overflow-wrap:anywhere]">{text('signature')} : {detail.signed_on} · {detail.signature_reference}. {text('signatureHint')}</p>}
        {detail.parties?.map((party) => <p key={party.id} className="[overflow-wrap:anywhere]">{text('party')} : {party.name}</p>)}
        {detail.obligations?.map((item) => <form key={item.id + item.version} aria-label={text('obligation') + ' ' + item.title} className="grid min-w-0 gap-2 rounded-xl border border-slate-700 p-3"
          onSubmit={(event) => mutation.propose(event, text('obligationSave') + ' ' + item.title, detailPath + '/obligations/' + item.id,
            { version: item.version, contract_version: detail.version, status: new FormData(event.currentTarget).get('status') }, true)}>
          <p className="[overflow-wrap:anywhere]">{item.title}{item.due_on && ' · ' + item.due_on} · {text('obligation.' + item.status)}</p>
          {manage && detail.status !== 'archived' && <><SelectField name="status" title={text('obligationStatus')} defaultValue={item.status} disabled={blocked}>
            {(['pending', 'completed', 'waived'] as const).map((status) => <option value={status} key={status}>{text('obligation.' + status)}</option>)}
          </SelectField><Button type="submit" disabled={blocked}>{text('obligationSave')}</Button></>}
        </form>)}
        {manage && detail.status !== 'archived' && <Button disabled={blocked} onClick={() => setEditing((value) => !value)}>{text('edit')}</Button>}
        {editing && <ContractForm key={detail.id + detail.version} active={active} record={detail} disabled={blocked} errors={mutation.errors(detailPath)} onInvalid={invalidate} onCancel={() => setEditing(false)} onSave={save} />}
        {manage && detail.status !== 'archived' && <TransitionForm key={'transition:' + detail.version} record={detail} actor={active.context!.actor_user_id} validate={validate}
          disabled={blocked} errors={mutation.errors(detailPath + '/transitions')} onSubmit={(event, body) => mutation.propose(event, text('transition'), detailPath + '/transitions', { version: detail.version, ...body })} />}
        <ContractHistory key={'history:' + detail.id + ':' + detail.version} active={active} record={detail} onInvalid={invalidate} />
      </section>}
    </>}
  </section>
}

function ContractForm({ active, record, disabled, errors, onSave, onCancel, onInvalid }: {
  active: ActiveOrganization; record: ContractRecord | null; disabled: boolean; errors: Record<string, string[]>
  onSave: (event: FormEvent<HTMLFormElement>, body: Record<string, unknown>, record: ContractRecord | null) => void; onCancel: () => void; onInvalid: () => void
}) {
  const editable = !record || ['draft', 'in_review'].includes(record.status)
  const partyBase = (record?.parties ?? []).map((item) => ({ kind: item.kind, person_id: item.person_id, name: item.name }))
  const obligationBase = (record?.obligations ?? []).map((item) => ({ title: item.title, due_on: item.due_on }))
  const [parties, setParties] = useState<PartyInput[]>(record ? partyBase : [{ kind: 'external', person_id: null, name: '' }])
  const [obligations, setObligations] = useState<ObligationInput[]>(obligationBase)
  const [people, setPeople] = useState<Page<ContractPerson> | null>(null), [peoplePage, setPeoplePage] = useState(1)
  const [loading, setLoading] = useState(false), [error, setError] = useState<ReturnType<typeof apiError> | null>(null)
  const [responsible, setResponsible] = useState(record?.responsible_person_id ?? ''), [responsibleLabel, setResponsibleLabel] = useState(record?.responsible_name ?? '')
  const org = active.context!.organization_id, revision = active.revision ?? undefined
  useEffect(() => {
    const controller = new AbortController()
    const load = async () => {
      setLoading(true); setError(null)
      try {
        const next = await tenancyRequest<Page<ContractPerson>>(contractsPath(org) + '/people?per_page=10&page=' + peoplePage, { revision, signal: controller.signal })
        if (!controller.signal.aborted) setPeople(next)
      } catch (failure) {
        if (controller.signal.aborted || isAbortError(failure)) return
        const next = apiError(failure); setError(next); if (shouldReloadContext(next)) onInvalid()
      } finally { if (!controller.signal.aborted) setLoading(false) }
    }
    void load(); return () => controller.abort()
  }, [org, revision, peoplePage, onInvalid])
  const blocked = disabled || loading
  const choices = people?.data ?? []
  const updateParty = (index: number, changes: Partial<PartyInput>) => setParties((items) => items.map((item, position) => position === index ? { ...item, ...changes } : item))
  const updateObligation = (index: number, changes: Partial<ObligationInput>) => setObligations((items) => items.map((item, position) => position === index ? { ...item, ...changes } : item))
  return <form noValidate aria-label={text('save')} className="grid min-w-0 gap-3 rounded-xl border border-cyan-400/30 p-4" onSubmit={(event) => {
    const data = new FormData(event.currentTarget), body: Record<string, unknown> = {}
    for (const field of record ? ['title', 'starts_on', 'ends_on', 'responsible_person_id'] : ['reference', 'type', 'title', 'starts_on', 'ends_on', 'responsible_person_id']) {
      if (!data.has(field)) continue
      const value = String(data.get(field) ?? '') || null
      if (!record || value !== record[field as keyof ContractRecord]) body[field] = value
    }
    if (editable && (!record || JSON.stringify(parties) !== JSON.stringify(partyBase))) body.parties = parties
    if (editable && (!record || JSON.stringify(obligations) !== JSON.stringify(obligationBase))) body.obligations = obligations
    onSave(event, body, record)
  }}>
    {!record && <><FormField name="reference" label={text('reference')} required maxLength={80} disabled={blocked} error={errors.reference?.[0]} />
      <SelectField name="type" title={text('type')} defaultValue="player" disabled={blocked} error={errors.type?.[0]}>{contractTypes.map((kind) => <option key={kind} value={kind}>{text('type.' + kind)}</option>)}</SelectField></>}
    {editable ? <><FormField name="title" label={text('dossierTitle')} defaultValue={record?.title ?? ''} required maxLength={160} disabled={blocked} error={errors.title?.[0]} />
      <FormField name="starts_on" label={text('starts_on')} type="date" defaultValue={record?.starts_on ?? ''} disabled={blocked} error={errors.starts_on?.[0]} />
      <FormField name="ends_on" label={text('ends_on')} type="date" defaultValue={record?.ends_on ?? ''} disabled={blocked} error={errors.ends_on?.[0]} /></> : <p className="[overflow-wrap:anywhere]">{text('frozen')}</p>}
    {loading && <p role="status">{text('peopleLoading')}</p>}{error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
    <SelectField name="responsible_person_id" title={text('responsible')} value={responsible} disabled={blocked} error={errors.responsible_person_id?.[0]} onChange={(value) => {
      setResponsible(value); setResponsibleLabel(choices.find((person) => person.id === value)?.label ?? '')
    }}>
      <option value="">{text('choosePerson')}</option>
      {responsible && !choices.some((person) => person.id === responsible) && <option value={responsible}>{responsibleLabel}</option>}
      {choices.map((person) => <option key={person.id} value={person.id}>{person.label}</option>)}
    </SelectField>
    {people && <Pagination currentPage={people.meta.current_page} totalPages={people.meta.last_page} disabled={blocked} onPageChange={setPeoplePage} />}
    <p className="text-sm text-slate-400 [overflow-wrap:anywhere]">{text('peopleHint')}</p>
    {editable && <>
      <h4 className="font-bold">{text('parties')}</h4>
      {parties.map((party, index) => <fieldset key={index} className="grid min-w-0 gap-2 rounded-xl border border-slate-700 p-3">
        <legend>{text('party')} {index + 1}</legend>
        <SelectField name={'parties.' + index + '.kind'} title={text('partyKind')} value={party.kind} disabled={blocked} onChange={(kind) => updateParty(index, { kind: kind as PartyInput['kind'], person_id: null })}>
          <option value="external">{text('partyExternal')}</option><option value="person">{text('partyPerson')}</option>
        </SelectField>
        {party.kind === 'person' && <SelectField name={'parties.' + index + '.person_id'} title={text('partyPerson')} value={party.person_id ?? ''} disabled={blocked} error={errors['parties.' + index + '.person_id']?.[0]} onChange={(value) => updateParty(index, { person_id: value || null, name: choices.find((person) => person.id === value)?.label ?? party.name })}>
          <option value="">{text('choosePerson')}</option>{party.person_id && !choices.some((person) => person.id === party.person_id) && <option value={party.person_id}>{party.name}</option>}
          {choices.map((person) => <option key={person.id} value={person.id}>{person.label}</option>)}
        </SelectField>}
        <FormField name={'parties.' + index + '.name'} label={text('partyName')} value={party.name} onChange={(event) => updateParty(index, { name: event.target.value })} required maxLength={160} disabled={blocked} error={errors['parties.' + index + '.name']?.[0]} />
        <Button disabled={blocked || parties.length === 1} onClick={() => setParties((items) => items.filter((_, position) => position !== index))}>{text('remove')}</Button>
      </fieldset>)}
      {errors.parties && <p role="alert">{errors.parties[0]}</p>}
      <Button disabled={blocked || parties.length >= 10} onClick={() => setParties((items) => [...items, { kind: 'external', person_id: null, name: '' }])}>{text('addParty')}</Button>
      <h4 className="font-bold">{text('obligations')}</h4>
      {record?.obligations?.some((item) => item.status !== 'pending') ? <p>{text('obligationsRetained')}</p> : <>
        {obligations.map((item, index) => <fieldset key={index} className="grid min-w-0 gap-2 rounded-xl border border-slate-700 p-3">
          <legend>{text('obligation')} {index + 1}</legend>
          <FormField name={'obligations.' + index + '.title'} label={text('obligationTitle')} value={item.title} onChange={(event) => updateObligation(index, { title: event.target.value })} required maxLength={160} disabled={blocked} error={errors['obligations.' + index + '.title']?.[0]} />
          <FormField name={'obligations.' + index + '.due_on'} label={text('due_on')} type="date" value={item.due_on ?? ''} onChange={(event) => updateObligation(index, { due_on: event.target.value || null })} disabled={blocked} error={errors['obligations.' + index + '.due_on']?.[0]} />
          <Button disabled={blocked} onClick={() => setObligations((items) => items.filter((_, position) => position !== index))}>{text('remove')}</Button>
        </fieldset>)}
        <Button disabled={blocked || obligations.length >= 50} onClick={() => setObligations((items) => [...items, { title: '', due_on: null }])}>{text('addObligation')}</Button>
      </>}
      {errors.obligations && <p role="alert">{errors.obligations[0]}</p>}
    </>}
    <div className="flex flex-wrap gap-3"><Button type="submit" disabled={blocked}>{text('save')}</Button><Button disabled={blocked} onClick={onCancel}>{t('tenant.cancel')}</Button></div>
  </form>
}

function TransitionForm({ record, actor, validate, disabled, errors, onSubmit }: {
  record: ContractRecord; actor: number; validate: boolean; disabled: boolean; errors: Record<string, string[]>
  onSubmit: (event: FormEvent<HTMLFormElement>, body: Record<string, unknown>) => void
}) {
  const choices = record.available_transitions.filter((status) => (!validationTargets.includes(status) || validate) && (status !== 'approved' || actor !== record.prepared_by))
  const [target, setTarget] = useState<ContractStatus>(choices[0] ?? 'draft')
  if (choices.length === 0) return <p className="[overflow-wrap:anywhere]">{text('validationRequired')}</p>
  return <form noValidate aria-label={text('transition')} className="grid min-w-0 gap-3 rounded-xl border border-amber-400/30 p-3" onSubmit={(event) => {
    const data = new FormData(event.currentTarget)
    onSubmit(event, { status: target, ...(target === 'signed' ? { signed_on: data.get('signed_on'), signature_reference: data.get('signature_reference'), confirm_signature_received: data.has('confirm_signature_received') } : {}),
      ...(target === 'terminated' ? { terminated_on: data.get('terminated_on') } : {}) })
  }}>
    <SelectField name="status" title={text('nextStatus')} value={target} onChange={(value) => setTarget(value as ContractStatus)} disabled={disabled}>
      {choices.map((status) => <option key={status} value={status}>{text('status.' + status)}</option>)}
    </SelectField>
    {record.status === 'in_review' && actor === record.prepared_by && <p className="text-sm text-slate-400 [overflow-wrap:anywhere]">{text('separateApproval')}</p>}
    {target === 'signed' && <>
      <FormField name="signed_on" label={text('signed_on')} type="date" required disabled={disabled} error={errors.signed_on?.[0]} />
      <FormField name="signature_reference" label={text('signature_reference')} required maxLength={160} disabled={disabled} error={errors.signature_reference?.[0]} />
      <label className="flex min-w-0 items-start gap-2"><input type="checkbox" name="confirm_signature_received" disabled={disabled} className="mt-1 shrink-0" /><span className="[overflow-wrap:anywhere]">{text('confirmSignature')}</span></label>
      <p className="text-sm text-slate-400 [overflow-wrap:anywhere]">{text('signatureHint')}</p>
    </>}
    {target === 'terminated' && <FormField name="terminated_on" label={text('terminated_on')} type="date" required disabled={disabled} error={errors.terminated_on?.[0]} />}
    <Button type="submit" disabled={disabled}>{text('transition')}</Button>
  </form>
}

function ContractHistory({ active, record, onInvalid }: { active: ActiveOrganization; record: ContractRecord; onInvalid: () => void }) {
  const [page, setPage] = useState(1), [list, setList] = useState<Page<ContractEvent> | null>(null), [loading, setLoading] = useState(false)
  const [error, setError] = useState<ReturnType<typeof apiError> | null>(null)
  const path = contractsPath(active.context!.organization_id, record.id) + '/transitions', revision = active.revision ?? undefined
  useEffect(() => {
    const controller = new AbortController()
    const load = async () => {
      setLoading(true); setList(null); setError(null)
      try {
        const result = await tenancyRequest<Page<ContractEvent>>(path + '?per_page=10&page=' + page, { revision, signal: controller.signal })
        if (!controller.signal.aborted) setList(result)
      } catch (failure) {
        if (controller.signal.aborted || isAbortError(failure)) return
        const next = apiError(failure); setError(next); if (shouldReloadContext(next)) onInvalid()
      } finally { if (!controller.signal.aborted) setLoading(false) }
    }
    void load(); return () => controller.abort()
  }, [path, revision, page, onInvalid])
  return <section aria-label={text('history')} className="grid min-w-0 gap-2">
    <h4 className="font-bold">{text('history')}</h4>
    {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
    {list?.data.map((event) => <p key={event.id} className="[overflow-wrap:anywhere]">{event.from_status ? text('status.' + event.from_status) + ' → ' : ''}{text('status.' + event.to_status)} · {new Intl.DateTimeFormat('fr', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(event.occurred_at))}</p>)}
    {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={loading} onPageChange={setPage} />}
  </section>
}
