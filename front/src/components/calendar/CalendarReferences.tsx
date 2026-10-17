import { useState } from 'react'
import type { ActiveOrganization } from '../../api/tenancy'
import { calendarText as text, type CalendarReference } from '../../api/calendar'
import { Button } from '../Button'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { SelectField } from '../people/peopleForms'
import { usePartnershipList } from '../partnerships/usePartnershipList'
type Props = { active: ActiveOrganization; root: string; disabled: boolean; onInvalid: () => void; error?: string }
export function CalendarTeamSelect({ active, root, disabled, onInvalid, error, value, onChange, currentLabel }: Props & { value: string; onChange: (value: string) => void; currentLabel: string | null }) {
  const [page, setPage] = useState(1)
  const { list, loading, error: failure } = usePartnershipList<CalendarReference>(root + '/teams?page=' + page + '&per_page=10', active, true, onInvalid)
  return <div className="grid min-w-0 gap-2">
    <SelectField name="team_id" title={text('team')} value={value} onChange={onChange} disabled={disabled} error={error}>
      <option value="">{text('noTeam')}</option>
      {value && !list?.data.some((team) => team.id === value) && <option value={value}>{currentLabel ?? text('currentTeam')}</option>}
      {list?.data.map((team) => <option key={team.id} value={team.id}>{team.label}</option>)}
    </SelectField>
    {loading && <p role="status">{text('loading')}</p>}
    {failure && <Alert tone="error" title={failure.message} requestId={failure.requestId} />}
    {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={disabled || loading} onPageChange={setPage} />}
  </div>
}
export function CalendarParticipantPicker({ active, root, disabled, onInvalid, error, selected, onChange }: Props & { selected: CalendarReference[]; onChange: (selected: CalendarReference[]) => void }) {
  const [page, setPage] = useState(1)
  const { list, loading, error: failure } = usePartnershipList<CalendarReference>(root + '/people?page=' + page + '&per_page=10', active, true, onInvalid)
  return <fieldset className="grid min-w-0 gap-3 rounded-xl border border-slate-700 p-3">
    <legend>{text('participants')} ({selected.length}/50)</legend>
    <p className="text-sm text-slate-400">{text('participantsHint')}</p>
    <ul className="grid min-w-0 gap-2">{selected.map((person) => <li key={person.id} className="flex min-w-0 flex-wrap items-center justify-between gap-2">
      <span className="[overflow-wrap:anywhere]">{person.label ?? text('unavailablePerson')}</span>
      <Button disabled={disabled} aria-label={text('remove') + ' ' + (person.label ?? text('unavailablePerson'))} onClick={() => onChange(selected.filter((item) => item.id !== person.id))}>{text('remove')}</Button>
    </li>)}</ul>
    {list?.data.map((person) => <label key={person.id} className="flex min-w-0 items-center gap-3 [overflow-wrap:anywhere]">
      <input type="checkbox" checked={selected.some((item) => item.id === person.id)} disabled={disabled || (selected.length >= 50 && !selected.some((item) => item.id === person.id))}
        onChange={(event) => onChange(event.target.checked ? [...selected, person] : selected.filter((item) => item.id !== person.id))} />
      {person.label}
    </label>)}
    {loading && <p role="status">{text('loading')}</p>}
    {failure && <Alert tone="error" title={failure.message} requestId={failure.requestId} />}
    {error && <p role="alert" className="text-sm text-rose-200">{error}</p>}
    {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={disabled || loading} onPageChange={setPage} />}
  </fieldset>
}
