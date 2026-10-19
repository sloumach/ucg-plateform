import { useState } from 'react'
import type { ActiveOrganization, Page } from '../../api/tenancy'
import { competitionText as text, safeCompetitionUrl, type ExternalCompetition, type CompetitionRevision } from '../../api/externalCompetitions'
import { calendarDate } from '../../api/calendar'
import { usePartnershipList } from '../partnerships/usePartnershipList'
import { usePeopleMutation } from '../people/usePeopleMutation'
import { SelectField } from '../people/peopleForms'
import { Pagination } from '../data/Pagination'
import { FormField } from '../FormField'
import { Button } from '../Button'
import { Alert } from '../feedback/Alert'
import { ExternalCompetitionForm } from './ExternalCompetitionForm'
import { CompetitionDocuments } from './CompetitionDocuments'
import { CompetitionRegistrationPanel } from './CompetitionRegistrationPanel'
import { registrationText } from '../../api/competitionRegistrations'

import { CompetitionOutcomePanel } from './CompetitionOutcomePanel'
import { outcomeText, type OutcomeStream } from '../../api/competitionOutcomes'

type Props = { active: ActiveOrganization; disabled: boolean; onInvalid: () => void }
export function ExternalCompetitionsPanel({ active, disabled, onInvalid }: Props) {
  const [open, setOpen] = useState(false), [page, setPage] = useState(1), [refresh, setRefresh] = useState(0)
  const [filters, setFilters] = useState({ status: 'active', season: '', region: '' })
  const [editor, setEditor] = useState<ExternalCompetition | 'new' | null>(null), [archive, setArchive] = useState<ExternalCompetition | null>(null)
  const [outcome, setOutcome] = useState<{ item: ExternalCompetition; stream: OutcomeStream } | null>(null)
  const [registration, setRegistration] = useState<ExternalCompetition | null>(null)
  const [history, setHistory] = useState<ExternalCompetition | null>(null), [documents, setDocuments] = useState<ExternalCompetition | null>(null)
  const permissions = active.context?.permissions ?? [], allowed = permissions.includes('sports.read') && permissions.includes('sports.competitions.read'), canWrite = permissions.includes('sports.competitions.write')
  const root = 'tenants/' + active.context!.organization_id + '/external-competitions'
  const params = new URLSearchParams({ page: String(page), per_page: '10', status: filters.status, ...(filters.season ? { season: filters.season } : {}), ...(filters.region ? { region: filters.region } : {}) })
  const { list, loading, error } = usePartnershipList<ExternalCompetition>(root + '?' + params, active, open && allowed, onInvalid, refresh)
  const clear = () => { setOutcome(null); setRegistration(null); setEditor(null); setArchive(null); setHistory(null); setDocuments(null) }
  const mutation = usePeopleMutation<ExternalCompetition>(active, () => { clear(); setRefresh((value) => value + 1) }, onInvalid)
  const blocked = disabled || mutation.blocked
  const editPath = root + (editor && editor !== 'new' ? '/' + editor.id : '')
  if (!allowed) return null
  return <section aria-label={text('title')} className="grid min-w-0 gap-4 rounded-2xl border border-white/10 p-4">
    <h2 className="text-xl font-bold">{text('title')}</h2><p className="text-sm text-slate-400">{text('hint')}</p>
    <Button disabled={blocked} onClick={() => { setOpen(!open); clear() }}>{text(open ? 'close' : 'open')}</Button>
    {open && <>
      {mutation.feedback}
      <form aria-label={text('filters')} className="grid min-w-0 gap-3 sm:grid-cols-2" onSubmit={(event) => {
        event.preventDefault(); const values = new FormData(event.currentTarget)
        setFilters({ status: String(values.get('status') ?? 'active'), region: String(values.get('region') ?? '').trim(), season: String(values.get('season') ?? '').trim() }); setPage(1); clear()
      }}>
        <SelectField name="status" title={text('status')} defaultValue="active" disabled={blocked}><option value="active">{text('active')}</option><option value="archived">{text('archived')}</option></SelectField>
        <FormField name="season" label={text('season')} maxLength={100} disabled={blocked} /><FormField name="region" label={text('region')} maxLength={80} disabled={blocked} />
        <div className="flex flex-wrap gap-3"><Button type="submit" disabled={blocked}>{text('filter')}</Button><Button disabled={blocked || loading} onClick={() => { clear(); setRefresh((value) => value + 1) }}>{text('refresh')}</Button></div>
      </form>
      {canWrite && <Button disabled={blocked} onClick={() => { clear(); setEditor('new') }}>{text('create')}</Button>}
      {loading && <p role="status">{text('loading')}</p>}{error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
      {list?.data.length === 0 && <p>{text('empty')}</p>}
      <ol className="grid min-w-0 gap-3">{list?.data.map((item) => <li key={item.id} className="grid min-w-0 gap-3 rounded-xl border border-slate-700 p-3">
        <CompetitionMetadata item={item} />
        <div className="flex flex-wrap gap-3"><Button disabled={blocked} onClick={() => { clear(); setOutcome({ item, stream: 'sport' }) }}>{outcomeText('sport')}</Button>{permissions.includes('financial.read') && <Button disabled={blocked} onClick={() => { clear(); setOutcome({ item, stream: 'awards' }) }}>{outcomeText('awards')}</Button>}</div>
        {permissions.includes('sports.registrations.read') && <Button disabled={blocked} onClick={() => { clear(); setRegistration(item) }}>{registrationText('panel')}</Button>}
        <div className="flex flex-wrap gap-3">
          <Button disabled={blocked} onClick={() => { clear(); setDocuments(item) }}>{text('documents')}</Button><Button disabled={blocked} onClick={() => { clear(); setHistory(item) }}>{text('history')}</Button>
          {canWrite && item.status === 'active' && <><Button disabled={blocked} onClick={() => { clear(); setEditor(item) }}>{text('edit')}</Button><Button disabled={blocked} onClick={() => { clear(); setArchive(item) }}>{text('archive')}</Button></>}
        </div>
      </li>)}</ol>
      {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={blocked || loading} onPageChange={(value) => { clear(); setPage(value) }} />}
      {editor && <ExternalCompetitionForm key={editor === 'new' ? 'new' : editor.version} {...{ active, root, onInvalid }} item={editor === 'new' ? null : editor} disabled={blocked} errors={mutation.errors(editPath)} onCancel={() => setEditor(null)} onSubmit={(event, body) => mutation.propose(event, text('save'), editPath, body, editor !== 'new')} />}
      {archive && <form aria-label={text('archive')} className="grid min-w-0 gap-3" onSubmit={(event) => {
        const values = new FormData(event.currentTarget); mutation.propose(event, text('archive'), root + '/' + archive.id, { version: archive.version, archive: true, reason: String(values.get('reason') ?? '').trim() }, true)
      }}><p className="[overflow-wrap:anywhere]">{archive.name} · {text('archiveHint')}</p><FormField name="reason" label={text('reason')} minLength={10} maxLength={1000} required disabled={blocked} error={mutation.errors(root + '/' + archive.id).reason?.[0]} />
        <div className="flex flex-wrap gap-3"><Button type="submit" disabled={blocked}>{text('archive')}</Button><Button disabled={blocked} onClick={() => setArchive(null)}>{text('cancel')}</Button></div></form>}
      {history && <CompetitionHistory key={history.id} {...{ active, onInvalid }} path={root + '/' + history.id + '/history'} disabled={blocked} onClose={() => setHistory(null)} />}
      {outcome && <CompetitionOutcomePanel key={outcome.item.id + outcome.stream + active.revision} {...{ active, root, onInvalid }} item={outcome.item} stream={outcome.stream} disabled={blocked} onClose={() => setOutcome(null)} />}
      {registration && <CompetitionRegistrationPanel key={registration.id + active.revision} {...{ active, root, onInvalid }} item={registration} disabled={blocked} onClose={() => setRegistration(null)} />}
      {documents && <CompetitionDocuments key={documents.id + active.revision} {...{ active, root, onInvalid }} item={documents} disabled={blocked} onClose={() => setDocuments(null)} />}
    </>}
  </section>
}
export function CompetitionMetadata({ item }: { item: Pick<ExternalCompetition, 'name' | 'reference' | 'game_name' | 'organizer' | 'region' | 'season' | 'status' | 'contacts' | 'website_url'> }) {
  const link = safeCompetitionUrl(item.website_url)
  return <>
    <h3 className="font-bold [overflow-wrap:anywhere]">{item.name}</h3><p className="[overflow-wrap:anywhere]">{item.reference} · {item.game_name} · {text(item.status)}</p>
    <p className="[overflow-wrap:anywhere]">{text('organizer')} : {item.organizer} · {item.region} · {item.season}</p>
    {link && <a className="break-all text-cyan-300 underline" href={link} target="_blank" rel="noopener noreferrer" referrerPolicy="no-referrer">{text('openUrl')}</a>}
    {item.contacts.length > 0 && <ul aria-label={text('contacts')}>{item.contacts.map((contact, index) => <li key={index} className="[overflow-wrap:anywhere]">{contact.name} · {contact.role} · {contact.email} · {contact.phone}</li>)}</ul>}
  </>
}
function CompetitionHistory({ active, path, disabled, onInvalid, onClose }: { active: ActiveOrganization; path: string; disabled: boolean; onInvalid: () => void; onClose: () => void }) {
  const [page, setPage] = useState(1)
  const { list, loading, error } = usePartnershipList<CompetitionRevision, Page<CompetitionRevision>>(path + '?page=' + page + '&per_page=5', active, true, onInvalid)
  return <section aria-label={text('history')} className="grid min-w-0 gap-3 rounded-xl border border-slate-700 p-3"><h3 className="font-bold">{text('history')}</h3><Button disabled={disabled} onClick={onClose}>{text('closeHistory')}</Button>
    {loading && <p role="status">{text('loading')}</p>}{error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
    <ol className="grid min-w-0 gap-3">{list?.data.map((revision) => <li key={revision.id} className="grid min-w-0 gap-2 border-b border-slate-700 pb-3"><p>v{revision.number} · {calendarDate(revision.occurred_at, active.context!.timezone)}</p>
      <CompetitionMetadata item={revision.snapshot} /><p className="whitespace-pre-wrap [overflow-wrap:anywhere]">{revision.reason}</p></li>)}</ol>
    {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={disabled || loading} onPageChange={setPage} />}
  </section>
}
