import { useState } from 'react'
import type { ActiveOrganization } from '../../api/tenancy'
import { mediaText as text, safeMediaUrl, type MediaItem } from '../../api/media'
import { Button } from '../Button'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { usePartnershipList } from '../partnerships/usePartnershipList'

export function ContextMedia({ active, type, id, disabled, onInvalid }: { active: ActiveOrganization; type: 'event' | 'match'; id: string; disabled: boolean; onInvalid: () => void }) {
  const [open, setOpen] = useState(false), [page, setPage] = useState(1), [refresh, setRefresh] = useState(0)
  const permissions = active.context?.permissions ?? []
  const allowed = permissions.includes('media.read') && permissions.includes(type === 'event' ? 'calendar.read' : 'sports.read')
  const path = 'tenants/' + active.context?.organization_id + '/media?context_type=' + type + '&context_id=' + id + '&per_page=10&page=' + page
  const { list, loading, error } = usePartnershipList<MediaItem>(path, active, open && allowed, onInvalid, refresh)
  if (!allowed) return null
  return <div className="grid min-w-0 gap-3">
    <Button disabled={disabled} onClick={() => setOpen(!open)}>{text(open ? 'closeContextMedia' : 'openContextMedia')}</Button>
    {open && <section aria-label={text('contextMedia')} className="grid min-w-0 gap-3 rounded-xl border border-slate-700 p-3">
      <h4 className="font-bold">{text('contextMedia')}</h4><p className="text-sm text-slate-400">{text('manual')}</p>
      <Button disabled={disabled || loading} onClick={() => setRefresh((value) => value + 1)}>{text('refresh')}</Button>
      {loading && <p role="status">{text('loading')}</p>}{error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
      {list?.data.length === 0 && <p>{text('empty')}</p>}
      <ul className="grid min-w-0 gap-3">{list?.data.map((item) => <li key={item.id} className="grid min-w-0 gap-1 border-b border-slate-700 pb-2">
        <p className="font-bold [overflow-wrap:anywhere]">{item.title}</p><p>{text('kind.' + item.kind)} · {text('status.' + item.status)} · {text('visibility.' + item.effective_visibility)}</p>
        <p className="text-sm text-slate-400">{text('observed')} : {new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'short', timeZone: active.context!.timezone }).format(new Date(item.status_observed_at))}</p>
        {safeMediaUrl(item.url) && <a href={safeMediaUrl(item.url)!} target="_blank" rel="noopener noreferrer" referrerPolicy="no-referrer" className="w-fit text-cyan-300 underline [overflow-wrap:anywhere]">{text('external')}</a>}
      </li>)}</ul>
      {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={disabled || loading} onPageChange={setPage} />}
    </section>}
  </div>
}
