import { useEffect, useState, type FormEvent } from 'react'
import type { ActiveOrganization } from '../../api/tenancy'
import { searchText as text, searchTypes, type SearchType, type SearchPage } from '../../api/search'
import { useDashboardRead } from './useDashboardRead'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { SelectField } from '../people/peopleForms'
import { Alert } from '../feedback/Alert'

type Props = { active: ActiveOrganization; disabled: boolean; onInvalid: () => void }
type Selection = { term: string; type: SearchType; cursors: (string | null)[]; page: number; revision: number }
export function TenantSearchPanel(props: Props) {
  const context = props.active.context
  if (!context?.permissions.some((permission) => ['sports.read', 'administrative.read'].includes(permission))) return null
  return <Search key={context.organization_id + ':' + context.actor_user_id + ':' + props.active.revision + ':' + context.permissions.join(',')} {...props} />
}
function Search({ active, disabled, onInvalid }: Props) {
  const [open, setOpen] = useState(false), [term, setTerm] = useState(''), [type, setType] = useState<SearchType>('people')
  const [selection, setSelection] = useState<Selection | null>(null), [focused, setFocused] = useState(false), [debounced, setDebounced] = useState('')
  const [catalogueRevision, setCatalogueRevision] = useState(0)
  const root = 'tenants/' + active.context!.organization_id + '/search'
  const catalogue = useDashboardRead<{ types: SearchType[] }>(open ? root + '/types' : null, active, onInvalid, catalogueRevision)
  const available = catalogue.data?.types.filter((value) => searchTypes.includes(value)) ?? []
  const chosen = available.includes(type) ? type : available[0]
  const suggestionKey = chosen + ':' + term.trim()
  const wantsSuggestions = open && focused && !selection && term.trim().length >= 2 && !!chosen && !disabled
  useEffect(() => {
    if (!wantsSuggestions) return
    const timer = setTimeout(() => setDebounced(suggestionKey), 350)
    return () => clearTimeout(timer)
  }, [wantsSuggestions, suggestionKey])
  const suggestions = useDashboardRead<SearchPage>(wantsSuggestions && debounced === suggestionKey ? root + '?' + new URLSearchParams({ q: term.trim(), type: chosen, mode: 'suggest' }) : null, active, onInvalid)
  const result = useDashboardRead<SearchPage>(selection ? root + '?' + new URLSearchParams({ q: selection.term, type: selection.type, per_page: '10', ...(selection.cursors[selection.page] ? { cursor: selection.cursors[selection.page]! } : {}) }) : null, active, onInvalid, selection?.revision ?? 0)
  function search(value: string) {
    if (!chosen || value.trim().length < 2 || disabled) return
    setSelection({ term: value.trim(), type: chosen, cursors: [null], page: 0, revision: (selection?.revision ?? 0) + 1 }); setFocused(false); setDebounced('')
  }
  function submit(event: FormEvent<HTMLFormElement>) { event.preventDefault(); search(term) }
  const error = result.error ?? suggestions.error ?? catalogue.error
  return <section aria-label={text('title')} className="grid min-w-0 gap-4 rounded-xl border border-slate-700 p-4 [overflow-wrap:anywhere]">
    <h2 className="text-xl font-bold">{text('title')}</h2><p>{text('hint')}</p>
    <Button disabled={disabled} onClick={() => { setOpen(!open); setSelection(null); setTerm(''); setDebounced(''); setFocused(false) }}>{text(open ? 'close' : 'open')}</Button>
    {open && <>
      {catalogue.loading && <p role="status">{text('loading')}</p>}
      {error && <><Alert tone="error" title={error.message} requestId={error.requestId} />{Object.entries(error.fieldErrors).map(([field, messages]) => <p key={field} role="alert">{messages.join(' ')}</p>)}</>}
      {catalogue.error && <Button disabled={disabled} onClick={() => setCatalogueRevision((n) => n + 1)}>{text('retry')}</Button>}
      {chosen && <form aria-label={text('filters')} onSubmit={submit} className="grid min-w-0 gap-3" onBlur={(event) => { if (!event.currentTarget.contains(event.relatedTarget as Node | null)) setFocused(false) }}>
        <SelectField name="search_type" title={text('type')} value={chosen} disabled={disabled} onChange={(value) => { setType(value as SearchType); setSelection(null); setDebounced('') }}>
          {available.map((value) => <option key={value} value={value}>{text('type.' + value)}</option>)}
        </SelectField>
        <FormField name="search_term" label={text('term')} value={term} minLength={2} maxLength={80} autoComplete="off" required disabled={disabled} onFocus={() => setFocused(true)} onKeyDown={(event) => { if (event.key === 'Escape') { setFocused(false); setDebounced('') } }} onChange={(event) => { setTerm(event.target.value); setSelection(null); setDebounced(''); setFocused(true) }} />
        {wantsSuggestions && suggestions.loading && <p role="status">{text('suggestLoading')}</p>}
        {wantsSuggestions && suggestions.data && <div><p>{text('suggestions')}</p>
          <ul aria-label={text('suggestions')} className="grid min-w-0 gap-2">{suggestions.data.items.map((item) => <li key={item.id}><Button type="button" disabled={disabled} onClick={() => { const value = Array.from(item.label).slice(0, 80).join(''); setTerm(value); search(value) }}>{item.label}{item.reference ? ' · ' + item.reference : ''}</Button></li>)}</ul>
          {suggestions.data.meta.has_more && <p>{text('suggestPartial')}</p>}
        </div>}
        <Button type="submit" disabled={disabled || term.trim().length < 2 || result.loading}>{text('submit')}</Button>
      </form>}
      {result.loading && <p role="status">{text('loading')}</p>}
      {result.data && selection && <section aria-label={text('results')} className="grid min-w-0 gap-3">
        <h3 className="font-semibold">{text('type.' + result.data.type)} · {selection.term}</h3>
        {result.data.items.length === 0 && <p role="status">{text(result.data.meta.has_more ? 'emptySegment' : selection.page > 0 ? 'end' : 'empty')}</p>}
        <ul className="grid min-w-0 gap-3">{result.data.items.map((item) => <li key={item.id} className="grid min-w-0 gap-1 rounded-xl border border-slate-700 p-3"><p className="font-medium">{item.label}</p>{item.reference && <p>{text('reference')} : {item.reference}</p>}</li>)}</ul>
        {result.data.meta.has_more && <p>{text('more')}</p>}
        <div className="flex flex-wrap gap-3">
          {selection.page > 0 && <Button disabled={disabled || result.loading} onClick={() => setSelection({ ...selection, page: selection.page - 1 })}>{text('previous')}</Button>}
          {result.data.meta.next_cursor && <Button disabled={disabled || result.loading} onClick={() => setSelection({ ...selection, cursors: [...selection.cursors.slice(0, selection.page + 1), result.data!.meta.next_cursor], page: selection.page + 1 })}>{text('next')}</Button>}
          <Button disabled={disabled || result.loading} onClick={() => search(selection.term)}>{text('restart')}</Button>
        </div>
      </section>}
      {result.error && selection && <Button disabled={disabled} onClick={() => setSelection({ ...selection, revision: selection.revision + 1 })}>{text('retry')}</Button>}
    </>}
  </section>
}
