import { useEffect, useId, useState, type FormEvent } from 'react'
import { tenancyRequest, type ActiveOrganization, type Page } from '../../api/tenancy'
import { isAbortError } from '../../api/errors'
import {
  partnershipText as text, sponsorsPath, sponsorStatuses, opportunityStatuses, interactionChannels,
  type Sponsor, type PartnershipKind, type PartnershipRecord, type SponsorContact, type SponsorshipOpportunity,
} from '../../api/partnerships'
import { t } from '../../i18n/fr'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { AsyncState } from '../feedback/AsyncState'
import { SelectField } from '../people/peopleForms'
import { apiError, changedValues, isoDate, localDate, shouldReloadContext } from '../people/peopleFormValues'
import { usePeopleMutation } from '../people/usePeopleMutation'

type Props = { active: ActiveOrganization; disabled: boolean; onInvalid: () => void }
type Editable = Sponsor | SponsorContact | SponsorshipOpportunity
type ExchangeLink = { field: 'contact_id' | 'opportunity_id'; id: string; name: string }

function usePartnershipList<T>(path: string, active: ActiveOrganization, enabled: boolean, onInvalid: () => void, refresh = 0) {
  const [list, setList] = useState<Page<T> | null>(null), [loading, setLoading] = useState(false)
  const [error, setError] = useState<ReturnType<typeof apiError> | null>(null)
  const revision = active.revision ?? undefined
  useEffect(() => {
    const controller = new AbortController()
    if (!enabled) return () => controller.abort()
    const load = async () => {
      setLoading(true); setList(null); setError(null)
      await tenancyRequest<Page<T>>(path, { revision, signal: controller.signal }).then((result) => {
        if (!controller.signal.aborted) setList(result)
      }).catch((failure: unknown) => {
        if (controller.signal.aborted || isAbortError(failure)) return
        const next = apiError(failure); setError(next)
        if (shouldReloadContext(next)) onInvalid()
      }).finally(() => { if (!controller.signal.aborted) setLoading(false) })
    }
    void load()
    return () => controller.abort()
  }, [path, revision, enabled, onInvalid, refresh])
  return { list, loading, error }
}

