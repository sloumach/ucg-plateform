import { useEffect, useRef, useState, type FormEvent } from 'react'
import { tenancyRequest, type ActiveOrganization } from '../../api/tenancy'
import { isAbortError } from '../../api/errors'
import { exportText as text, type ReportExport, type ReportExportPage } from '../../api/reportExports'
import type { DashboardCardDefinition } from '../../api/dashboards'
import { Button } from '../Button'
import { Alert } from '../feedback/Alert'
import { Pagination } from '../data/Pagination'
import { useNotifications } from '../feedback/notificationContext'
import { apiError, shouldReloadContext } from '../people/peopleFormValues'
import { SelectField } from '../people/peopleForms'
import { useDashboardRead } from './useDashboardRead'
import { usePeopleMutation } from '../people/usePeopleMutation'

type Props = { active: ActiveOrganization; disabled: boolean; onInvalid: () => void; cards: DashboardCardDefinition[]; query: { view: string; from: string; to: string } }
export function ReportExportPanel(props: Props) {
  if (!props.active.context?.permissions.includes('reporting.export')) return null
  const key = [props.active.context.organization_id, props.active.context.actor_user_id, props.active.revision, props.active.context.permissions.join(','), JSON.stringify(props.query)].join(':')
  return <Exports key={key} {...props} />
}
function Exports({ active, disabled, onInvalid, cards, query }: Props) {
  const { notify } = useNotifications()
  const [open, setOpen] = useState(false), [page, setPage] = useState(1), [refresh, setRefresh] = useState(0)
  const [selected, setSelected] = useState(''), [pending, setPending] = useState(false)
  const [failure, setFailure] = useState<ReturnType<typeof apiError> | null>(null)
  const [expired, setExpired] = useState<string[]>([])
  const mutation = useRef<AbortController | null>(null), requestKey = useRef(crypto.randomUUID())
  useEffect(() => () => mutation.current?.abort(), [])
  const root = 'tenants/' + active.context?.organization_id + '/exports'
  const requestMutation = usePeopleMutation<ReportExport>(active, () => {
    requestKey.current = crypto.randomUUID()
    setPage(1); setRefresh((value) => value + 1)
  }, onInvalid)
  const read = useDashboardRead<ReportExportPage>(open ? root + '?page=' + page : null, active, onInvalid, refresh)
  useEffect(() => {
    if (!read.data) return
    const elapsed = read.startedAt === undefined ? 0 : Math.max(0, performance.now() - read.startedAt)
    const checkedAt = Date.parse(read.data.checked_at)
    const timers = read.data.items.filter((row) => row.state === 'completed').map((row) => setTimeout(() => {
      setExpired((ids) => ids.includes(row.id) ? ids : [...ids, row.id])
    }, Math.max(0, Date.parse(row.expires_at) - checkedAt - elapsed)))
    return () => timers.forEach(clearTimeout)
  }, [read.data, read.startedAt])
  const choices = cards.filter((card) => card.indicator_supported && card.scope === 'organization' && card.status === 'available')
  const card = choices.find((entry) => entry.id === selected) ?? choices[0]
  const blocked = disabled || pending || read.loading || requestMutation.blocked
  function failed(error: unknown) {
    const parsed = apiError(error); setFailure(parsed)
    notify({ type: 'error', message: parsed.message, requestId: parsed.requestId })
    if (shouldReloadContext(parsed)) onInvalid()
  }
  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (blocked || !read.data?.enabled || !card || !new FormData(event.currentTarget).has('confirm')) return
    setFailure(null)
    requestMutation.propose(event, text('request'), root, { card: card.id, view: query.view, from: query.from, to: query.to, confirm: true, request_key: requestKey.current })
  }
  async function download(row: ReportExport) {
    if (blocked || row.state !== 'completed' || expired.includes(row.id)) return
    const controller = new AbortController(); mutation.current = controller; setPending(true); setFailure(null)
    try {
      const blob = await tenancyRequest<Blob>(root + '/' + row.id + '/download', { download: true, revision: active.revision ?? undefined, signal: controller.signal })
      if (controller.signal.aborted) return
      if (!blob.type.startsWith('text/csv')) throw new Error(text('invalidFile'))
      const url = URL.createObjectURL(blob)
      try {
        const link = document.createElement('a'); link.href = url; link.download = 'report-' + row.id + '.csv'
        document.body.appendChild(link); link.click(); link.remove()
      } finally { URL.revokeObjectURL(url) }
      notify({ type: 'success', message: text('downloaded') })
    } catch (error: unknown) { if (!controller.signal.aborted && !isAbortError(error)) failed(error) }
    finally { if (!controller.signal.aborted) setPending(false) }
  }
  const error = failure ?? read.error
  return <section aria-label={text('title')} className="grid min-w-0 gap-3 rounded-xl border border-slate-700 p-4 [overflow-wrap:anywhere]">
    <h3 className="font-bold">{text('title')}</h3><p>{text('hint')}</p>
    <Button disabled={disabled || pending || requestMutation.blocked} onClick={() => { setOpen(!open); setFailure(null) }}>{text(open ? 'close' : 'open')}</Button>
    {requestMutation.feedback}
    {Object.entries(requestMutation.errors(root)).map(([field, messages]) => <p key={field} role="alert">{messages.join(' ')}</p>)}
    {open && <>{read.loading && <p role="status">{text('loading')}</p>}
      {error && <><Alert tone="error" title={error.message} requestId={error.requestId} />{Object.entries(error.fieldErrors).map(([field, messages]) => <p key={field} role="alert">{messages.join(' ')}</p>)}</>}
      {!error && read.data && <><p>{text('limit')} : {read.data.max_rows} · {text('lifetime')} : {read.data.lifetime_minutes} min</p>
        {!read.data.enabled && <p>{text('disabled')}</p>}
        {!active.confirmed && <p>{text('context')}</p>}
        {read.data.enabled && card && <form aria-label={text('form')} onSubmit={submit} className="grid min-w-0 gap-3">
          <SelectField name="export_card" title={text('report')} value={card.id} disabled={blocked} onChange={(id) => { setSelected(id); requestKey.current = crypto.randomUUID() }}>
            {choices.map((entry) => <option key={entry.id} value={entry.id}>{entry.label}</option>)}
          </SelectField>
          <p>{card.period_kind === 'snapshot' ? text('snapshot') : query.from + ' → ' + query.to + ' · ' + active.context?.timezone}</p>
          <label className="flex items-start gap-2"><input type="checkbox" name="confirm" required disabled={blocked} className="mt-1" />{text('confirm')}</label>
          <Button type="submit" disabled={blocked}>{text('request')}</Button>
        </form>}
        {!card && <p>{text('noReports')}</p>}
        {read.data.items.length === 0 && <p>{text('empty')}</p>}
        <ul className="grid min-w-0 gap-3">{read.data.items.map((row) => <li key={row.id} className="grid min-w-0 gap-2 rounded-xl border border-slate-700 p-3">
          <p>{row.label} · {row.view_label} · {text(expired.includes(row.id) ? 'state.expired' : 'state.' + row.state)}</p>
          <p>{row.period_kind === 'snapshot' ? text('snapshot') : row.from + ' → ' + row.to + ' · ' + row.timezone}</p>
          {row.row_count !== null && <p>{text('rows')} : {row.row_count}</p>}
          <p>{text('expires')} : {new Date(row.expires_at).toLocaleString('fr-FR', { timeZone: active.context?.timezone ?? 'UTC' })}</p>
          {row.state === 'completed' && <Button disabled={blocked || !read.data?.enabled || expired.includes(row.id)} onClick={() => void download(row)}>{text('download')}</Button>}
        </li>)}</ul>
        <Pagination currentPage={read.data.meta.current_page} totalPages={read.data.meta.last_page} disabled={blocked} onPageChange={setPage} />
      </>}
      <Button disabled={blocked} onClick={() => { setFailure(null); setRefresh((value) => value + 1) }}>{text('refresh')}</Button>
    </>}
  </section>
}
