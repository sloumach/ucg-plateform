import { useState, type FormEvent } from 'react'
import type { ActiveOrganization } from '../../api/tenancy'
import { mediaKinds, mediaStatuses, mediaVisibilities, mediaText as text, safeMediaUrl, type MediaItem } from '../../api/media'
import { t } from '../../i18n/fr'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { AsyncState } from '../feedback/AsyncState'
import { SelectField } from '../people/peopleForms'
import { changedValues, isoDate, localDate } from '../people/peopleFormValues'
import { usePeopleMutation } from '../people/usePeopleMutation'
import { usePartnershipList } from '../partnerships/usePartnershipList'

type Props = { active: ActiveOrganization; disabled: boolean; onInvalid: () => void }
export function MediaPanel({ active, disabled, onInvalid }: Props) {
  const [open, setOpen] = useState(false), [page, setPage] = useState(1), [refresh, setRefresh] = useState(0)
  const [filters, setFilters] = useState({ q: '', kind: '', status: '', archived: '' })
  const [editor, setEditor] = useState<MediaItem | 'new' | null>(null)
  const permissions = active.context?.permissions ?? [], canRead = permissions.includes('media.read'), canWrite = permissions.includes('media.write')
  const root = 'tenants/' + active.context!.organization_id + '/media'
  const params = new URLSearchParams({ page: String(page), per_page: '10', ...Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '')) })
  const { list, loading, error } = usePartnershipList<MediaItem>(root + '?' + params, active, open && canRead, onInvalid, refresh)
  const mutation = usePeopleMutation<MediaItem>(active, () => { setEditor(null); setRefresh((n) => n + 1) }, onInvalid)
  const blocked = disabled || mutation.blocked
  if (!canRead) return null
  return <section aria-label={text('title')} className="grid min-w-0 gap-4 rounded-2xl border border-white/10 p-4">
    <h2 className="text-xl font-bold">{text('title')}</h2>
    <p className="text-sm text-slate-400">{text('hint')}</p>
    <Button disabled={blocked} onClick={() => { setOpen(!open); setEditor(null) }}>{text(open ? 'close' : 'open')}</Button>
    {open && <>
      {mutation.feedback}
      <form aria-label={text('search')} className="grid min-w-0 gap-3 sm:grid-cols-2" onSubmit={(event) => {
        event.preventDefault(); const values = new FormData(event.currentTarget)
        setFilters({ q: String(values.get('q') ?? '').trim(), kind: String(values.get('kind') ?? ''), status: String(values.get('status') ?? ''), archived: String(values.get('archived') ?? '') })
        setPage(1); setEditor(null)
      }}>
        <FormField name="q" label={text('search')} maxLength={80} disabled={blocked} />
        <SelectField name="kind" title={text('kind')} defaultValue="" disabled={blocked}><option value="">{text('all')}</option>{mediaKinds.map((kind) => <option key={kind} value={kind}>{text('kind.' + kind)}</option>)}</SelectField>
        <SelectField name="status" title={text('status')} defaultValue="" disabled={blocked}><option value="">{text('all')}</option>{mediaStatuses.map((status) => <option key={status} value={status}>{text('status.' + status)}</option>)}</SelectField>
        {canWrite && <SelectField name="archived" title={text('archiveFilter')} defaultValue="" disabled={blocked}><option value="">{text('active')}</option><option value="1">{text('archived')}</option></SelectField>}
        <div className="flex flex-wrap gap-3 sm:col-span-2"><Button type="submit" disabled={blocked}>{text('searchAction')}</Button><Button disabled={blocked} onClick={() => setRefresh((n) => n + 1)}>{text('refresh')}</Button></div>
      </form>
      {canWrite && <Button disabled={blocked} onClick={() => setEditor('new')}>{text('new')}</Button>}
      {loading && <AsyncState variant="loading" title={text('loading')} />}
      {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
      {list?.data.length === 0 && <p>{text('empty')}</p>}
      <ul className="grid min-w-0 gap-3">{list?.data.map((item) => <li key={item.id} className="grid min-w-0 gap-2 rounded-xl border border-slate-700 p-3">
        <h3 className="font-bold [overflow-wrap:anywhere]">{item.title}</h3>
        <p className="[overflow-wrap:anywhere]">{text('kind.' + item.kind)} · {item.provider === 'youtube' ? 'YouTube' : 'Twitch'} · {item.team_label ?? text('teamUnavailable')}</p>
        <p>{text('status.' + item.status)} · {text('manual')}</p>
        <p className="text-sm text-slate-400">{text('observed')} : {date(item.status_observed_at, active)}</p>
        <p>{text('visibility.' + item.effective_visibility)}{item.is_scrim && ' · ' + text('scrim')}</p>
        {item.publish_at && <p>{text('publish_at')} : {date(item.publish_at, active)}</p>}
        {item.archived_at && <p>{text('archived')}</p>}
        {!item.archived_at && safeMediaUrl(item.url) && <a className="w-fit text-cyan-300 underline [overflow-wrap:anywhere]" href={safeMediaUrl(item.url)!} target="_blank" rel="noopener noreferrer" referrerPolicy="no-referrer">{text('external')}</a>}
        {canWrite && !item.archived_at && <div className="flex flex-wrap gap-3">
          <Button disabled={blocked} onClick={() => setEditor(item)}>{text('edit')}</Button>
          <form onSubmit={(event) => mutation.propose(event, text('archive'), root + '/' + item.id, { version: item.version, archive: true }, true)}><Button type="submit" disabled={blocked}>{text('archive')}</Button></form>
        </div>}
      </li>)}</ul>
      {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={blocked} onPageChange={(next) => { setPage(next); setEditor(null) }} />}
      {editor && <MediaForm key={editor === 'new' ? 'new' : editor.id + editor.version} {...{ active, onInvalid }} root={root} item={editor === 'new' ? null : editor} disabled={blocked}
        errors={mutation.errors(root + (editor === 'new' ? '' : '/' + editor.id))} onCancel={() => setEditor(null)} onSubmit={(event, values) => {
          const record = editor === 'new' ? null : editor
          mutation.propose(event, text('save'), root + (record ? '/' + record.id : ''), { ...values, ...(record ? { version: record.version } : {}) }, !!record)
        }} />}
      <p className="text-sm text-slate-400">{text('limits')}</p>
    </>}
  </section>
}

