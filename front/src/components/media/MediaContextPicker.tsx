import { useState } from 'react'
import type { ActiveOrganization } from '../../api/tenancy'
import { mediaText as text, type MediaContextTarget, type MediaItem } from '../../api/media'
import { SelectField } from '../people/peopleForms'
import { Pagination } from '../data/Pagination'
import { usePartnershipList } from '../partnerships/usePartnershipList'
import { Alert } from '../feedback/Alert'

export function MediaContextPicker({ active, root, disabled, onInvalid, item, type, selected, onType, onSelect, errors }: {
  active: ActiveOrganization; root: string; disabled: boolean; onInvalid: () => void; item: MediaItem | null; type: string; selected: MediaContextTarget | null
  onType: (type: string) => void; onSelect: (target: MediaContextTarget | null) => void; errors: Record<string, string[]>
}) {
  const [page, setPage] = useState(1)
  const { list, loading, error } = usePartnershipList<MediaContextTarget>(root + '/contexts?type=' + type + '&per_page=10&page=' + page, active, !!type && !item?.context_id, onInvalid)
  if (item?.context_id) return <div className="grid min-w-0 gap-2 sm:col-span-2"><p className="[overflow-wrap:anywhere]">{text('context.' + item.context_type)} : {item.context_label}</p><p className="text-sm text-slate-400">{text('contextFixed')}</p>{errors.context_version && <Alert tone="error" title={errors.context_version[0]} />}</div>
  const permissions = active.context?.permissions ?? []
  return <div className="grid min-w-0 gap-3 sm:col-span-2">
    <SelectField name="context_type" title={text('contextType')} value={type} disabled={disabled} error={errors.context_type?.[0]} onChange={(value) => { onType(value); onSelect(null); setPage(1) }}>
      <option value="">{text('context.none')}</option>
      {permissions.includes('calendar.read') && <option value="event">{text('context.event')}</option>}
      {permissions.includes('sports.read') && <option value="match">{text('context.match')}</option>}
    </SelectField>
    {type && <>
      <SelectField name="context_id" title={text('contextObject')} value={selected?.id ?? ''} disabled={disabled || loading || !!error} error={errors.context_id?.[0]} onChange={(id) => onSelect(list?.data.find((target) => target.id === id) ?? null)}>
        <option value="">{text('chooseContext')}</option>
        {selected && !list?.data.some((target) => target.id === selected.id) && <option value={selected.id}>{selected.label}</option>}
        {list?.data.map((target) => <option key={target.id} value={target.id}>{target.label}</option>)}
      </SelectField>
      {loading && <p role="status">{text('loading')}</p>}{error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
      {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={disabled || loading} onPageChange={setPage} />}
      {selected && <p className="text-sm text-slate-400">{text(selected.is_scrim ? 'contextScrim' : 'contextAccess')}</p>}
      {errors.context_version && <Alert tone="error" title={errors.context_version[0]} />}
    </>}
  </div>
}
