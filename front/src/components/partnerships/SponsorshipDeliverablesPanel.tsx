import { useState } from 'react'
import type { ActiveOrganization } from '../../api/tenancy'
import type { SponsorshipAgreement } from '../../api/sponsorshipAgreements'
import { campaignText as text, campaignDate, deliverableKinds, deliverableStatuses, type SponsorshipCampaign, type SponsorshipDeliverable } from '../../api/sponsorshipCampaigns'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { SelectField } from '../people/peopleForms'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { changedValues, isoDate, localDate } from '../people/peopleFormValues'
import { usePeopleMutation } from '../people/usePeopleMutation'
import { usePartnershipList } from './usePartnershipList'
import { SponsorshipReferenceSelect, SponsorshipTextArea } from './SponsorshipFields'

type Props = { active: ActiveOrganization; agreement: SponsorshipAgreement; campaign: SponsorshipCampaign; path: string; disabled: boolean; readOnly: boolean; onInvalid: () => void; onChanged: () => void }
export function SponsorshipDeliverablesPanel({ active, agreement, campaign, path, disabled, readOnly, onInvalid, onChanged }: Props) {
  const [page, setPage] = useState(1), [status, setStatus] = useState(''), [editor, setEditor] = useState<SponsorshipDeliverable | 'new' | null>(null)
  const [delivery, setDelivery] = useState<SponsorshipDeliverable | null>(null)
  const [deliveryOpenedAt, setDeliveryOpenedAt] = useState('')
  const params = new URLSearchParams({ per_page: '10', page: String(page), ...(status ? { status } : {}) })
  const { list, loading, error } = usePartnershipList<SponsorshipDeliverable>(path + '?' + params, active, true, onInvalid)
  const mutation = usePeopleMutation(active, onChanged, onInvalid), permissions = active.context!.permissions
  const writable = !readOnly && permissions.includes('partnerships.write') && campaign.status === 'active' && agreement.status !== 'archived'
  const editable = writable && ['draft', 'approved'].includes(agreement.status), validator = writable && permissions.includes('partnerships.validate')
  const blocked = disabled || mutation.blocked, timezone = active.context!.timezone
  const versions = (record: SponsorshipDeliverable) => ({ agreement_version: agreement.version, campaign_version: campaign.version, version: record.version })
  return <section aria-label={text('deliverables')} className="grid min-w-0 gap-3">
    <h5 className="font-bold">{text('deliverables')}</h5>
    {mutation.feedback}
    <SelectField name="deliverable_status_filter" title={text('filter')} value={status} onChange={(value) => { setStatus(value); setPage(1); setEditor(null); setDelivery(null) }} disabled={blocked}>
      <option value="">{text('all')}</option>{deliverableStatuses.map((value) => <option key={value} value={value}>{text('status.' + value)}</option>)}
    </SelectField>
    {loading && <p role="status">{text('loading')}</p>}{error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
    {list?.data.length === 0 && <p>{text('noDeliverables')}</p>}
    {editable && <Button disabled={blocked} onClick={() => { setEditor('new'); setDelivery(null) }}>{text('newDeliverable')}</Button>}
    <ul className="grid min-w-0 gap-3">{list?.data.map((record) => {
      const own = [record.prepared_by, record.responsible_user_id, record.delivered_by].includes(active.context!.actor_user_id)
      const transition = (event: React.FormEvent<HTMLFormElement>, target: string) => mutation.propose(event, text(target === 'promised' ? 'reopen' : target === 'cancelled' ? 'cancelDeliverable' : 'validate'), path + '/' + record.id + '/transitions', {
        ...versions(record), target_status: target, ...(target === 'validated' ? { confirm_evidence: new FormData(event.currentTarget).has('confirm_evidence') } : {}),
      })
      return <li key={record.id} className="grid min-w-0 gap-2 rounded-xl border border-slate-700 p-3">
        <p className="font-bold [overflow-wrap:anywhere]">{record.title} · {text('status.' + record.status)}</p>
        <p>{text('kind.' + record.kind)}</p>
        <p className="[overflow-wrap:anywhere]">{text('responsible')} : {record.responsible_label ?? text('unavailable')}</p>
        <p>{text('due_at')} : {campaignDate(record.due_at, timezone)}</p>
        {record.overdue && <p className="font-semibold text-rose-200">{text('overdue')}</p>}
        {record.delivered_at && <p>{text('delivered_at')} : {campaignDate(record.delivered_at, timezone)}{record.delivered_late && ' · ' + text('deliveredLate')}</p>}
        {record.proof_reference && <p className="whitespace-pre-wrap [overflow-wrap:anywhere]">{text('proof_reference')} : {record.proof_reference}</p>}
        {record.validated_at && <p>{text('validatedAt')} : {campaignDate(record.validated_at, timezone)}</p>}
        {record.status === 'validated' && record.validation_matches_agreement === false && <p className="text-amber-200">{text('oldValidation')}</p>}
        {editable && record.status === 'promised' && <Button disabled={blocked} onClick={() => { setEditor(record); setDelivery(null) }}>{text('editDeliverable')}</Button>}
        {writable && record.status === 'promised' && <Button disabled={blocked} onClick={() => { setDeliveryOpenedAt(new Date().toISOString()); setDelivery(record); setEditor(null) }}>{text('deliver')}</Button>}
        {validator && record.status === 'delivered' && (own ? <p>{text('independent')}</p> : agreement.status === 'approved' ? <form aria-label={text('validate')} className="grid min-w-0 gap-2" onSubmit={(event) => transition(event, 'validated')}>
          <label className="flex items-start gap-3"><input type="checkbox" name="confirm_evidence" disabled={blocked} /><span>{text('confirmEvidence')}</span></label>
          {mutation.errors(path + '/' + record.id + '/transitions').confirm_evidence && <p role="alert">{mutation.errors(path + '/' + record.id + '/transitions').confirm_evidence[0]}</p>}
          <Button type="submit" disabled={blocked}>{text('validate')}</Button>
        </form> : <p>{text('approvalRequired')}</p>)}
        {validator && ['delivered', 'validated'].includes(record.status) && <form aria-label={text('reopen')} onSubmit={(event) => transition(event, 'promised')}><Button type="submit" disabled={blocked}>{text('reopen')}</Button></form>}
        {writable && ['promised', 'delivered'].includes(record.status) && <form aria-label={text('cancelDeliverable')} onSubmit={(event) => transition(event, 'cancelled')}><Button type="submit" disabled={blocked}>{text('cancelDeliverable')}</Button></form>}
      </li>
    })}</ul>
    {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={blocked} onPageChange={(next) => { setPage(next); setEditor(null); setDelivery(null) }} />}
    {editor && <PromiseForm key={editor === 'new' ? 'new' : editor.id} {...{ active, campaign, path, onInvalid, disabled: blocked }} record={editor === 'new' ? null : editor}
      errors={mutation.errors(path + (editor === 'new' ? '' : '/' + editor.id))} onCancel={() => setEditor(null)} onSubmit={(event) => {
        const record = editor === 'new' ? null : editor, body = changedValues(event.currentTarget, record, ['due_at'])
        if ('responsible_user_id' in body) body.responsible_user_id = Number(body.responsible_user_id)
        mutation.propose(event, text('saveDeliverable'), path + (record ? '/' + record.id : ''), { ...body, agreement_version: agreement.version, campaign_version: campaign.version, ...(record ? { version: record.version } : {}) }, !!record)
      }} />}
    {delivery && <form noValidate aria-label={text('recordDelivery')} className="grid min-w-0 gap-3 rounded-xl border border-amber-400/30 p-3" onSubmit={(event) => {
      const data = new FormData(event.currentTarget)
      mutation.propose(event, text('recordDelivery'), path + '/' + delivery.id + '/deliveries', { ...versions(delivery), delivered_at: isoDate(String(data.get('delivered_at') ?? '')), proof_reference: String(data.get('proof_reference') ?? '') })
    }}>
      <FormField name="delivered_at" label={text('delivered_at')} type="datetime-local" step="1" defaultValue={localDate(deliveryOpenedAt)} required disabled={blocked} error={mutation.errors(path + '/' + delivery.id + '/deliveries').delivered_at?.[0]} hint={text('inputTimezone') + ' : ' + Intl.DateTimeFormat().resolvedOptions().timeZone} />
      <SponsorshipTextArea name="proof_reference" title={text('proof_reference')} required disabled={blocked} error={mutation.errors(path + '/' + delivery.id + '/deliveries').proof_reference?.[0]} />
      <p className="text-sm text-slate-400">{text('proofHint')}</p>
      <div className="flex flex-wrap gap-3"><Button type="submit" disabled={blocked}>{text('recordDelivery')}</Button><Button disabled={blocked} onClick={() => setDelivery(null)}>{text('cancel')}</Button></div>
    </form>}
  </section>
}
function PromiseForm({ active, campaign, path, record, disabled, errors, onInvalid, onSubmit, onCancel }: {
  active: ActiveOrganization; campaign: SponsorshipCampaign; path: string; record: SponsorshipDeliverable | null; disabled: boolean; errors: Record<string, string[]>; onInvalid: () => void; onSubmit: (event: React.FormEvent<HTMLFormElement>) => void; onCancel: () => void
}) {
  const exposurePath = path.slice(0, path.lastIndexOf('/campaigns/')) + '/exposures'
  const [defaultDue] = useState(() => new Date().toISOString())
  return <form noValidate aria-label={text('saveDeliverable')} className="grid min-w-0 gap-3" onSubmit={onSubmit}>
    <FormField name="title" label={text('deliverableTitle')} defaultValue={record?.title ?? ''} required maxLength={160} disabled={disabled} error={errors.title?.[0]} />
    <SelectField name="kind" title={text('kind')} defaultValue={record?.kind ?? 'publication'} disabled={disabled} error={errors.kind?.[0]}>
      {deliverableKinds.map((kind) => <option key={kind} value={kind}>{text('kind.' + kind)}</option>)}
    </SelectField>
    {!record && <SponsorshipReferenceSelect {...{ active, disabled, onInvalid }} path={exposurePath} filters={{ status: 'active' }} name="exposure_id" title={text('scope')} error={errors.exposure_id?.[0]} />}
    <SponsorshipReferenceSelect {...{ active, disabled, onInvalid }} path={'tenants/' + active.context!.organization_id + '/sponsorship-references/members'} name="responsible_user_id" title={text('responsible')} value={String(record?.responsible_user_id ?? campaign.responsible_user_id)} error={errors.responsible_user_id?.[0]} />
    <FormField name="due_at" label={text('due_at')} type="datetime-local" step="1" defaultValue={localDate(record?.due_at ?? defaultDue)} required disabled={disabled} error={errors.due_at?.[0]} hint={text('inputTimezone') + ' : ' + Intl.DateTimeFormat().resolvedOptions().timeZone} />
    <div className="flex flex-wrap gap-3"><Button type="submit" disabled={disabled}>{text('saveDeliverable')}</Button><Button disabled={disabled} onClick={onCancel}>{text('cancel')}</Button></div>
  </form>
}