function MediaForm({ active, root, item, disabled, errors, onSubmit, onCancel, onInvalid }: Props & { root: string; item: MediaItem | null; errors: Record<string, string[]>; onSubmit: (event: FormEvent<HTMLFormElement>, values: Record<string, unknown>) => void; onCancel: () => void }) {
  const [kind, setKind] = useState(item?.kind ?? 'vod'), [status, setStatus] = useState(item?.status ?? 'ended')
  const [visibility, setVisibility] = useState(item?.visibility ?? 'private'), [scrim, setScrim] = useState(item?.is_scrim ?? false)
  const [observed] = useState(() => localDate(item?.status_observed_at ?? new Date().toISOString()))
  const publisher = active.context!.permissions.includes('media.publish')
  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const values = changedValues(event.currentTarget, item, ['status_observed_at', 'publish_at'])
    if (!item || scrim !== item.is_scrim) values.is_scrim = scrim
    else delete values.is_scrim
    if (visibility === 'private') delete values.publish_at
    if (values.confirm_sharing) values.confirm_sharing = true
    if (item && ['kind','provider','url','status'].some((key) => key in values)) values.status_observed_at = isoDate(String(new FormData(event.currentTarget).get('status_observed_at') ?? ''))
    onSubmit(event, values)
  }
  return <form noValidate aria-label={text('save')} onSubmit={submit} className="grid min-w-0 gap-3 rounded-xl border border-cyan-400/30 p-3 sm:grid-cols-2">
    <FormField name="title" label={text('name')} defaultValue={item?.title ?? ''} required maxLength={160} disabled={disabled} error={errors.title?.[0]} />
    <TeamSelect {...{ active, root, onInvalid, disabled }} value={item?.team_id ?? ''} error={errors.team_id?.[0]} />
    <SelectField name="kind" title={text('kind')} value={kind} disabled={disabled} error={errors.kind?.[0]} onChange={(next) => {
      setKind(next as MediaItem['kind']); setStatus(next === 'stream' ? 'scheduled' : 'ended')
    }}>{mediaKinds.map((key) => <option key={key} value={key}>{text('kind.' + key)}</option>)}</SelectField>
    <SelectField name="provider" title={text('provider')} defaultValue={item?.provider ?? 'youtube'} disabled={disabled} error={errors.provider?.[0]}><option value="youtube">YouTube</option><option value="twitch">Twitch</option></SelectField>
    <FormField name="url" label={text('url')} defaultValue={item?.url ?? ''} maxLength={500} type="url" required disabled={disabled} error={errors.url?.[0]} />
    <SelectField name="status" title={text('status')} value={status} onChange={(value) => setStatus(value as MediaItem['status'])} disabled={disabled} error={errors.status?.[0]}>{mediaStatuses.filter((key) => kind === 'stream' || ['ended','unavailable'].includes(key)).map((key) => <option key={key} value={key}>{text('status.' + key)}</option>)}</SelectField>
    <FormField name="status_observed_at" label={text('observedLocal')} type="datetime-local" step="1" defaultValue={observed} required disabled={disabled} error={errors.status_observed_at?.[0]} />
    <SelectField name="is_scrim" title={text('context')} value={String(scrim)} onChange={(value) => setScrim(value === 'true')} disabled={disabled} error={errors.is_scrim?.[0]}><option value="false">{text('teamContext')}</option><option value="true">{text('scrimContext')}</option></SelectField>
    <SelectField name="visibility" title={text('visibility')} value={visibility} onChange={(value) => setVisibility(value as MediaItem['visibility'])} disabled={disabled} error={errors.visibility?.[0]}>{mediaVisibilities.filter((key) => publisher || key === 'private' || key === item?.visibility).map((key) => <option key={key} value={key}>{text('visibility.' + key)}</option>)}</SelectField>
    {visibility !== 'private' && <>
      <FormField name="publish_at" label={text('publishLocal')} type="datetime-local" step="1" defaultValue={localDate(item?.publish_at)} required={scrim} disabled={disabled || !publisher} error={errors.publish_at?.[0]} />
      <FormField name="confirm_sharing" label={text('confirmSharing')} type="checkbox" disabled={disabled || !publisher} error={errors.confirm_sharing?.[0]} />
      <p className="text-sm text-slate-400 sm:col-span-2">{text('sharingHint')}</p>
    </>}
    <div className="flex flex-wrap gap-3 sm:col-span-2"><Button type="submit" disabled={disabled}>{text('save')}</Button><Button disabled={disabled} onClick={onCancel}>{t('tenant.cancel')}</Button></div>
  </form>
}

function TeamSelect({ active, root, onInvalid, disabled, value, error }: Props & { root: string; value: string; error?: string }) {
  const [page, setPage] = useState(1), [selected, setSelected] = useState(value)
  const { list, loading, error: failure } = usePartnershipList<{ id: string; label: string }>(root + '/teams?per_page=10&page=' + page, active, true, onInvalid)
  return <div className="grid min-w-0 gap-2">
    <SelectField name="team_id" title={text('team')} value={selected} onChange={setSelected} disabled={disabled} error={error}>
      <option value="">{text('chooseTeam')}</option>
      {selected && !list?.data.some((team) => team.id === selected) && <option value={selected}>{text('currentTeam')}</option>}
      {list?.data.map((team) => <option key={team.id} value={team.id}>{team.label}</option>)}
    </SelectField>
    {loading && <p role="status">{text('loading')}</p>}
    {failure && <Alert tone="error" title={failure.message} requestId={failure.requestId} />}
    {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={disabled || loading} onPageChange={setPage} />}
  </div>
}
function date(value: string, active: ActiveOrganization) {
  return new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'short', timeZone: active.context!.timezone }).format(new Date(value))
}
