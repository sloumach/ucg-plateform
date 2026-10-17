import { useState } from 'react'
import type { ActiveOrganization } from '../../api/tenancy'
import type { CalendarReference } from '../../api/calendar'
import { availabilityText as text } from '../../api/availability'
import { usePartnershipList } from '../partnerships/usePartnershipList'
import { SelectField } from '../people/peopleForms'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'

export function AvailabilityPersonSelect({ active, root, value, onChange, disabled, onInvalid, error, required = false }: {
  active: ActiveOrganization; root: string; value: string; onChange: (value: string) => void; disabled: boolean; onInvalid: () => void; error?: string; required?: boolean
}) {
  const [page, setPage] = useState(1), [selected, setSelected] = useState<CalendarReference | null>(null)
  const { list, loading, error: failure } = usePartnershipList<CalendarReference>(root + '/availability-people?page=' + page + '&per_page=10', active, true, onInvalid)
  return <div className="grid min-w-0 gap-2">
    <SelectField name="person_id" title={text('person')} value={value} required={required} disabled={disabled || loading} error={error} onChange={(id) => {
      setSelected(list?.data.find((person) => person.id === id) ?? null); onChange(id)
    }}>
      <option value="">{text(required ? 'choosePerson' : 'allPeople')}</option>
      {value && !list?.data.some((person) => person.id === value) && <option value={value}>{selected?.label ?? text('unknownPerson')}</option>}
      {list?.data.map((person) => <option key={person.id} value={person.id}>{person.label}</option>)}
    </SelectField>
    {loading && <p role="status">{text('loading')}</p>}
    {failure && <Alert tone="error" title={failure.message} requestId={failure.requestId} />}
    {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={disabled || loading} onPageChange={setPage} />}
  </div>
}