export function PartnershipsPanel({ active, disabled, onInvalid }: Props) {
  const [open, setOpen] = useState(false), [page, setPage] = useState(1), [refresh, setRefresh] = useState(0)
  const [query, setQuery] = useState(''), [status, setStatus] = useState('')
  const [editor, setEditor] = useState<Sponsor | 'new' | null>(null), [selected, setSelected] = useState<Sponsor | null>(null)
  const context = active.context, canRead = context?.permissions.includes('partnerships.read') ?? false
  const canWrite = context?.permissions.includes('partnerships.write') ?? false
  const path = sponsorsPath(context?.organization_id ?? '')
  const params = new URLSearchParams({ per_page: '10', page: String(page), ...(query ? { q: query } : {}), ...(status ? { status } : {}) })
  const { list, loading, error } = usePartnershipList<Sponsor>(path + '?' + params, active, open && canRead, onInvalid, refresh)
  const mutation = usePeopleMutation<Sponsor>(active, () => { setEditor(null); setSelected(null); setRefresh((value) => value + 1) }, onInvalid)
  const blocked = disabled || mutation.blocked
  if (!canRead) return null
  return <section aria-label={text('title')} className="grid min-w-0 gap-4 rounded-2xl border border-white/10 p-4">
    <h2 className="text-xl font-bold">{text('title')}</h2>
    <p className="text-sm text-slate-400">{text('hint')}</p>
    <Button disabled={blocked} onClick={() => { setOpen(!open); setEditor(null); setSelected(null) }}>{text(open ? 'close' : 'open')}</Button>
    {open && <>
      {mutation.feedback}
      <SearchForm status={status} statuses={sponsorStatuses} disabled={blocked} onSearch={(next, filter) => { setQuery(next); setStatus(filter); setPage(1); setSelected(null); setEditor(null) }} />
      {canWrite && <Button disabled={blocked} onClick={() => { setSelected(null); setEditor('new') }}>{text('new')}</Button>}
      {loading && <AsyncState variant="loading" title={text('loading')} />}
      {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
      {list?.data.length === 0 && <p>{text('empty')}</p>}
      <ul className="grid min-w-0 gap-3">{list?.data.map((sponsor) => <li key={sponsor.id} className="grid min-w-0 gap-2 rounded-xl border border-slate-700 p-3">
        <h3 className="font-bold [overflow-wrap:anywhere]">{sponsor.name}</h3>
        <p className="[overflow-wrap:anywhere]">{text('status.' + sponsor.status)}{sponsor.industry && ' · ' + sponsor.industry}</p>
        <div className="flex flex-wrap gap-3">
          <Button disabled={blocked} onClick={() => { setEditor(null); setSelected(sponsor) }}>{text('details')}</Button>
          {canWrite && sponsor.status !== 'archived' && <Button disabled={blocked} onClick={() => { setSelected(null); setEditor(sponsor) }}>{text('edit')}</Button>}
        </div>
      </li>)}</ul>
      {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={blocked} onPageChange={(next) => { setSelected(null); setEditor(null); setPage(next) }} />}
      {editor && <RecordForm key={editor === 'new' ? 'new' : editor.id + editor.version} kind="sponsors" record={editor === 'new' ? null : editor} disabled={blocked}
        errors={mutation.errors(path + (editor === 'new' ? '' : '/' + editor.id))} onCancel={() => setEditor(null)} onSubmit={(event) => {
          const record = editor === 'new' ? null : editor
          mutation.propose(event, text('save'), path + (record ? '/' + record.id : ''), { ...changedValues(event.currentTarget, record), ...(record ? { version: record.version } : {}) }, !!record)
        }} />}
      {selected && <SponsorDetails key={selected.id + selected.version} {...{ active, disabled: blocked, onInvalid }} sponsor={selected} onClose={() => setSelected(null)} />}
      <p className="text-sm text-slate-400">{text('historyHint')}</p>
    </>}
  </section>
}

function SponsorDetails({ active, sponsor, disabled, onInvalid, onClose }: Props & { sponsor: Sponsor; onClose: () => void }) {
  const [kind, setKind] = useState<PartnershipKind>('contacts'), [link, setLink] = useState<ExchangeLink | null>(null)
  return <section aria-label={sponsor.name} className="grid min-w-0 gap-4 rounded-xl border border-cyan-400/30 p-4">
    <h3 className="font-bold [overflow-wrap:anywhere]">{sponsor.name}</h3>
    <Button disabled={disabled} onClick={onClose}>{text('closeDetails')}</Button>
    <div className="flex flex-wrap gap-3">{(['contacts', 'opportunities', 'interactions'] as const).map((tab) => <Button key={tab} disabled={disabled} aria-pressed={kind === tab} onClick={() => { setLink(null); setKind(tab) }}>{text(tab)}</Button>)}</div>
    <RelatedPanel key={kind + (link?.id ?? '')} {...{ active, sponsor, disabled, onInvalid, kind, link }} onLink={(next) => { setLink(next); setKind('interactions') }} />
  </section>
}

function RelatedPanel({ active, sponsor, kind, link, disabled, onInvalid, onLink }: Props & { sponsor: Sponsor; kind: PartnershipKind; link: ExchangeLink | null; onLink: (link: ExchangeLink) => void }) {
  const [page, setPage] = useState(1), [refresh, setRefresh] = useState(0), [query, setQuery] = useState(''), [status, setStatus] = useState('')
  const [editor, setEditor] = useState<PartnershipRecord | 'new' | null>(link ? 'new' : null)
  const path = sponsorsPath(active.context!.organization_id, sponsor.id) + '/' + kind
  const params = new URLSearchParams({ per_page: '10', page: String(page), ...(query ? { q: query } : {}), ...(status ? { status } : {}) })
  const { list, loading, error } = usePartnershipList<PartnershipRecord>(path + '?' + params, active, true, onInvalid, refresh)
  const mutation = usePeopleMutation<PartnershipRecord>(active, () => { setEditor(null); setPage(1); setRefresh((value) => value + 1) }, onInvalid)
  const canWrite = active.context!.permissions.includes('partnerships.write') && sponsor.status !== 'archived'
  const blocked = disabled || mutation.blocked
  const statuses = kind === 'opportunities' ? opportunityStatuses : kind === 'contacts' ? ['active', 'archived'] : []
  return <div className="grid min-w-0 gap-3">
    <h4 className="font-bold">{text(kind)}</h4>
    {mutation.feedback}
    <SearchForm disabled={blocked} status={status} statuses={statuses} onSearch={(next, filter) => { setQuery(next); setStatus(filter); setPage(1); setEditor(null) }} />
    {canWrite && <Button disabled={blocked} onClick={() => setEditor('new')}>{text('new.' + kind)}</Button>}
    {loading && <AsyncState variant="loading" title={text('loading')} />}
    {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
    {list?.data.length === 0 && <p>{text('empty')}</p>}
    <ul className="grid min-w-0 gap-3">{list?.data.map((record) => <li key={record.id} className="grid min-w-0 gap-2 rounded-xl border border-slate-700 p-3">
      {'summary' in record ? <>
        <p className="whitespace-pre-wrap [overflow-wrap:anywhere]">{record.summary}</p>
        <p className="[overflow-wrap:anywhere]">{text('channel.' + record.channel)} · {new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'short', timeZone: active.context!.timezone }).format(new Date(record.occurred_at))}</p>
      </> : <>
        <p className="font-bold [overflow-wrap:anywhere]">{'name' in record ? record.name : record.title} · {text('status.' + record.status)}</p>
        {'email' in record && <p className="[overflow-wrap:anywhere]">{[record.position, record.email, record.phone].filter(Boolean).join(' · ')}</p>}
        {'description' in record && record.description && <p className="whitespace-pre-wrap [overflow-wrap:anywhere]">{record.description}</p>}
        {canWrite && record.status !== 'archived' && <div className="flex flex-wrap gap-3">
          <Button disabled={blocked} onClick={() => setEditor(record)}>{text('edit')}</Button>
          <Button disabled={blocked} onClick={() => onLink({ field: kind === 'contacts' ? 'contact_id' : 'opportunity_id', id: record.id, name: 'name' in record ? record.name : record.title })}>{text('recordExchange')}</Button>
        </div>}
      </>}
    </li>)}</ul>
    {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={blocked} onPageChange={(next) => { setPage(next); setEditor(null) }} />}
    {editor && <RecordForm key={editor === 'new' ? 'new' : editor.id} kind={kind} record={editor === 'new' || 'summary' in editor ? null : editor} link={link}
      errors={mutation.errors(path + (editor === 'new' ? '' : '/' + editor.id))} disabled={blocked} onCancel={() => setEditor(null)} onSubmit={(event) => {
        const record = editor === 'new' ? null : editor
        const values = changedValues(event.currentTarget, record)
        if (kind === 'interactions') values.occurred_at = isoDate(String(values.occurred_at ?? ''))
        mutation.propose(event, text('save.' + kind), path + (record ? '/' + record.id : ''), { ...values, ...(record && 'version' in record ? { version: record.version } : {}) }, !!record)
      }} />}
  </div>
}

