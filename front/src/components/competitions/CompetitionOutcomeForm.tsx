import { useState, type FormEvent } from 'react'
import type { ActiveOrganization } from '../../api/tenancy'
import { awardCurrencies, awardInput, awardMinor, outcomeText as text, type CompetitionOutcome, type OutcomeMatch, type OutcomeStream } from '../../api/competitionOutcomes'
import { usePartnershipList } from '../partnerships/usePartnershipList'
import { SelectField } from '../people/peopleForms'
import { FormField } from '../FormField'
import { Pagination } from '../data/Pagination'
import { Button } from '../Button'
import { Alert } from '../feedback/Alert'
type DraftLink = { key: string; reference_id: string | null; match_id: string | null; match_version: string | null; title: string }
type Props = { active: ActiveOrganization; path: string; stream: OutcomeStream; record: CompetitionOutcome | null; disabled: boolean; errors: Record<string, string[]>; onInvalid: () => void; onCancel: () => void; onSubmit: (event: FormEvent<HTMLFormElement>, body: Record<string, unknown>) => void }
export function CompetitionOutcomeForm({ active, path, stream, record, disabled, errors, onInvalid, onCancel, onSubmit }: Props) {
  const p = record?.payload, [currency, setCurrency] = useState(p?.currency ?? 'EUR'), [announced, setAnnounced] = useState(awardInput(p?.announced_minor, p?.currency ?? 'EUR')), [received, setReceived] = useState(awardInput(p?.received_minor, p?.currency ?? 'EUR'))
  const [receivedOn, setReceivedOn] = useState(p?.received_on ?? '')
  const [links, setLinks] = useState<DraftLink[]>(() => record?.matches.map((l) => ({ key: l.reference_id, reference_id: l.reference_id, match_id: null, match_version: null, title: l.match?.title ?? text('hidden') })) ?? [])
  const [selected, setSelected] = useState(''), [page, setPage] = useState(1), [moneyError, setMoneyError] = useState<string | null>(null)
  const choices = usePartnershipList<OutcomeMatch>(path + '/choices?page=' + page + '&per_page=10', active, stream === 'sport', onInvalid)
  const field = (name: string, value: string | number | null | undefined, required = false, type = 'text', maxLength = 2000) => <FormField name={name} label={text(name)} defaultValue={value ?? ''} required={required} type={type} maxLength={maxLength} disabled={disabled} error={errors[name]?.[0]} />
  return <form aria-label={text('save')} className="grid min-w-0 gap-3" onSubmit={(event) => {
    event.preventDefault(); const f = new FormData(event.currentTarget), value = (name: string) => String(f.get(name) ?? '').trim(), nullable = (name: string) => value(name) || null
    let money: Record<string, unknown> = {}
    if (stream === 'awards') {
      try { money = { currency, announced_minor: awardMinor(announced, currency), received_minor: awardMinor(received, currency), received_on: nullable('received_on') }; setMoneyError(null) }
      catch (failure) { setMoneyError(failure instanceof Error ? failure.message : text('moneyError')); const bad = [ ['announced_minor', announced], ['received_minor', received] ].find(([, amount]) => { try { awardMinor(amount, currency); return false } catch { return true } }); const input = event.currentTarget.elements.namedItem(bad?.[0] ?? 'announced_minor'); if (input instanceof HTMLElement) input.focus(); return }
    }
    onSubmit(event, { expected_version: record?.version ?? null, source: value('source'), source_url: nullable('source_url'), observed_on: value('observed_on'), notes: nullable('notes'), reason: value('reason'),
      ...(stream === 'sport' ? { place: value('place') ? Number(value('place')) : null, field_size: value('field_size') ? Number(value('field_size')) : null, matches: links.map(({ reference_id, match_id, match_version }) => ({ reference_id, match_id, match_version })) } : money) })
  }}>
    {field('source', p?.source, true, 'text', 500)}{field('source_url', p?.source_url, false, 'url')}{field('observed_on', p?.observed_on, true, 'date')}
    {stream === 'sport' ? <>
      {(['place', 'field_size'] as const).map((name) => <FormField key={name} name={name} label={text(name)} type="number" min={1} max={1000000} step={1} defaultValue={p?.[name] ?? ''} disabled={disabled} error={errors[name]?.[0]} />)}
      <h4 className="font-bold">{text('matches')}</h4>{errors.matches && <Alert tone="error" title={errors.matches[0]} />}
      <ol className="grid min-w-0 gap-2">{links.map((link, index) => <li key={link.key} className="grid min-w-0 gap-2 [overflow-wrap:anywhere]"><p>{link.title}</p>{errors['matches.' + index + '.match_id'] && <Alert tone="error" title={errors['matches.' + index + '.match_id'][0]} />}<Button disabled={disabled} aria-label={text('remove') + ' ' + (index + 1)} onClick={() => setLinks((old) => old.filter((l) => l.key !== link.key))}>{text('remove')}</Button></li>)}</ol>
      {choices.loading && <p role="status">{text('loading')}</p>}{choices.error && <Alert tone="error" title={choices.error.message} />}
      <SelectField name="selected_match" title={text('choose')} value={selected} onChange={setSelected} disabled={disabled || links.length >= 20}><option value="">—</option>{choices.list?.data.filter((m) => !links.some((l) => l.match_id === m.id) && !record?.matches.some((l) => l.match?.id === m.id && links.some((draft) => draft.reference_id === l.reference_id))).map((m) => <option key={m.id} value={m.id}>{m.title}</option>)}</SelectField>
      <Button disabled={disabled || !selected || links.length >= 20} onClick={() => { const m = choices.list?.data.find((candidate) => candidate.id === selected); if (m) { setLinks((old) => [...old, { key: m.id, reference_id: null, match_id: m.id, match_version: m.version, title: m.title }]); setSelected('') } }}>{text('add')}</Button>
      {choices.list?.data.length === 0 && <p>{text('noChoices')}</p>}{choices.list && <Pagination currentPage={choices.list.meta.current_page} totalPages={choices.list.meta.last_page} disabled={disabled || choices.loading} onPageChange={(n) => { setSelected(''); setPage(n) }} />}
    </> : <>
      <SelectField name="currency" title={text('currency')} value={currency} onChange={(v) => { setCurrency(v); setAnnounced(''); setReceived(''); setReceivedOn('') }} disabled={disabled}>{awardCurrencies.map((c) => <option key={c} value={c}>{c}</option>)}</SelectField>
      <p className="text-sm text-slate-400">{text('currencyHint')}</p>{moneyError && <Alert tone="error" title={moneyError} />}
      <FormField name="announced_minor" label={text('announced_minor')} value={announced} onChange={(e) => setAnnounced(e.target.value)} maxLength={16} inputMode="decimal" disabled={disabled} error={errors.announced_minor?.[0]} />
      <FormField name="received_minor" label={text('received_minor')} value={received} onChange={(e) => setReceived(e.target.value)} maxLength={16} inputMode="decimal" disabled={disabled} error={errors.received_minor?.[0]} />
      <FormField name="received_on" label={text('received_on')} type="date" value={receivedOn} onChange={(e) => setReceivedOn(e.target.value)} required={received.trim() !== ''} disabled={disabled} error={errors.received_on?.[0]} />
    </>}
    {field('notes', p?.notes)}<FormField name="reason" label={text('reason')} required minLength={10} maxLength={1000} disabled={disabled} error={errors.reason?.[0]} />
    <div className="flex flex-wrap gap-3"><Button type="submit" disabled={disabled}>{text('save')}</Button><Button disabled={disabled} onClick={onCancel}>{text('cancel')}</Button></div>
  </form>
}
