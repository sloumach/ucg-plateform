import { useId, useState, type FormEvent } from 'react'
import { communicationText as text, type Announcement } from '../../api/communication'
import { t } from '../../i18n/fr'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { Pagination } from '../data/Pagination'
import { SelectField } from '../people/peopleForms'
import { isoDate, localDate } from '../people/peopleFormValues'
import { usePartnershipList } from '../partnerships/usePartnershipList'
import type { CommunicationProps } from './CommunicationPanel'
import { CommunicationLoadState } from './CommunicationViews'
type Errors = Record<string, string[]>
export function CommunicationForm({ active, onInvalid, root, item, disabled, errors, onSubmit, onCancel }: CommunicationProps & {
  root: string; item: Announcement | null; errors: Errors; onSubmit: (event: FormEvent<HTMLFormElement>, values: Record<string, unknown>) => void; onCancel: () => void
}) {
  const [type, setType] = useState(item?.target_type ?? 'organization')
  return <form aria-label={text('save')} noValidate className="grid min-w-0 gap-3 rounded-xl border border-cyan-400/30 p-3" onSubmit={(event) => {
    event.preventDefault(); const values = new FormData(event.currentTarget)
    onSubmit(event, { title: String(values.get('title') ?? '').trim(), body: String(values.get('body') ?? '').trim(), target_type: type,
      target_id: type === 'organization' ? null : String(values.get('target_id') ?? ''),
      expires_at: isoDate(String(values.get('expires_at') ?? '')), comments_enabled: values.has('comments_enabled') })
  }}>
    <FormField name="title" label={text('name')} defaultValue={item?.title ?? ''} required maxLength={160} disabled={disabled} error={errors.title?.[0]} />
    <MessageField defaultValue={item?.body ?? ''} maxLength={12000} disabled={disabled} error={errors.body?.[0]} />
    <SelectField name="target_type" title={text('targetType')} value={type} onChange={(next) => setType(next as Announcement['target_type'])} disabled={disabled} error={errors.target_type?.[0]}>
      {['organization', 'team', 'game', 'role'].map((key) => <option key={key} value={key}>{text('target.' + key)}</option>)}
    </SelectField>
    <p className="text-sm text-slate-400">{text('targetHint')}</p>
    {type !== 'organization' && <TargetSelect key={type} {...{ active, root, type, onInvalid, disabled }} value={item?.target_type === type ? item.target_id ?? '' : ''} error={errors.target_id?.[0]} />}
    <FormField name="expires_at" label={text('expiresLocal')} type="datetime-local" step="1" defaultValue={localDate(item?.expires_at)} disabled={disabled} error={errors.expires_at?.[0]} />
    <FormField name="comments_enabled" label={text('enableComments')} type="checkbox" defaultChecked={item?.comments_enabled ?? true} disabled={disabled} error={errors.comments_enabled?.[0]} />
    <div className="flex flex-wrap gap-3"><Button type="submit" disabled={disabled}>{text('save')}</Button><Button disabled={disabled} onClick={onCancel}>{t('tenant.cancel')}</Button></div>
  </form>
}
function TargetSelect({ active, root, type, onInvalid, disabled, value, error }: CommunicationProps & { root: string; type: string; value: string; error?: string }) {
  const [page, setPage] = useState(1), [selected, setSelected] = useState(value)
  const { list, loading, error: failure } = usePartnershipList<{ id: string; label: string }>(root + '/targets?type=' + type + '&per_page=10&page=' + page, active, true, onInvalid)
  return <div className="grid min-w-0 gap-2">
    <SelectField name="target_id" title={text('target')} value={selected} onChange={setSelected} disabled={disabled} error={error}>
      <option value="">{text('chooseTarget')}</option>
      {selected && !list?.data.some((target) => target.id === selected) && <option value={selected}>{text('currentTarget')}</option>}
      {list?.data.map((target) => <option key={target.id} value={target.id}>{target.label}</option>)}
    </SelectField>
    <CommunicationLoadState loading={loading} error={failure} />
    {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={disabled || loading} onPageChange={setPage} />}
  </div>
}
export function PublishForm({ disabled, errors, onSubmit }: { disabled: boolean; errors: Errors; onSubmit: (event: FormEvent<HTMLFormElement>, notify: boolean) => void }) {
  const [confirmed, setConfirmed] = useState(false)
  return <form aria-label={text('action.publish')} className="grid min-w-0 gap-3" onSubmit={(event) => {
    event.preventDefault(); if (confirmed) onSubmit(event, new FormData(event.currentTarget).has('notify_members'))
  }}>
    <p className="text-sm text-amber-200">{text('publishHint')}</p>
    <FormField name="confirm_publication" label={text('confirmPublication')} type="checkbox" checked={confirmed} onChange={(event) => setConfirmed(event.target.checked)} disabled={disabled} error={errors.confirm_publication?.[0]} />
    <FormField name="notify_members" label={text('notifyAnnouncements')} type="checkbox" disabled={disabled} error={errors.notify_members?.[0]} />
    <Button type="submit" disabled={disabled || !confirmed}>{text('action.publish')}</Button>
  </form>
}
export function MessageField({ defaultValue = '', disabled, error, maxLength = 4000 }: { defaultValue?: string; disabled: boolean; error?: string; maxLength?: number }) {
  const id = useId()
  return <div className="grid min-w-0 gap-2">
    <label htmlFor={id}>{text('body')}</label>
    <textarea id={id} name="body" defaultValue={defaultValue} required maxLength={maxLength} rows={5} disabled={disabled}
      aria-invalid={error ? true : undefined} aria-describedby={error ? id + '-error' : undefined}
      className="min-w-0 w-full rounded-xl border border-white/15 bg-slate-900 px-4 py-3 text-slate-100" />
    {error && <p id={id + '-error'} className="text-sm text-rose-200">{error}</p>}
  </div>
}
