import { useCallback, useState, type FormEvent } from 'react'
import type { ActiveOrganization } from '../../api/tenancy'
import type { SponsorshipAgreement } from '../../api/sponsorshipAgreements'
import { campaignText as text, type SponsorshipCampaign, type SponsorshipCampaignEvent, campaignDate } from '../../api/sponsorshipCampaigns'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { changedValues } from '../people/peopleFormValues'
import { usePeopleMutation } from '../people/usePeopleMutation'
import { SponsorshipReferenceSelect } from './SponsorshipFields'
import { usePartnershipList } from './usePartnershipList'
import { useAgreementRead } from './useAgreementRead'
import { SponsorshipDeliverablesPanel } from './SponsorshipDeliverablesPanel'

type Props = { active: ActiveOrganization; agreement: SponsorshipAgreement; path: string; disabled: boolean; readOnly: boolean; onInvalid: () => void; onChanged?: () => void }
export function SponsorshipCampaignsPanel({ active, agreement, path, disabled, readOnly, onInvalid, onChanged }: Props) {
  const [open, setOpen] = useState(false), [page, setPage] = useState(1), [refresh, setRefresh] = useState(0)
  const [creating, setCreating] = useState(false), [selected, setSelected] = useState<string | null>(null)
  const changed = useCallback(() => { setRefresh((n) => n + 1); onChanged?.() }, [onChanged])
  const { list, loading, error } = usePartnershipList<SponsorshipCampaign>(path + '?per_page=10&page=' + page, active, open, onInvalid, refresh)
  const mutation = usePeopleMutation<SponsorshipCampaign>(active, (record) => { setCreating(false); setSelected(record.id); changed() }, onInvalid)
  const writable = !readOnly && active.context!.permissions.includes('partnerships.write') && ['draft', 'approved'].includes(agreement.status)
  const blocked = disabled || mutation.blocked
  return <section aria-label={text('title')} className="grid min-w-0 gap-3 rounded-xl border border-cyan-400/30 p-2 sm:p-3">
    <h4 className="font-bold">{text('title')}</h4>
    <p className="text-sm text-slate-400">{text('hint')}</p>
    <Button disabled={blocked} onClick={() => { setOpen(!open); setCreating(false); setSelected(null) }}>{text(open ? 'close' : 'open')}</Button>
    {open && <>
      {mutation.feedback}
      {loading && <p role="status">{text('loading')}</p>}
      {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
      {list?.data.length === 0 && <p>{text('empty')}</p>}
      {writable && <Button disabled={blocked} onClick={() => { setCreating(true); setSelected(null) }}>{text('new')}</Button>}
      <ul className="grid min-w-0 gap-3">{list?.data.map((campaign) => <li key={campaign.id} className="grid min-w-0 gap-2 rounded-xl border border-slate-700 p-3">
        <p className="font-bold [overflow-wrap:anywhere]">{campaign.title} · {text('campaign.' + campaign.status)}</p>
        <p>{campaign.starts_on} → {campaign.ends_on}</p>
        <Button disabled={blocked} onClick={() => { setCreating(false); setSelected(campaign.id) }}>{text('details')}</Button>
      </li>)}</ul>
      {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={blocked} onPageChange={(next) => { setPage(next); setSelected(null); setCreating(false) }} />}
      {creating && <CampaignForm {...{ active, agreement, onInvalid, disabled: blocked }} record={null} errors={mutation.errors(path)} onCancel={() => setCreating(false)}
        onSubmit={(event) => { const body = changedValues(event.currentTarget, null); body.responsible_user_id = Number(body.responsible_user_id); mutation.propose(event, text('save'), path, { ...body, agreement_version: agreement.version }) }} />}
      {selected && <CampaignDetails key={selected} {...{ active, agreement, readOnly, onInvalid, disabled: blocked }} path={path + '/' + selected} refresh={refresh} onChanged={changed} />}
    </>}
  </section>
}
function CampaignDetails({ active, agreement, path, disabled, readOnly, onInvalid, onChanged, refresh }: Props & { refresh: number; onChanged: () => void }) {
  const { data: record, loading, error } = useAgreementRead<SponsorshipCampaign>(path, active, true, onInvalid, refresh)
  const [editing, setEditing] = useState(false), [history, setHistory] = useState(false)
  const mutation = usePeopleMutation(active, () => { setEditing(false); onChanged() }, onInvalid)
  const writable = !readOnly && active.context!.permissions.includes('partnerships.write') && record?.status === 'active'
  const editable = writable && ['draft', 'approved'].includes(agreement.status), blocked = disabled || mutation.blocked
  return <section aria-label={text('details')} className="grid min-w-0 gap-3">
    {mutation.feedback}
    {loading && <p role="status">{text('loading')}</p>}
    {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
    {record && <>
      <h5 className="font-bold [overflow-wrap:anywhere]">{record.title} · {text('campaign.' + record.status)}</h5>
      <p className="[overflow-wrap:anywhere]">{text('responsible')} : {record.responsible_label ?? text('unavailable')}</p>
      {editable && <Button disabled={blocked} onClick={() => setEditing(!editing)}>{text('edit')}</Button>}
      {editing && editable && <CampaignForm key={'edit:' + record.version} {...{ active, agreement, record, onInvalid, disabled: blocked }} errors={mutation.errors(path)} onCancel={() => setEditing(false)}
        onSubmit={(event) => { const body = changedValues(event.currentTarget, record); if ('responsible_user_id' in body) body.responsible_user_id = Number(body.responsible_user_id)
          mutation.propose(event, text('save'), path, { ...body, version: record.version, agreement_version: agreement.version }, true) }} />}
      <SponsorshipDeliverablesPanel key={'deliverables:' + record.version} {...{ active, agreement, campaign: record, path: path + '/deliverables', disabled: blocked, readOnly, onInvalid, onChanged }} />
      {writable && <form aria-label={text('archive')} onSubmit={(event) => mutation.propose(event, text('archive'), path, { version: record.version, agreement_version: agreement.version, status: 'archived' }, true)}>
        <Button disabled={blocked} type="submit">{text('archive')}</Button>
      </form>}
      <Button disabled={blocked} onClick={() => setHistory(!history)}>{text(history ? 'closeHistory' : 'history')}</Button>
      {history && <CampaignHistory key={'history:' + record.version} {...{ active, onInvalid, disabled: blocked }} path={path + '/history'} />}
    </>}
  </section>
}
function CampaignForm({ active, agreement, record, disabled, errors, onInvalid, onSubmit, onCancel }: {
  active: ActiveOrganization; agreement: SponsorshipAgreement; record: SponsorshipCampaign | null; disabled: boolean; errors: Record<string, string[]>; onInvalid: () => void; onSubmit: (event: FormEvent<HTMLFormElement>) => void; onCancel: () => void
}) {
  return <form noValidate aria-label={text('save')} className="grid min-w-0 gap-3" onSubmit={onSubmit}>
    <FormField name="title" label={text('campaignTitle')} defaultValue={record?.title ?? ''} required maxLength={160} disabled={disabled} error={errors.title?.[0]} />
    {(['starts_on', 'ends_on'] as const).map((field) => <FormField key={field} name={field} label={text(field)} type="date" defaultValue={record?.[field] ?? agreement[field]} required disabled={disabled} error={errors[field]?.[0]} />)}
    <SponsorshipReferenceSelect {...{ active, disabled, onInvalid }} path={'tenants/' + active.context!.organization_id + '/sponsorship-references/members'} name="responsible_user_id" title={text('responsible')} value={String(record?.responsible_user_id ?? active.context!.actor_user_id)} error={errors.responsible_user_id?.[0]} />
    <div className="flex flex-wrap gap-3"><Button type="submit" disabled={disabled}>{text('save')}</Button><Button disabled={disabled} onClick={onCancel}>{text('cancel')}</Button></div>
  </form>
}
function CampaignHistory({ active, path, disabled, onInvalid }: { active: ActiveOrganization; path: string; disabled: boolean; onInvalid: () => void }) {
  const [page, setPage] = useState(1)
  const { list, loading, error } = usePartnershipList<SponsorshipCampaignEvent>(path + '?per_page=10&page=' + page, active, true, onInvalid)
  return <section aria-label={text('history')} className="grid min-w-0 gap-2">
    {loading && <p role="status">{text('loading')}</p>}{error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
    <ul className="grid gap-2">{list?.data.map((event) => <li key={event.id} className="[overflow-wrap:anywhere]">{text('action.' + event.action)} · {campaignDate(event.occurred_at, active.context!.timezone)}</li>)}</ul>
    {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={disabled} onPageChange={setPage} />}
  </section>
}
