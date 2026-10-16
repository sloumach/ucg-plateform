import { useCallback, useState, type FormEvent } from 'react'
import type { ActiveOrganization } from '../../api/tenancy'
import type { Sponsor } from '../../api/partnerships'
import { sponsorsPath } from '../../api/partnerships'
import { agreementText as text, agreementsPath, agreementStatuses, type SponsorshipAgreement, type SponsorshipEvent } from '../../api/sponsorshipAgreements'
import { t } from '../../i18n/fr'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { SelectField } from '../people/peopleForms'
import { changedValues } from '../people/peopleFormValues'
import { usePeopleMutation } from '../people/usePeopleMutation'
import { usePartnershipList } from './usePartnershipList'
import { useAgreementRead } from './useAgreementRead'
import { SponsorshipReferenceSelect } from './SponsorshipFields'
import { AgreementExposuresPanel } from './AgreementExposuresPanel'
import { AgreementFinancialPanel } from './AgreementFinancialPanel'
import { AgreementDocumentsPanel } from './AgreementDocumentsPanel'

type Props = { active: ActiveOrganization; sponsor: Sponsor; disabled: boolean; onInvalid: () => void }
export function SponsorshipAgreementsPanel({ active, sponsor, disabled, onInvalid }: Props) {
  const [page, setPage] = useState(1), [refresh, setRefresh] = useState(0), [query, setQuery] = useState(''), [status, setStatus] = useState('')
  const [creating, setCreating] = useState(false), [selected, setSelected] = useState<string | null>(null)
  const path = agreementsPath(active.context!.organization_id, sponsor.id)
  const params = new URLSearchParams({ per_page: '10', page: String(page), ...(query ? { q: query } : {}), ...(status ? { status } : {}) })
  const { list, loading, error } = usePartnershipList<SponsorshipAgreement>(path + '?' + params, active, true, onInvalid, refresh)
  const changed = useCallback(() => setRefresh((value) => value + 1), [])
  const mutation = usePeopleMutation<SponsorshipAgreement>(active, (record) => { setCreating(false); setSelected(record.id); changed() }, onInvalid)
  const writable = sponsor.status !== 'archived' && active.context!.permissions.includes('partnerships.write')
  const blocked = disabled || mutation.blocked
  return <section aria-label={text('title')} className="grid min-w-0 gap-3">
    <h4 className="font-bold">{text('title')}</h4>
    <p className="text-sm text-slate-400">{text('hint')}</p>
    {mutation.feedback}
    <form aria-label={text('search')} className="grid min-w-0 gap-3 sm:grid-cols-2" onSubmit={(event) => {
      event.preventDefault(); const data = new FormData(event.currentTarget); setQuery(String(data.get('q') ?? '').trim()); setStatus(String(data.get('status') ?? '')); setPage(1); setSelected(null); setCreating(false)
    }}>
      <FormField name="q" label={text('search')} maxLength={80} disabled={blocked} />
      <SelectField name="status" title={text('status')} defaultValue="" disabled={blocked}><option value="">{text('all')}</option>{agreementStatuses.map((value) => <option key={value} value={value}>{text('status.' + value)}</option>)}</SelectField>
      <Button type="submit" disabled={blocked}>{t('partnerships.searchAction')}</Button>
    </form>
    {writable && <Button disabled={blocked} onClick={() => { setCreating(true); setSelected(null) }}>{text('new')}</Button>}
    {loading && <p role="status">{text('loading')}</p>}
    {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
    {list?.data.length === 0 && <p>{text('empty')}</p>}
    <ul className="grid min-w-0 gap-3">{list?.data.map((agreement) => <li key={agreement.id} className="grid min-w-0 gap-2 rounded-xl border border-slate-700 p-3">
      <p className="font-bold [overflow-wrap:anywhere]">{agreement.reference} · {agreement.title}</p><p>{text('status.' + agreement.status)}</p>
      <p>{agreement.starts_on} → {agreement.ends_on}</p>
      <Button disabled={blocked} onClick={() => { setCreating(false); setSelected(agreement.id) }}>{text('details')}</Button>
    </li>)}</ul>
    {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={blocked} onPageChange={(next) => { setPage(next); setSelected(null); setCreating(false) }} />}
    {creating && <AgreementForm {...{ active, sponsor, onInvalid }} record={null} errors={mutation.errors(path)} disabled={blocked} onCancel={() => setCreating(false)}
      onSubmit={(event) => { const body = changedValues(event.currentTarget, null); body.responsible_user_id = Number(body.responsible_user_id); mutation.propose(event, text('save'), path, body) }} />}
    {selected && <AgreementDetails key={selected} {...{ active, sponsor, onInvalid, disabled: blocked }} path={path + '/' + selected} refresh={refresh} onChanged={changed} onClose={() => setSelected(null)} />}
  </section>
}

function AgreementDetails({ active, sponsor, path, refresh, disabled, onInvalid, onChanged, onClose }: Props & { path: string; refresh: number; onChanged: () => void; onClose: () => void }) {
  const { data: record, loading, error } = useAgreementRead<SponsorshipAgreement>(path, active, true, onInvalid, refresh)
  const [editing, setEditing] = useState(false), [history, setHistory] = useState(false)
  const mutation = usePeopleMutation(active, () => { setEditing(false); onChanged() }, onInvalid)
  const permissions = active.context!.permissions, blocked = disabled || mutation.blocked
  const writable = sponsor.status !== 'archived' && permissions.includes('partnerships.write')
  const editable = writable && !!record && ['draft', 'approved'].includes(record.status)
  const validate = writable && permissions.includes('partnerships.validate')
  return <section aria-label={text('details')} className="grid min-w-0 gap-4 rounded-xl border border-cyan-400/30 p-4">
    <Button disabled={blocked} onClick={onClose}>{text('close')}</Button>
    {loading && <p role="status">{text('loading')}</p>}
    {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
    {mutation.feedback}
    {record && <>
      <h4 className="font-bold [overflow-wrap:anywhere]">{record.reference} · {record.title} · {text('status.' + record.status)}</h4>
      <p className="[overflow-wrap:anywhere]">{text('responsible_user_id')} : {record.responsible_label ?? text('responsibleUnavailable')}</p>
      {editable && <Button disabled={blocked} onClick={() => setEditing(!editing)}>{text('edit')}</Button>}
      {editing && editable && <AgreementForm key={record.version} {...{ active, sponsor, record, onInvalid }} disabled={blocked} errors={mutation.errors(path)} onCancel={() => setEditing(false)} onSubmit={(event) => {
        const body = changedValues(event.currentTarget, record); if ('responsible_user_id' in body) body.responsible_user_id = Number(body.responsible_user_id)
        mutation.propose(event, text('save'), path, { ...body, version: record.version }, true)
      }} />}
      {editable && record.contract_id && permissions.includes('administrative.read') && <form aria-label={text('rebind')} onSubmit={(event) => mutation.propose(event, text('rebind'), path, { version: record.version, contract_id: record.contract_id }, true)}><Button type="submit" disabled={blocked}>{text('rebind')}</Button></form>}
      <AgreementExposuresPanel key={'exposures:' + record.version} {...{ active, record, onInvalid, onChanged, disabled: blocked, readOnly: sponsor.status === 'archived' }} path={path + '/exposures'} />
      <AgreementFinancialPanel key={'financial:' + record.version} {...{ active, record, onInvalid, onChanged, disabled: blocked, readOnly: sponsor.status === 'archived' }} path={path + '/financial'} />
      <AgreementDocumentsPanel key={'documents:' + record.version} {...{ active, record, onInvalid, onChanged, disabled: blocked }} />
      {validate && record.status === 'draft' && permissions.includes('financial.read') && permissions.includes('financial.validate') && (record.prepared_by === active.context!.actor_user_id ? <p>{text('otherApprover')}</p> : <form aria-label={text('approve')} className="grid min-w-0 gap-3" onSubmit={(event) => mutation.propose(event, text('approve'), path + '/transitions', { version: record.version, target_status: 'approved', confirm_authorizations: new FormData(event.currentTarget).has('confirm_authorizations') })}>
        <label className="flex items-start gap-3"><input type="checkbox" name="confirm_authorizations" disabled={blocked} /><span>{text('confirm_authorizations')}</span></label>
        {mutation.errors(path + '/transitions').confirm_authorizations && <p role="alert">{mutation.errors(path + '/transitions').confirm_authorizations[0]}</p>}
        <Button type="submit" disabled={blocked}>{text('approve')}</Button>
      </form>)}
      {validate && record.status === 'approved' && <form aria-label={text('end')} onSubmit={(event) => mutation.propose(event, text('end'), path + '/transitions', { version: record.version, target_status: 'ended' })}><Button type="submit" disabled={blocked}>{text('end')}</Button></form>}
      {writable && ['draft', 'ended'].includes(record.status) && <form aria-label={text('archive')} onSubmit={(event) => mutation.propose(event, text('archive'), path + '/transitions', { version: record.version, target_status: 'archived' })}><Button type="submit" disabled={blocked}>{text('archive')}</Button></form>}
      <Button disabled={blocked} onClick={() => setHistory(!history)}>{text(history ? 'closeHistory' : 'history')}</Button>
      {history && <AgreementHistory key={record.version} {...{ active, onInvalid, disabled: blocked }} path={path + '/history'} />}
    </>}
  </section>
}

function AgreementForm({ active, sponsor, record, disabled, errors, onSubmit, onCancel, onInvalid }: Props & { record: SponsorshipAgreement | null; errors: Record<string, string[]>; onSubmit: (event: FormEvent<HTMLFormElement>) => void; onCancel: () => void }) {
  const [today] = useState(() => new Date().toLocaleDateString('en-CA'))
  const refs = 'tenants/' + active.context!.organization_id + '/sponsorship-references/'
  return <form noValidate aria-label={text('save')} className="grid min-w-0 gap-3 rounded-xl border border-cyan-400/30 p-3" onSubmit={onSubmit}>
    {!record && <FormField name="reference" label={text('reference')} required maxLength={80} disabled={disabled} error={errors.reference?.[0]} />}
    <FormField name="title" label={text('titleField')} defaultValue={record?.title ?? ''} required maxLength={160} disabled={disabled} error={errors.title?.[0]} />
    {(['starts_on', 'ends_on'] as const).map((field) => <FormField key={field} name={field} label={text(field)} defaultValue={record?.[field] ?? today} type="date" required disabled={disabled} error={errors[field]?.[0]} />)}
    <SponsorshipReferenceSelect {...{ active, disabled, onInvalid }} path={refs + 'members'} name="responsible_user_id" title={text('responsible_user_id')} value={String(record?.responsible_user_id ?? active.context!.actor_user_id)} error={errors.responsible_user_id?.[0]} />
    {!record && <SponsorshipReferenceSelect {...{ active, disabled, onInvalid }} optional path={sponsorsPath(active.context!.organization_id, sponsor.id) + '/opportunities'} name="opportunity_id" title={text('opportunity_id')} error={errors.opportunity_id?.[0]} />}
    {active.context!.permissions.includes('administrative.read') && <SponsorshipReferenceSelect {...{ active, disabled, onInvalid }} optional path={refs + 'contracts'} name="contract_id" title={text('contract_id')} value={record?.contract_id ?? ''} error={errors.contract_id?.[0]} />}
    <div className="flex flex-wrap gap-3"><Button type="submit" disabled={disabled}>{text('save')}</Button><Button disabled={disabled} onClick={onCancel}>{t('tenant.cancel')}</Button></div>
  </form>
}

function AgreementHistory({ active, path, disabled, onInvalid }: { active: ActiveOrganization; path: string; disabled: boolean; onInvalid: () => void }) {
  const [page, setPage] = useState(1)
  const { list, loading, error } = usePartnershipList<SponsorshipEvent>(path + '?per_page=10&page=' + page, active, true, onInvalid)
  return <section aria-label={text('history')} className="grid min-w-0 gap-3">
    {loading && <p role="status">{text('loading')}</p>}
    {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
    {list?.data.length === 0 && <p>{text('empty')}</p>}
    <ul className="grid min-w-0 gap-2">{list?.data.map((event) => <li key={event.id} className="[overflow-wrap:anywhere]">{text('action.' + event.action)} · {text('status.' + event.to_status)} · {new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'short', timeZone: active.context!.timezone }).format(new Date(event.occurred_at))}</li>)}</ul>
    {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={disabled} onPageChange={setPage} />}
  </section>
}
