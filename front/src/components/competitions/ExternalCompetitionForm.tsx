import { useRef, useState, type FormEvent } from 'react'
import type { ActiveOrganization, Page } from '../../api/tenancy'
import { competitionText as text, type ExternalCompetition } from '../../api/externalCompetitions'
import { FormField } from '../FormField'
import { SelectField } from '../people/peopleForms'
import { usePartnershipList } from '../partnerships/usePartnershipList'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { Button } from '../Button'
type Props = { active: ActiveOrganization; root: string; item: ExternalCompetition | null; disabled: boolean; errors: Record<string, string[]>; onInvalid: () => void; onCancel: () => void; onSubmit: (event: FormEvent<HTMLFormElement>, body: Record<string, unknown>) => void }
export function ExternalCompetitionForm({ active, root, item, disabled, errors, onInvalid, onCancel, onSubmit }: Props) {
  const [page, setPage] = useState(1), [choice, setChoice] = useState<{ id: string; label: string } | null>(null)
  const [contacts, setContacts] = useState(() => (item?.contacts ?? []).map((contact, key) => ({ ...contact, key })))
  const nextKey = useRef(contacts.length)
  const { list, loading, error } = usePartnershipList<{ id: string; label: string }, Page<{ id: string; label: string }>>(root + '/games?page=' + page + '&per_page=10', active, item === null, onInvalid)
  return <form aria-label={text('save')} className="grid min-w-0 gap-3 rounded-xl border border-cyan-400/30 p-3" onSubmit={(event) => {
    const values = new FormData(event.currentTarget), value = (key: string) => String(values.get(key) ?? '').trim()
    onSubmit(event, { ...(item ? { version: item.version } : { reference: value('reference').toUpperCase(), game_id: choice?.id ?? '' }),
      name: value('name'), organizer: value('organizer'), region: value('region'), season: value('season'), website_url: value('website_url') || null,
      contacts: contacts.map((_, index) => ({ name: value(`contacts.${index}.name`), role: value(`contacts.${index}.role`) || null, email: value(`contacts.${index}.email`) || null, phone: value(`contacts.${index}.phone`) || null })), reason: value('reason') })
  }}>
    <h3 className="font-bold">{text(item ? 'edit' : 'create')}</h3>
    {item ? <p className="[overflow-wrap:anywhere]">{item.reference} · {item.game_name} · {text('fixedGame')}</p> : <>
      <FormField name="reference" label={text('reference')} minLength={2} maxLength={64} pattern="[a-zA-Z0-9][a-zA-Z0-9_-]+" required disabled={disabled} error={errors.reference?.[0]} />
      {loading && <p role="status">{text('loading')}</p>}{error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
      <SelectField name="game_id" title={text('game')} value={choice?.id ?? ''} required disabled={disabled || loading} error={errors.game_id?.[0]} onChange={(id) => setChoice(list?.data.find((entry) => entry.id === id) ?? null)}>
        <option value="">{text('select')}</option>{choice && !list?.data.some((entry) => entry.id === choice.id) && <option value={choice.id}>{choice.label}</option>}{list?.data.map((entry) => <option key={entry.id} value={entry.id}>{entry.label}</option>)}
      </SelectField>
      {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={disabled || loading} onPageChange={setPage} />}
    </>}
    {(['name', 'organizer', 'region', 'season'] as const).map((field) => <FormField key={field} name={field} label={text(field)} defaultValue={item?.[field] ?? ''} maxLength={field === 'region' ? 80 : field === 'season' ? 100 : 200} required disabled={disabled} error={errors[field]?.[0]} />)}
    <FormField name="website_url" label={text('url')} type="url" pattern="https://.*" maxLength={2000} defaultValue={item?.website_url ?? ''} disabled={disabled} error={errors.website_url?.[0]} />
    <h4 className="font-bold">{text('contacts')}</h4>
    {errors.contacts?.[0] && <Alert tone="error" title={errors.contacts[0]} />}
    {contacts.map((contact, index) => <fieldset key={contact.key} className="grid min-w-0 gap-3 rounded-lg border border-slate-700 p-3"><legend>{text('contact')} {index + 1}</legend>
      {(['name', 'role', 'email', 'phone'] as const).map((field) => <FormField key={field} name={`contacts.${index}.${field}`} label={text('contact.' + field)} defaultValue={contact[field] ?? ''} type={field === 'email' ? 'email' : 'text'} required={field === 'name'} maxLength={field === 'name' ? 160 : field === 'role' ? 120 : field === 'email' ? 254 : 40} disabled={disabled} error={errors[`contacts.${index}.${field}`]?.[0]} />)}
      <Button disabled={disabled} onClick={() => setContacts((current) => current.filter((entry) => entry.key !== contact.key))}>{text('removeContact')} {index + 1}</Button>
    </fieldset>)}
    {contacts.length < 10 && <Button disabled={disabled} onClick={() => { const key = nextKey.current++; setContacts((current) => [...current, { key, name: '', role: null, email: null, phone: null }]) }}>{text('addContact')}</Button>}
    <FormField name="reason" label={text('reason')} minLength={10} maxLength={1000} required disabled={disabled} error={errors.reason?.[0]} />
    <div className="flex flex-wrap gap-3"><Button type="submit" disabled={disabled || (!item && !choice)}>{text('save')}</Button><Button disabled={disabled} onClick={onCancel}>{text('cancel')}</Button></div>
  </form>
}
