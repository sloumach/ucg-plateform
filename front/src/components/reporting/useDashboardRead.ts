import { useEffect, useState } from 'react'
import { tenancyRequest, type ActiveOrganization } from '../../api/tenancy'
import type { ApiSuccessResponse } from '../../api/contracts'
import { isAbortError } from '../../api/errors'
import { apiError, shouldReloadContext } from '../people/peopleFormValues'
type ReadState<T> = { key: string; data?: T; error?: ReturnType<typeof apiError>; startedAt?: number }

export function useDashboardRead<T>(path: string | null, active: ActiveOrganization, onInvalid: () => void, refresh = 0) {
  const [state, setState] = useState<ReadState<T> | null>(null)
  const key = path + ':' + active.revision + ':' + refresh
  useEffect(() => {
    if (!path) return
    const controller = new AbortController()
    const startedAt = performance.now()
    void tenancyRequest<ApiSuccessResponse<T>>(path, { revision: active.revision ?? undefined, signal: controller.signal })
      .then((response) => { if (!controller.signal.aborted) setState({ key, data: response.data, startedAt }) })
      .catch((failure: unknown) => {
        if (controller.signal.aborted || isAbortError(failure)) return
        const error = apiError(failure); setState({ key, error }); if (shouldReloadContext(error)) onInvalid()
      })
    return () => controller.abort()
  }, [path, active.revision, onInvalid, key])
  return { data: path && state?.key === key ? state.data : undefined, error: path && state?.key === key ? state.error : undefined, loading: path !== null && state?.key !== key, startedAt: path && state?.key === key ? state.startedAt : undefined }
}
