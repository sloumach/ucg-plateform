import { useEffect, useRef, useState } from 'react'
import type { ApiSuccessResponse } from '../../api/contracts'
import { tenancyRequest, type ActiveOrganization, type Page } from '../../api/tenancy'
import { documentText as text, type ContractDocumentScan, type ContractDocumentVersion } from '../../api/contractDocuments'
import { ApiClientError, isAbortError } from '../../api/errors'
import { Button } from '../Button'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { useNotifications } from '../feedback/notificationContext'
import { apiError, shouldReloadContext } from '../people/peopleFormValues'
import { usePeopleMutation } from '../people/usePeopleMutation'

type Props = { active: ActiveOrganization; path: string; version: ContractDocumentVersion; manage: boolean; disabled: boolean; onChanged: () => void; onInvalid: () => void }
export function DocumentSecurityControls({ active, path, version, manage, disabled, onChanged, onInvalid }: Props) {
  const revision = active.revision ?? undefined
  const [history, setHistory] = useState(false), [page, setPage] = useState(1)
  const [scans, setScans] = useState<Page<ContractDocumentScan> | null>(null)
  const [busy, setBusy] = useState(false), [error, setError] = useState<ReturnType<typeof apiError> | null>(null)
  const transfer = useRef<AbortController | null>(null), alive = useRef(true)
  const { notify } = useNotifications()
  const mutation = usePeopleMutation(active, onChanged, onInvalid)
  useEffect(() => { alive.current = true; return () => { alive.current = false; transfer.current?.abort() } }, [path, revision])
  useEffect(() => {
    if (!history) return
    const controller = new AbortController()
    const load = async () => {
      setScans(null); setError(null)
      return tenancyRequest<Page<ContractDocumentScan>>(path + '/scans?per_page=10&page=' + page, { revision, signal: controller.signal })
        .then((result) => { if (!controller.signal.aborted) setScans(result) })
        .catch((failure) => {
          if (controller.signal.aborted || isAbortError(failure)) return
          const next = apiError(failure); setError(next); if (shouldReloadContext(next) || next.status === 409) onInvalid()
        })
    }
    void load()
    return () => controller.abort()
  }, [history, page, path, revision, onInvalid])
  const blocked = disabled || busy || mutation.blocked
  async function download() {
    if (blocked || !version.scan_id || !version.download_available || version.scan_status !== 'clean') return
    const controller = new AbortController(); transfer.current = controller; setBusy(true); setError(null)
    try {
      const result = await tenancyRequest<ApiSuccessResponse<{ url: string; filename: string; expires_at: string }>>(path + '/download-link', {
        method: 'POST', body: { expected_scan_id: version.scan_id }, revision, signal: controller.signal,
      })
      if (!alive.current || controller.signal.aborted) return
      // Only the exact current version endpoint may receive session credentials.
      const signed = new URL(result.data.url, 'https://document.invalid')
      if (signed.origin !== 'https://document.invalid' || signed.pathname !== '/api/v1/' + path + '/download'
        || signed.searchParams.get('scan') !== version.scan_id || signed.searchParams.get('actor') !== String(active.context?.actor_user_id)) {
        throw new ApiClientError(text('invalidLink'))
      }
      const blob = await tenancyRequest<Blob>(path + '/download' + signed.search, { download: 'pdf', revision, signal: controller.signal })
      if (!alive.current || controller.signal.aborted) return
      const url = URL.createObjectURL(blob), anchor = document.createElement('a')
      anchor.href = url; anchor.download = version.filename; anchor.click()
      setTimeout(() => URL.revokeObjectURL(url), 0)
      notify({ type: 'success', message: text('downloaded') })
    } catch (failure) {
      if (!alive.current || controller.signal.aborted || isAbortError(failure)) return
      const next = apiError(failure); setError(next)
      notify({ type: 'warning', message: next.message, requestId: next.requestId })
      if (shouldReloadContext(next) || next.status === 409) onInvalid()
    } finally { if (alive.current && !controller.signal.aborted) setBusy(false) }
  }
  return <div className="grid min-w-0 gap-2">
    <p role="status" className="text-sm [overflow-wrap:anywhere]">{text(version.scan_status)}{version.scan_reason && ' · ' + text('reason_' + version.scan_reason)}</p>
    {version.scan_finished_at && <p className="text-sm">{text('analyzedAt')} : {version.scan_finished_at}</p>}
    {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
    {mutation.feedback}
    {version.download_available && version.scan_status === 'clean' && version.scan_id && <Button disabled={blocked} onClick={() => void download()}>{busy ? text('downloading') : text('download')}</Button>}
    {manage && !['queued', 'processing'].includes(version.scan_status) && <form aria-label={text('scan')} onSubmit={(event) => mutation.propose(event, text('scan'), path + '/scans', { expected_scan_id: version.scan_id ?? null })}>
      <Button type="submit" disabled={blocked}>{text('scan')}</Button>
    </form>}
    <Button disabled={blocked} onClick={() => { setHistory((value) => !value); setPage(1) }}>{text(history ? 'hideScans' : 'scanHistory')}</Button>
    {history && <section aria-label={text('scanHistory')} className="grid min-w-0 gap-2">
      {!scans && !error && <p>{text('loading')}</p>}
      {scans?.data.length === 0 && <p>{text('noScans')}</p>}
      {scans?.data.map((scan) => <div key={scan.id} className="grid min-w-0 gap-1 rounded-lg border border-slate-700 p-2 text-sm [overflow-wrap:anywhere]">
        <p>{text('attempt')} {scan.attempt} · {text(scan.invalidated_at ? 'rejected' : scan.status)}{scan.reason && ' · ' + text('reason_' + scan.reason)}</p>
        <p>{scan.requested_at}{scan.finished_at && ' → ' + scan.finished_at}</p>
        {scan.engine && <p>{scan.engine}</p>}
      </div>)}
      {scans && <Pagination currentPage={scans.meta.current_page} totalPages={scans.meta.last_page} disabled={blocked} onPageChange={setPage} />}
    </section>}
  </div>
}
