import { useEffect, useState } from 'react'
import { tenancyRequest, type ActiveOrganization } from '../../api/tenancy'
import type { ApiSuccessResponse } from '../../api/contracts'
import { isAbortError } from '../../api/errors'
import { apiError, shouldReloadContext } from '../people/peopleFormValues'

export function useAgreementRead<T>(path: string, active: ActiveOrganization, enabled: boolean, onInvalid: () => void, refresh = 0) {
  const [data, setData] = useState<T | null>(null), [loading, setLoading] = useState(false)
  const [error, setError] = useState<ReturnType<typeof apiError> | null>(null)
  const revision = active.revision ?? undefined
  useEffect(() => {
    const controller = new AbortController()
    if (!enabled) return () => controller.abort()
    const load = async () => {
      setLoading(true); setData(null); setError(null)
      try {
        const result = await tenancyRequest<ApiSuccessResponse<T>>(path, { revision, signal: controller.signal })
        if (!controller.signal.aborted) setData(result.data)
      } catch (failure) {
        if (controller.signal.aborted || isAbortError(failure)) return
        const next = apiError(failure); setError(next); if (shouldReloadContext(next)) onInvalid()
      } finally { if (!controller.signal.aborted) setLoading(false) }
    }
    void load(); return () => controller.abort()
  }, [path, revision, enabled, onInvalid, refresh])
  return { data, loading, error }
}
