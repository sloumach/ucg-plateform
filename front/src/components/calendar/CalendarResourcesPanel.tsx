import { useState } from 'react'
import type { ActiveOrganization } from '../../api/tenancy'
import { bookingText as text, type CalendarResource } from '../../api/calendarConflicts'
import { usePartnershipList } from '../partnerships/usePartnershipList'
import { usePeopleMutation } from '../people/usePeopleMutation'
import { SelectField } from '../people/peopleForms'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
export function CalendarResourcesPanel({ active, root, disabled, onInvalid }: { active: ActiveOrganization; root: string; disabled: boolean; onInvalid: () => void }) {
  const [open, setOpen] = useState(false), [page, setPage] = useState(1), [refresh, setRefresh] = useState(0), [archived, setArchived] = useState(false)
  const [editor, setEditor] = useState<CalendarResource | 'new' | null>(null)
  const { list, loading, error } = usePartnershipList<CalendarResource>(root + '/resources?page=' + page + '&per_page=10' + (archived ? '&archived=1' : ''), active, open, onInvalid, refresh)
  const mutation = usePeopleMutation<CalendarResource>(active, () => { setEditor(null); setRefresh((n) => n + 1) }, onInvalid)
  const canManage = active.context!.permissions.includes('calendar.resources.manage'), blocked = disabled || mutation.blocked
  return <section aria-label={text('resourceCatalogue')} className="grid min-w-0 gap-3 rounded-xl border border-slate-700 p-3">
    <Button disabled={blocked} onClick={() => { setOpen(!open); setEditor(null) }}>{text(open ? 'closeResources' : 'openResources')}</Button>
    {open && <>
      {mutation.feedback}<p className="text-sm text-slate-400">{text('catalogueHint')}</p>
      <FormField name="archived_resources" label={text('archivedResources')} type="checkbox" checked={archived} disabled={blocked} onChange={(event) => { setArchived(event.target.checked); setPage(1); setEditor(null) }} />
      {loading && <p role="status">{text('loading')}</p>}{error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
      {canManage && <Button disabled={blocked} onClick={() => setEditor('new')}>{text('newResource')}</Button>}
      {list?.data.length === 0 && <p>{text('emptyResources')}</p>}
      <ul className="grid min-w-0 gap-3">{list?.data.map((resource) => <li key={resource.id} className="grid min-w-0 gap-2 border-b border-slate-700 pb-3">
        <p className="[overflow-wrap:anywhere]">{resource.label} · {text('kind.' + resource.kind)} · {resource.key}</p>
        {canManage && resource.status === 'active' && <div className="flex flex-wrap gap-3"><Button disabled={blocked} onClick={() => setEditor(resource)}>{text('editResource')}</Button>
          <form onSubmit={(event) => mutation.propose(event, text('archiveResource'), root + '/resources/' + resource.id, { version: resource.version, archive: true }, true)}><Button type="submit" disabled={blocked}>{text('archiveResource')}</Button></form>
        </div>}
      </li>)}</ul>
      {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={blocked || loading} onPageChange={setPage} />}
      {editor && <form key={editor === 'new' ? 'new' : editor.id + editor.version} aria-label={text('saveResource')} className="grid min-w-0 gap-3" onSubmit={(event) => {
        event.preventDefault(); const data = new FormData(event.currentTarget), label = String(data.get('label') ?? '').trim()
        const body = editor === 'new' ? { key: String(data.get('key') ?? ''), kind: String(data.get('kind') ?? ''), label }
          : { version: editor.version, ...(label === editor.label ? {} : { label }) }
        mutation.propose(event, text('saveResource'), root + '/resources' + (editor === 'new' ? '' : '/' + editor.id), body, editor !== 'new')
      }}>
        {editor === 'new' && <><FormField name="key" label={text('resourceKey')} required maxLength={80} disabled={blocked} error={mutation.errors(root + '/resources').key?.[0]} />
          <SelectField name="kind" title={text('resourceKind')} defaultValue="room" disabled={blocked}>{['room','equipment','other'].map((kind) => <option key={kind} value={kind}>{text('kind.' + kind)}</option>)}</SelectField></>}
        <FormField name="label" label={text('resourceLabel')} defaultValue={editor === 'new' ? '' : editor.label} required maxLength={120} disabled={blocked} error={mutation.errors(root + '/resources' + (editor === 'new' ? '' : '/' + editor.id)).label?.[0]} />
        <div className="flex flex-wrap gap-3"><Button type="submit" disabled={blocked}>{text('saveResource')}</Button><Button disabled={blocked} onClick={() => setEditor(null)}>{text('closeForm')}</Button></div>
      </form>}
    </>}
  </section>
}
