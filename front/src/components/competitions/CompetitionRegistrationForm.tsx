import { useRef, useState, type FormEvent } from 'react'
import type { ActiveOrganization } from '../../api/tenancy'
import type { CompetitionDocument } from '../../api/externalCompetitions'
import { emptyRegistration, registrationStates, registrationText as text, type CompetitionRegistration, type RegistrationItem } from '../../api/competitionRegistrations'
import { usePartnershipList } from '../partnerships/usePartnershipList'
import { SelectField } from '../people/peopleForms'
import { FormField } from '../FormField'
import { Pagination } from '../data/Pagination'
import { Button } from '../Button'
import { Alert } from '../feedback/Alert'
type Props = { active: ActiveOrganization; path: string; record: CompetitionRegistration | null; disabled: boolean; onInvalid: () => void; errors: Record<string, string[]>; onSubmit: (event: FormEvent<HTMLFormElement>, body: Record<string, unknown>) => void; onCancel: () => void }
export function CompetitionRegistrationForm({ active, path, record, disabled, onInvalid, errors, onSubmit, onCancel }: Props) {
  const payload = record?.payload ?? emptyRegistration
  const [roster, setRoster] = useState(() => payload.roster.map((row, key) => ({ ...row, key }))), nextKey = useRef(payload.roster.length)
  const [items, setItems] = useState(payload.items), [peoplePage, setPeoplePage] = useState(1), [documentPage, setDocumentPage] = useState(1)
  const { list: people, loading, error } = usePartnershipList<{ id: string; label: string }>(path + '/people?page=' + peoplePage + '&per_page=5', active, true, onInvalid)
  const { list: documents, loading: documentsLoading, error: documentsError } = usePartnershipList<CompetitionDocument>(path.replace(/\/registration$/, '/documents') + '?page=' + documentPage + '&per_page=5', active, true, onInvalid)
  const options = (id: string | null, label: string | null) => <><option value="">{text('selectPerson')}</option>{id && !people?.data.some((p) => p.id === id) && <option value={id}>{label ?? id}</option>}{people?.data.map((p) => <option key={p.id} value={p.id}>{p.label}</option>)}</>
  return <form aria-label={text('save')} className="grid min-w-0 gap-4 rounded-xl border border-cyan-400/30 p-3" onSubmit={(event) => {
    const values = new FormData(event.currentTarget), value = (key: string) => String(values.get(key) ?? '').trim(), nullable = (key: string) => value(key) || null
    const body = { ...Object.fromEntries(Object.keys(registrationStates).map((key) => [key, value(key)])), external_reference: nullable('external_reference'), source_url: nullable('source_url'), notes: nullable('notes'), requirements: Object.fromEntries(['age', 'residence', 'rank'].map((key) => [key, nullable('requirements.' + key)])),
      roster: roster.map((row, index) => ({ person_id: row.person_id, role: value('roster.' + index + '.role') })),
      items: items.map((item, index) => ({ id: item.id, kind: item.kind, title: value('items.' + index + '.title'), status: value('items.' + index + '.status'), due_at: value('items.' + index + '.due_at') ? new Date(value('items.' + index + '.due_at') + 'Z').toISOString().replace('Z', '+00:00').replace('.000', '') : null, responsible_person_id: item.responsible_person_id, note: nullable('items.' + index + '.note'), document_version_id: item.kind === 'document' ? item.document_version_id : null })),
      expected_version: record?.version ?? null, reason: value('reason') }
    onSubmit(event, body)
  }}>
    {Object.entries(registrationStates).map(([key, states]) => <SelectField key={key} name={key} title={text(key)} defaultValue={payload[key as keyof typeof registrationStates]} disabled={disabled} error={errors[key]?.[0]}>{states.map((state) => <option key={state} value={state}>{text('state.' + state)}</option>)}</SelectField>)}
    <FormField name="external_reference" label={text('external_reference')} defaultValue={payload.external_reference ?? ''} maxLength={160} disabled={disabled} error={errors.external_reference?.[0]} />
    <FormField name="source_url" label={text('source_url')} type="url" pattern="https://.*" defaultValue={payload.source_url ?? ''} maxLength={2000} disabled={disabled} error={errors.source_url?.[0]} />
    <FormField name="notes" label={text('notes')} defaultValue={payload.notes ?? ''} maxLength={2000} disabled={disabled} error={errors.notes?.[0]} />
    <p className="text-sm text-slate-400">{text('declarative')}</p>
    {(['age', 'residence', 'rank'] as const).map((key) => <FormField key={key} name={'requirements.' + key} label={text('requirement.' + key)} defaultValue={payload.requirements[key] ?? ''} maxLength={1000} disabled={disabled} error={errors['requirements.' + key]?.[0]} />)}
    <h4 className="font-bold">{text('peopleChoices')}</h4>{loading && <p role="status">{text('loading')}</p>}{error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
    {people && <Pagination currentPage={people.meta.current_page} totalPages={people.meta.last_page} disabled={disabled || loading} onPageChange={setPeoplePage} />}
    <h4 className="font-bold">{text('roster')}</h4>{errors.roster?.[0] && <Alert tone="error" title={errors.roster[0]} />}
    {roster.map((row, index) => <fieldset key={row.key} className="grid min-w-0 gap-3 rounded-lg border border-slate-700 p-3"><legend>{text('player')} {index + 1}</legend>
      <SelectField name={'roster.' + index + '.person_id'} title={text('player')} value={row.person_id} required disabled={disabled || loading} error={errors['roster.' + index + '.person_id']?.[0]} onChange={(id) => setRoster((all) => all.map((p) => p.key === row.key ? { ...p, person_id: id, label: people?.data.find((entry) => entry.id === id)?.label ?? '' } : p))}>{options(row.person_id, row.label)}</SelectField>
      <SelectField name={'roster.' + index + '.role'} title={text('role')} defaultValue={row.role} disabled={disabled} error={errors['roster.' + index + '.role']?.[0]}><option value="starter">{text('starter')}</option><option value="substitute">{text('substitute')}</option></SelectField>
      <Button disabled={disabled} onClick={() => setRoster((all) => all.filter((p) => p.key !== row.key))}>{text('removePlayer')} {index + 1}</Button>
    </fieldset>)}
    {roster.length < 20 && <Button disabled={disabled} onClick={() => { const key = nextKey.current++; setRoster((all) => [...all, { key, person_id: '', role: 'starter', label: '' }]) }}>{text('addPlayer')}</Button>}
    <h4 className="font-bold">{text('items')}</h4><p className="text-sm text-slate-400">{text('piecesHint')}</p>{errors.items?.[0] && <Alert tone="error" title={errors.items[0]} />}
    {items.some((item) => item.kind === 'document') && <><p>{text('documentChoices')}</p>{documentsError && <Alert tone="error" title={documentsError.message} requestId={documentsError.requestId} />}{documents && <Pagination currentPage={documents.meta.current_page} totalPages={documents.meta.last_page} disabled={disabled || documentsLoading} onPageChange={setDocumentPage} />}</>}
    {items.map((item, index) => <fieldset key={item.id} className="grid min-w-0 gap-3 rounded-lg border border-slate-700 p-3"><legend>{text('item')} {index + 1}</legend>
      <SelectField name={'items.' + index + '.kind'} title={text('kind')} value={item.kind} disabled={disabled} onChange={(kind) => setItems((all) => all.map((row) => row.id === item.id ? { ...row, kind: kind as RegistrationItem['kind'], document_version_id: null } : row))}>{['registration', 'payment', 'document', 'roster', 'checkin', 'task'].map((kind) => <option key={kind} value={kind}>{text('kind.' + kind)}</option>)}</SelectField>
      <FormField name={'items.' + index + '.title'} label={text('title')} defaultValue={item.title} required maxLength={160} disabled={disabled} error={errors['items.' + index + '.title']?.[0]} />
      <SelectField name={'items.' + index + '.status'} title={text('itemStatus')} defaultValue={item.status} disabled={disabled}>{['pending', 'done', 'waived'].map((status) => <option key={status} value={status}>{text('state.' + status)}</option>)}</SelectField>
      <FormField name={'items.' + index + '.due_at'} label={text('dueUtc')} type="datetime-local" step={1} defaultValue={item.due_at ? new Date(item.due_at).toISOString().slice(0, 19) : ''} disabled={disabled} error={errors['items.' + index + '.due_at']?.[0]} />
      <SelectField name={'items.' + index + '.responsible_person_id'} title={text('responsible')} value={item.responsible_person_id ?? ''} disabled={disabled || loading} error={errors['items.' + index + '.responsible_person_id']?.[0]} onChange={(id) => setItems((all) => all.map((row) => row.id === item.id ? { ...row, responsible_person_id: id || null, responsible_label: people?.data.find((p) => p.id === id)?.label ?? null } : row))}>{options(item.responsible_person_id, item.responsible_label)}</SelectField>
      <FormField name={'items.' + index + '.note'} label={text('itemNote')} defaultValue={item.note ?? ''} maxLength={1000} disabled={disabled} error={errors['items.' + index + '.note']?.[0]} />
      {item.kind === 'document' && <SelectField name={'items.' + index + '.document_version_id'} title={text('privateVersion')} value={item.document_version_id ?? ''} disabled={disabled || documentsLoading} onChange={(id) => setItems((all) => all.map((row) => row.id === item.id ? { ...row, document_version_id: id || null } : row))}>
        <option value="">{text('noDocument')}</option>{item.document_version_id && !documents?.data.some((d) => d.version === item.document_version_id) && <option value={item.document_version_id}>{text('retainedVersion')}</option>}{documents?.data.map((d) => <option key={d.version} value={d.version}>{d.title} · v{d.number}</option>)}
      </SelectField>}
      <Button disabled={disabled} onClick={() => setItems((all) => all.filter((row) => row.id !== item.id))}>{text('removeItem')} {index + 1}</Button>
    </fieldset>)}
    {items.length < 50 && <Button disabled={disabled} onClick={() => setItems((all) => [...all, { id: crypto.randomUUID(), kind: 'task', title: '', status: 'pending', due_at: null, responsible_person_id: null, responsible_label: null, note: null, document_version_id: null }])}>{text('addItem')}</Button>}
    <FormField name="reason" label={text('reason')} required minLength={10} maxLength={1000} disabled={disabled} error={errors.reason?.[0]} />
    <div className="flex flex-wrap gap-3"><Button type="submit" disabled={disabled}>{text('save')}</Button><Button disabled={disabled} onClick={onCancel}>{text('cancel')}</Button></div>
  </form>
}
