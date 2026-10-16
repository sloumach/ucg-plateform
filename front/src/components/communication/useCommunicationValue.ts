import { useEffect, useState } from 'react'
import { tenancyRequest, type ActiveOrganization } from '../../api/tenancy'
import type { ApiSuccessResponse } from '../../api/contracts'
import { isAbortError } from '../../api/errors'
import { apiError, shouldReloadContext } from '../people/peopleFormValues'
export function useCommunicationValue<T>(path: string, active: ActiveOrganization, onInvalid: () => void) {
  const [data, setData] = useState<T | null>(null), [loading, setLoading] = useState(true)
  const [error, setError] = useState<ReturnType<typeof apiError> | null>(null)
  useEffect(() => {
    const controller = new AbortController()
    const load = async () => {
      setData(null); setLoading(true); setError(null)
      await tenancyRequest<ApiSuccessResponse<T>>(path, { revision: active.revision ?? undefined, signal: controller.signal })
        .then((result) => { if (!controller.signal.aborted) setData(result.data) })
        .catch((failure: unknown) => {
          if (controller.signal.aborted || isAbortError(failure)) return
          const next = apiError(failure); setError(next); if (shouldReloadContext(next)) onInvalid()
        }).finally(() => { if (!controller.signal.aborted) setLoading(false) })
    }
    void load()
    return () => controller.abort()
  }, [path, active.revision, onInvalid])
  return { data, loading, error }
}