function SearchForm({ statuses, status, disabled, onSearch }: { statuses: readonly string[]; status: string; disabled: boolean; onSearch: (query: string, status: string) => void }) {
  return <form aria-label={text('search')} className="grid min-w-0 gap-3 sm:grid-cols-2" onSubmit={(event) => {
    event.preventDefault(); const data = new FormData(event.currentTarget); onSearch(String(data.get('q') ?? '').trim(), String(data.get('status') ?? ''))
  }}>
    <FormField name="q" label={text('search')} maxLength={80} disabled={disabled} />
    {statuses.length > 0 && <SelectField name="status" title={text('status')} defaultValue={status} disabled={disabled}><option value="">{text('all')}</option>{statuses.map((option) => <option key={option} value={option}>{text('status.' + option)}</option>)}</SelectField>}
    <Button type="submit" disabled={disabled}>{text('searchAction')}</Button>
  </form>
}

function RecordForm({ kind, record, link, disabled, errors, onSubmit, onCancel }: { kind: 'sponsors' | PartnershipKind; record: Editable | null; link?: ExchangeLink | null; disabled: boolean; errors: Record<string, string[]>; onSubmit: (event: FormEvent<HTMLFormElement>) => void; onCancel: () => void }) {
  const [exchangeDate] = useState(() => localDate(new Date().toISOString()))
  const label = kind === 'sponsors' ? text('save') : text('save.' + kind)
  const fields = kind === 'sponsors' ? ['name', 'industry'] : kind === 'contacts' ? ['name', 'position', 'email', 'phone'] : kind === 'opportunities' ? ['title', 'description'] : ['summary', 'occurred_at']
  const statuses = kind === 'sponsors' ? sponsorStatuses : kind === 'contacts' ? ['active', 'archived'] : opportunityStatuses
  return <form noValidate aria-label={label} onSubmit={onSubmit} className="grid min-w-0 gap-3 rounded-xl border border-cyan-400/30 p-4 sm:grid-cols-2">
    {fields.map((field) => {
      const title = field === 'name' && kind === 'contacts' ? text('contactName') : field === 'title' ? text('opportunityTitle') : text(field)
      const value = record && field in record ? String(record[field as keyof Editable] ?? '') : ''
      if (['summary', 'description'].includes(field)) return <LongTextField key={field} name={field} title={title} value={value} disabled={disabled} error={errors[field]?.[0]} />
      return <FormField key={field} name={field} label={title} defaultValue={field === 'occurred_at' ? exchangeDate : value}
        required={['name', 'title', 'summary', 'occurred_at'].includes(field)} maxLength={['summary', 'description'].includes(field) ? 4000 : field === 'email' ? 254 : field === 'phone' ? 32 : ['industry', 'position'].includes(field) ? 120 : 160}
        type={field === 'email' ? 'email' : field === 'occurred_at' ? 'datetime-local' : 'text'} step={field === 'occurred_at' ? '1' : undefined} disabled={disabled} error={errors[field]?.[0]} />
    })}
    {kind === 'interactions' ? <>
      <SelectField name="channel" title={text('channel')} defaultValue="note" disabled={disabled} error={errors.channel?.[0]}>{interactionChannels.map((channel) => <option key={channel} value={channel}>{text('channel.' + channel)}</option>)}</SelectField>
      {link && <><input type="hidden" name={link.field} value={link.id} /><p className="[overflow-wrap:anywhere] sm:col-span-2">{t('partnerships.linked', { name: link.name })}</p></>}
      <p className="text-sm text-slate-400 sm:col-span-2">{text('manual')}</p>
    </> : <SelectField name="status" title={text('status')} defaultValue={record?.status ?? statuses[0]} disabled={disabled} error={errors.status?.[0]}>{statuses.map((status) => <option key={status} value={status}>{text('status.' + status)}</option>)}</SelectField>}
    <div className="flex flex-wrap gap-3 sm:col-span-2"><Button type="submit" disabled={disabled}>{label}</Button><Button disabled={disabled} onClick={onCancel}>{t('tenant.cancel')}</Button></div>
  </form>
}

function LongTextField({ name, title, value, disabled, error }: { name: string; title: string; value: string; disabled: boolean; error?: string }) {
  const id = useId()
  return <div className="grid min-w-0 gap-2 sm:col-span-2">
    <label htmlFor={id}>{title}</label>
    <textarea id={id} name={name} defaultValue={value} disabled={disabled} maxLength={4000} rows={4} required={name === 'summary'}
      aria-invalid={error ? true : undefined} aria-describedby={error ? id + '-error' : undefined}
      className="min-w-0 w-full rounded-xl border border-white/15 bg-slate-900 px-4 py-3 text-slate-100" />
    {error && <p id={id + '-error'} className="text-sm text-rose-200">{error}</p>}
  </div>
}
