import { useState } from 'react'
import type { ActiveOrganization } from '../../api/tenancy'
import { bookingText as text, type CalendarResource, type CalendarBookingResource } from '../../api/calendarConflicts'
import { usePartnershipList } from '../partnerships/usePartnershipList'
import { Button } from '../Button'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
export function CalendarResourcePicker({ active, root, disabled, onInvalid, selected, onChange, error }: {
  active: ActiveOrganization; root: string; disabled: boolean; onInvalid: () => void; selected: CalendarBookingResource[]; onChange: (value: CalendarBookingResource[]) => void; error?: string
}) {
  const [page, setPage] = useState(1)
  const { list, loading, error: failure } = usePartnershipList<CalendarResource>(root + '/resources?page=' + page + '&per_page=10', active, true, onInvalid)
  return <fieldset className="grid min-w-0 gap-3 rounded-xl border border-slate-700 p-3">
    <legend>{text('resources')} ({selected.length}/20)</legend><p className="text-sm text-slate-400">{text('resourcesHint')}</p>
    {selected.map((resource) => <div key={resource.id} className="flex min-w-0 flex-wrap items-center justify-between gap-2">
      <span className="[overflow-wrap:anywhere]">{resource.label ?? text('unknownResource')}{resource.archived ? ' · ' + text('archivedResource') : ''}</span>
      <Button disabled={disabled} aria-label={text('removeResource') + ' ' + resource.label} onClick={() => onChange(selected.filter((item) => item.id !== resource.id))}>{text('removeResource')}</Button>
    </div>)}
    {list?.data.map((resource) => <label key={resource.id} className="flex min-w-0 items-center gap-3 [overflow-wrap:anywhere]">
      <input type="checkbox" checked={selected.some((item) => item.id === resource.id)} disabled={disabled || (selected.length >= 20 && !selected.some((item) => item.id === resource.id))}
        onChange={(event) => onChange(event.target.checked ? [...selected, { id: resource.id, label: resource.label }] : selected.filter((item) => item.id !== resource.id))} />
      {resource.label}
    </label>)}
    {loading && <p role="status">{text('loading')}</p>}{failure && <Alert tone="error" title={failure.message} requestId={failure.requestId} />}{error && <p role="alert">{error}</p>}
    {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={disabled || loading} onPageChange={setPage} />}
  </fieldset>
}
