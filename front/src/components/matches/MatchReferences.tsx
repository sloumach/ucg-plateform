import { useState } from 'react'
import type { ActiveOrganization, Page } from '../../api/tenancy'
import { matchText as text, type MatchChoice, type MatchParticipantKind, type MatchSelection } from '../../api/matches'
import { usePartnershipList } from '../partnerships/usePartnershipList'
import { SelectField } from '../people/peopleForms'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { Button } from '../Button'

type Props = { active: ActiveOrganization; root: string; disabled: boolean; onInvalid: () => void }
type ChoiceProps = Props & { name?: string; type: 'game' | MatchParticipantKind; title: string; value: string; label?: string; game?: string; error?: string; load?: boolean; onChange: (choice: MatchChoice | null) => void }
export function MatchReferenceSelect({ active, root, disabled, onInvalid, type, title, value, label, game, error, load = true, name, onChange }: ChoiceProps) {
  const [page, setPage] = useState(1)
  const params = new URLSearchParams({ page: String(page), per_page: '10', ...(game ? { game_id: game } : {}) })
  const { list, loading, error: failure } = usePartnershipList<MatchChoice, Page<MatchChoice>>(root + '/references/' + type + '?' + params, active, load, onInvalid)
  return <div className="grid min-w-0 gap-2">
    <SelectField name={name ?? 'reference_' + type} title={title} value={value} disabled={disabled || loading} error={error} onChange={(id) => onChange(list?.data.find((row) => row.id === id) ?? null)}>
      <option value="">{text('select')}</option>
      {value && !list?.data.some((row) => row.id === value) && <option value={value}>{label ?? text('storedReference')}</option>}
      {list?.data.map((row) => <option key={row.id} value={row.id}>{row.label}</option>)}
    </SelectField>
    {loading && <p role="status">{text('loadingChoices')}</p>}
    {failure && <Alert tone="error" title={failure.message} requestId={failure.requestId} />}
    {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={disabled || loading} onPageChange={setPage} />}
  </div>
}
export function MatchParticipants({ active, root, disabled, onInvalid, game, selected, onChange, error }: Props & { game: string; selected: MatchSelection[]; onChange: (next: MatchSelection[]) => void; error?: string }) {
  const [kind, setKind] = useState<MatchParticipantKind>('person'), [choice, setChoice] = useState<MatchChoice | null>(null)
  return <fieldset className="grid min-w-0 gap-3 rounded-xl border border-slate-700 p-3">
    <legend>{text('participants')}</legend><p className="text-sm text-slate-400">{text('participantsHint')}</p>
    <SelectField name="participant_kind" title={text('participantKind')} value={kind} disabled={disabled} onChange={(value) => { setKind(value as MatchParticipantKind); setChoice(null) }}>
      {(['person', 'team', 'opponent_snapshot'] as const).map((type) => <option key={type} value={type}>{text('source.' + type)}</option>)}
    </SelectField>
    {game && <MatchReferenceSelect key={kind + game} {...{ active, root, disabled, onInvalid, game }} type={kind} title={text('participantChoice')} value={choice?.id ?? ''} label={choice?.label} onChange={setChoice} />}
    <Button disabled={disabled || !choice || selected.length >= 100 || selected.some((p) => p.kind === kind && p.source_id === choice.id)} onClick={() => {
      if (choice) { onChange([...selected, { kind, source_id: choice.id, label: choice.label }]); setChoice(null) }
    }}>{text('add')}</Button>
    {error && <p role="alert" className="text-sm text-rose-200">{error}</p>}
    <ol className="grid min-w-0 gap-2">{selected.map((p, index) => <li key={p.kind + p.source_id} className="grid min-w-0 gap-2">
      <span className="[overflow-wrap:anywhere]">{index + 1}. {text('source.' + p.kind)} · {p.label}</span>
      <Button disabled={disabled} aria-label={text('remove') + ' ' + p.label} onClick={() => onChange(selected.filter((_, i) => i !== index))}>{text('remove')}</Button>
    </li>)}</ol>
  </fieldset>
}
