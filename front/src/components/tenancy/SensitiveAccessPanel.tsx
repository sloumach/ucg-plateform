import { useEffect, useState, type FormEvent } from 'react'
import { tenancyRequest, type ActiveOrganization } from '../../api/tenancy'
import { isAbortError } from '../../api/errors'
import { sensitiveActions, sensitiveText as text, type SensitiveAccessPage } from '../../api/sensitiveAccess'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { SelectField } from '../people/peopleForms'
import { apiError, shouldReloadContext } from '../people/peopleFormValues'
import { Alert } from '../feedback/Alert'

export function SensitiveAccessPanel({ active, disabled, onInvalid }: { active: ActiveOrganization; disabled: boolean; onInvalid: () => void }) {
  const org = active.context?.organization_id, revision = active.revision ?? undefined
  const allowed = active.context?.permissions.includes('audit.view') ?? false
  const [open, setOpen] = useState(false), [loading, setLoading] = useState(false), [refresh, setRefresh] = useState(0)
  const [filters, setFilters] = useState<Record<string, string>>({})
  const [cursors, setCursors] = useState<(string | null)[]>([null]), [position, setPosition] = useState(0)
  const [page, setPage] = useState<SensitiveAccessPage | null>(null), [error, setError] = useState<ReturnType<typeof apiError> | null>(null)
  const cursor = cursors[position] ?? null
  useEffect(() => {
    if (!allowed || !org || !open) return
    const controller = new AbortController()
    const load = async () => {
      setLoading(true); setPage(null); setError(null)
      try {
        const query = new URLSearchParams({ source: 'tenancy.sensitive', per_page: '10', ...filters, ...(cursor ? { cursor } : {}) })
        const result = await tenancyRequest<SensitiveAccessPage>('tenants/' + org + '/audit-events?' + query, { revision, signal: controller.signal })
        if (!controller.signal.aborted) setPage(result)
      } catch (failure) {
        if (controller.signal.aborted || isAbortError(failure)) return
        const next = apiError(failure); setError(next)
        if (shouldReloadContext(next)) onInvalid()
      } finally { if (!controller.signal.aborted) setLoading(false) }
    }
    void load(); return () => controller.abort()
  }, [allowed, org, revision, open, cursor, filters, refresh, onInvalid])
  if (!allowed || !org) return null
  const blocked = disabled || loading
  function filter(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setFilters(Object.fromEntries([...new FormData(event.currentTarget)].filter(([, value]) => value !== '').map(([key, value]) => [key, String(value)])))
    setCursors([null]); setPosition(0)
  }
  return <section aria-label={text('title')} className="grid min-w-0 gap-4 rounded-xl border border-slate-700 p-4">
    <Button disabled={disabled} onClick={() => setOpen((value) => !value)}>{open ? text('close') : text('title')}</Button>
    {open && <>
      <p className="text-sm text-slate-400 [overflow-wrap:anywhere]">{text('hint')}</p>
      <form aria-label={text('filter')} className="grid min-w-0 gap-3 sm:grid-cols-2" onSubmit={filter}>
        <SelectField name="action" title={text('action')} disabled={blocked}><option value="">{text('all')}</option>
          {sensitiveActions.map((action) => <option key={action} value={action}>{text(action)}</option>)}
        </SelectField>
        <FormField name="actor_user_id" label={text('actorFilter')} inputMode="numeric" pattern="[0-9]*" maxLength={19} disabled={blocked} />
        <Button type="submit" disabled={blocked}>{text('filter')}</Button>
      </form>
      {loading && <p role="status">{text('loading')}</p>}
      {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
      {page?.data.length === 0 && <p>{text('empty')}</p>}
      {page && <ul className="grid min-w-0 gap-3">{page.data.map((entry) => <li key={entry.id} className="grid min-w-0 gap-2 rounded-lg border border-slate-700 p-3 [overflow-wrap:anywhere]">
        <h3 className="font-semibold">{text(entry.action)}</h3>
        <p>{new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'medium', timeZone: active.context?.timezone ?? 'UTC' }).format(new Date(entry.occurred_at))}</p>
        <p>{text('actor')} : {entry.actor_user_id ?? '—'}</p>
        <p>{text('purpose')} : {text(entry.reason_code)}</p>
        <p>{text(entry.target.type)} : <span className="font-mono text-sm">{entry.target.id ?? '—'}</span></p>
        <details><summary>{text('trace')}</summary><p className="font-mono text-xs">{entry.request_id}</p></details>
      </li>)}</ul>}
      <div className="flex flex-wrap gap-2">
        <Button disabled={blocked || position === 0} onClick={() => setPosition((value) => value - 1)}>{text('previous')}</Button>
        <Button disabled={blocked || !page?.meta.next_cursor} onClick={() => {
          if (page?.meta.next_cursor) { setCursors([...cursors.slice(0, position + 1), page.meta.next_cursor]); setPosition(position + 1) }
        }}>{text('next')}</Button>
        <Button disabled={blocked} onClick={() => { setCursors([null]); setPosition(0); setRefresh((value) => value + 1) }}>{text('refresh')}</Button>
      </div>
    </>}
  </section>
}
