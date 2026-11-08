import { useEffect, useState } from 'react'
import { tenancyRequest, type ActiveOrganization, type Page } from '../../api/tenancy'
import { ApiClientError, isAbortError } from '../../api/errors'
import type { ScopeType } from '../../api/roles'
import { t } from '../../i18n/fr'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'

type Option = { id: string; label: string }
export function ScopeSourcePicker({ active, type, disabled, error }: {
  active: ActiveOrganization; type: ScopeType; disabled: boolean; error?: string
}) {
  const [page, setPage] = useState(1)
  const [result, setResult] = useState<Page<Option> | null>(null)
  const [selected, setSelected] = useState<Option | null>(null)
  const [failure, setFailure] = useState<ApiClientError | null>(null)
  const [loading, setLoading] = useState(true)
  const [refresh, setRefresh] = useState(0)
  useEffect(() => {
    const controller = new AbortController()
    void Promise.resolve().then(async () => {
      if (controller.signal.aborted) return
      setLoading(true); setResult(null); setFailure(null)
      try {
        const response = await tenancyRequest<Page<Option>>('tenants/' + active.context!.organization_id + '/authorization-scopes/' + type + '?per_page=10&page=' + page,
          { revision: active.revision ?? undefined, signal: controller.signal })
        if (!controller.signal.aborted) setResult(response)
      } catch (cause) {
        if (!controller.signal.aborted && !isAbortError(cause)) setFailure(cause instanceof ApiClientError ? cause : new ApiClientError(t('roles.sourcesFailed'), { status: 0 }))
      } finally { if (!controller.signal.aborted) setLoading(false) }
    })
    return () => controller.abort()
  }, [active.context, active.revision, type, page, refresh])
  const options = result?.data ?? []
  return <div className="grid min-w-0 gap-2">
    <label className="grid gap-2">{t('roles.source')}
      <select name="scope_id" required value={selected?.id ?? ''} disabled={disabled || loading || failure !== null}
        aria-invalid={error ? true : undefined} onChange={(event) => setSelected(options.find((item) => item.id === event.target.value) ?? null)}
        className="min-w-0 w-full rounded-xl border border-white/15 bg-slate-900 px-3 py-3 text-slate-100">
        <option value="">{t(loading ? 'roles.loading' : 'roles.choose')}</option>
        {selected && !options.some((item) => item.id === selected.id) && <option value={selected.id}>{selected.label}</option>}
        {options.map((item) => <option key={item.id} value={item.id}>{item.label}</option>)}
      </select>
    </label>
    {error && <p role="alert" className="text-rose-200">{error}</p>}
    {failure && <><Alert tone="error" title={failure.message} requestId={failure.requestId} /><button type="button" onClick={() => setRefresh((value) => value + 1)}>{t('roles.refresh')}</button></>}
    {result?.data.length === 0 && <p>{t('roles.noSources')}</p>}
    {result && <Pagination currentPage={result.meta.current_page} totalPages={result.meta.last_page} disabled={disabled || loading} onPageChange={setPage} />}
  </div>
}
