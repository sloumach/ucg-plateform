import { useEffect, useRef, useState, type FormEvent } from 'react'
import { tenancyRequest, type ActiveOrganization } from '../../api/tenancy'
import type { ApiSuccessResponse } from '../../api/contracts'
import { isAbortError } from '../../api/errors'
import { dashboardText as text, type DashboardIndicator } from '../../api/dashboards'
import { Button } from '../Button'
import { Alert } from '../feedback/Alert'
import { Pagination } from '../data/Pagination'
import { useNotifications } from '../feedback/notificationContext'
import { apiError, shouldReloadContext } from '../people/peopleFormValues'
import { useDashboardRead } from './useDashboardRead'

type Props = { active: ActiveOrganization; disabled: boolean; onInvalid: () => void; root: string; query: { view: string; from: string; to: string } }
export function DashboardIndicatorPanel({ active, disabled, onInvalid, root, query }: Props) {
  const { notify } = useNotifications()
  const [page, setPage] = useState(1), [refresh, setRefresh] = useState(0), [pending, setPending] = useState(false)
  const [failure, setFailure] = useState<ReturnType<typeof apiError> | null>(null)
  const [expiredRevision, setExpiredRevision] = useState<string | null>(null)
  const mutation = useRef<AbortController | null>(null)
  useEffect(() => () => mutation.current?.abort(), [])
  const params = new URLSearchParams({ view: query.view, from: query.from, to: query.to, page: String(page) })
  const read = useDashboardRead<DashboardIndicator>(root + '?' + params.toString(), active, onInvalid, refresh)
  const data = read.data, blocked = disabled || pending || read.loading
  const state = data?.state === 'fresh' && expiredRevision === data.version ? 'stale' : data?.state
  useEffect(() => {
    if (data?.state !== 'fresh' || !data.expires_at || !data.version) return
    const version = data.version
    const elapsed = read.startedAt === undefined ? 0 : Math.max(0, performance.now() - read.startedAt)
    const remaining = Math.max(0, Date.parse(data.expires_at) - Date.parse(data.checked_at) - elapsed)
    const timeout = setTimeout(() => setExpiredRevision(version), Math.min(remaining, 2147483647))
    return () => clearTimeout(timeout)
  }, [data, read.startedAt])
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!data || blocked || !data.enabled || !active.confirmed || !new FormData(event.currentTarget).has('confirm')) return
    const controller = new AbortController(); mutation.current = controller
    setPending(true); setFailure(null)
    try {
      const response = await tenancyRequest<ApiSuccessResponse<DashboardIndicator>>(root, { method: 'POST', revision: active.revision ?? undefined, signal: controller.signal,
        body: { view: query.view, from: query.from, to: query.to, confirm: true, ...(data.version ? { version: data.version } : {}) } })
      if (controller.signal.aborted) return
      if (response.notification) notify({ ...response.notification, requestId: response.meta.request_id })
      setRefresh((value) => value + 1)
    } catch (error: unknown) {
      if (controller.signal.aborted || isAbortError(error)) return
      const parsed = apiError(error); setFailure(parsed); notify({ type: 'error', message: parsed.message, requestId: parsed.requestId })
      if (shouldReloadContext(parsed)) onInvalid()
    } finally { if (!controller.signal.aborted) setPending(false) }
  }
  const error = failure ?? read.error
  return <section aria-label={text('indicator.title')} className="grid min-w-0 gap-3">
    <h5 className="font-semibold">{text('indicator.title')}</h5><p>{text('indicator.hint')}</p>
    {read.loading && <p role="status">{text('loading')}</p>}
    {error && <><Alert tone="error" title={error.message} requestId={error.requestId} />{Object.entries(error.fieldErrors).map(([field, messages]) => <p key={field} role="alert">{messages.join(' ')}</p>)}</>}
    {data && <><p role="status">{text('indicator.state.' + state)}</p>
      <p>{text('indicator.engine')} : {data.engine_version}{data.revision !== null && ' · ' + text('indicator.revision') + ' ' + data.revision}</p>
      {data.evaluated_at && <p>{text('indicator.evaluated')} : {new Date(data.evaluated_at).toLocaleString('fr-FR', { timeZone: active.context?.timezone ?? 'UTC' })}</p>}
      {data.expires_at && <p>{text('indicator.expires')} : {new Date(data.expires_at).toLocaleString('fr-FR', { timeZone: active.context?.timezone ?? 'UTC' })}</p>}
      {!pending && !error && state === 'fresh' && data.result && <><p className="text-xl font-bold">{text('indicator.total')} : {data.result.total}</p>
        <ul>{data.result.groups.map((group) => <li key={group.state}>{text('state.' + group.state)} : {group.count}</li>)}</ul></>}
      {!data.enabled && <p>{text('indicator.disabled')}</p>}
      {!active.confirmed && <p>{text('indicator.context')}</p>}
      {data.enabled && state !== 'fresh' && state !== 'queued' && <form onSubmit={submit} className="grid min-w-0 gap-3">
        <label className="flex items-start gap-2"><input type="checkbox" name="confirm" required disabled={blocked || !active.confirmed} className="mt-1" />{text('indicator.confirm')}</label>
        <Button type="submit" disabled={blocked || !active.confirmed}>{text('indicator.calculate')}</Button>
      </form>}
      {data.history && data.history.items.length > 0 && <details><summary>{text('indicator.history')}</summary>
        <ul>{data.history.items.map((row) => <li key={row.number}>{text('indicator.revision')} {row.number} · {row.engine_version} · {new Date(row.evaluated_at).toLocaleString('fr-FR', { timeZone: active.context?.timezone ?? 'UTC' })}</li>)}</ul>
        <Pagination currentPage={data.history.meta.current_page} totalPages={data.history.meta.last_page} disabled={blocked} onPageChange={setPage} />
      </details>}
    </>}
    <Button disabled={blocked} onClick={() => { setFailure(null); setRefresh((value) => value + 1) }}>{text('indicator.refresh')}</Button>
  </section>
}
