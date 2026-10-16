import { useState, type FormEvent } from 'react'
import type { ActiveOrganization } from '../../api/tenancy'
import { agreementText as text, exposureChannels, type SponsorshipAgreement, type SponsorshipExposure } from '../../api/sponsorshipAgreements'
import { t } from '../../i18n/fr'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { SelectField } from '../people/peopleForms'
import { changedValues } from '../people/peopleFormValues'
import { usePeopleMutation } from '../people/usePeopleMutation'
import { usePartnershipList } from './usePartnershipList'
import { SponsorshipReferenceSelect, SponsorshipTextArea } from './SponsorshipFields'

type Props = { active: ActiveOrganization; record: SponsorshipAgreement; path: string; disabled: boolean; readOnly?: boolean; onChanged: () => void; onInvalid: () => void }
export function AgreementExposuresPanel({ active, record, path, disabled, readOnly = false, onChanged, onInvalid }: Props) {
  const [page, setPage] = useState(1), [editor, setEditor] = useState<SponsorshipExposure | 'new' | null>(null)
  const { list, loading, error } = usePartnershipList<SponsorshipExposure>(path + '?per_page=10&page=' + page, active, true, onInvalid)
  const mutation = usePeopleMutation(active, onChanged, onInvalid)
  const writable = !readOnly && active.context!.permissions.includes('partnerships.write') && ['draft', 'approved'].includes(record.status)
  const blocked = disabled || mutation.blocked
  return <section aria-label={text('exposures')} className="grid min-w-0 gap-3 rounded-xl border border-white/10 p-3">
    <h4 className="font-bold">{text('exposures')}</h4>
    <p className="text-sm text-slate-400">{text('exclusiveHint')}</p>
    {mutation.feedback}
    {loading && <p role="status">{text('loading')}</p>}
    {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
    {list?.data.length === 0 && <p>{text('empty')}</p>}
    {writable && <Button disabled={blocked} onClick={() => setEditor('new')}>{text('newExposure')}</Button>}
    <ul className="grid min-w-0 gap-3">{list?.data.map((exposure) => <li key={exposure.id} className="grid min-w-0 gap-2 rounded-xl border border-slate-700 p-3">
      <p className="font-bold [overflow-wrap:anywhere]">{exposure.target_label} · {text('channel.' + exposure.channel)}</p>
      {exposure.description && <p className="whitespace-pre-wrap [overflow-wrap:anywhere]">{exposure.description}</p>}
      <p className="[overflow-wrap:anywhere]">{text('authorization_reference')} : {exposure.authorization_reference}</p>
      {exposure.exclusivity_key && <p className="[overflow-wrap:anywhere]">{text('exclusivity_key')} : {exposure.exclusivity_key}</p>}
      <p className={exposure.exposure_authorized ? 'text-emerald-300' : 'text-amber-200'}>{text(exposure.status === 'archived' ? 'archivedExposure' : exposure.exposure_authorized ? 'authorized' : 'notAuthorized')}</p>
      {writable && exposure.status === 'active' && <>
        <Button disabled={blocked} onClick={() => setEditor(exposure)}>{text('editExposure')}</Button>
        <form aria-label={text('archiveExposure')} onSubmit={(event) => mutation.propose(event, text('archiveExposure'), path + '/' + exposure.id, { version: exposure.version, agreement_version: record.version, status: 'archived' }, true)}>
          <Button type="submit" disabled={blocked}>{text('archiveExposure')}</Button>
        </form>
      </>}
    </li>)}</ul>
    {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={blocked} onPageChange={setPage} />}
    {editor && <ExposureForm key={editor === 'new' ? 'new' : editor.id} {...{ active, disabled: blocked, onInvalid }} exposure={editor === 'new' ? null : editor}
      errors={mutation.errors(path + (editor === 'new' ? '' : '/' + editor.id))} onCancel={() => setEditor(null)} onSubmit={(event) => {
        const exposure = editor === 'new' ? null : editor
        mutation.propose(event, text('saveExposure'), path + (exposure ? '/' + exposure.id : ''), { ...changedValues(event.currentTarget, exposure), agreement_version: record.version, ...(exposure ? { version: exposure.version } : {}) }, !!exposure)
      }} />}
  </section>
}

function ExposureForm({ active, exposure, disabled, errors, onInvalid, onCancel, onSubmit }: {
  active: ActiveOrganization; exposure: SponsorshipExposure | null; disabled: boolean; errors: Record<string, string[]>; onInvalid: () => void; onCancel: () => void; onSubmit: (event: FormEvent<HTMLFormElement>) => void
}) {
  const [type, setType] = useState('organization')
  const refs = 'tenants/' + active.context!.organization_id + '/sponsorship-references/'
  return <form noValidate aria-label={text('saveExposure')} className="grid min-w-0 gap-3" onSubmit={onSubmit}>
    {!exposure && <>
      <SelectField name="target_type" title={text('target_type')} value={type} onChange={setType} disabled={disabled} error={errors.target_type?.[0]}>
        {(['organization', 'team', 'player'] as const).map((target) => <option key={target} value={target}>{text('target.' + target)}</option>)}
      </SelectField>
      {type === 'organization' ? <input type="hidden" name="target_id" value={active.context!.organization_id} /> : <SponsorshipReferenceSelect key={type} active={active} path={refs + (type === 'team' ? 'teams' : 'players')} name="target_id" title={text('target_id')} disabled={disabled} error={errors.target_id?.[0]} onInvalid={onInvalid} />}
    </>}
    <SelectField name="channel" title={text('channel')} defaultValue={exposure?.channel ?? 'website'} disabled={disabled} error={errors.channel?.[0]}>
      {exposureChannels.map((channel) => <option key={channel} value={channel}>{text('channel.' + channel)}</option>)}
    </SelectField>
    <SponsorshipTextArea name="description" title={text('description')} value={exposure?.description ?? ''} disabled={disabled} error={errors.description?.[0]} />
    <FormField name="authorization_reference" label={text('authorization_reference')} defaultValue={exposure?.authorization_reference ?? ''} required maxLength={160} disabled={disabled} error={errors.authorization_reference?.[0]} />
    <FormField name="exclusivity_key" label={text('exclusivity_key')} defaultValue={exposure?.exclusivity_key ?? ''} maxLength={80} disabled={disabled} error={errors.exclusivity_key?.[0]} />
    <div className="flex flex-wrap gap-3"><Button type="submit" disabled={disabled}>{text('saveExposure')}</Button><Button disabled={disabled} onClick={onCancel}>{t('tenant.cancel')}</Button></div>
  </form>
}
