import { useEffect, useState } from 'react'
import { tenancyRequest, type ActiveOrganization, type Page } from '../../api/tenancy'
import { isAbortError } from '../../api/errors'
import { apiError, shouldReloadContext } from '../people/peopleFormValues'

export function usePartnershipList<T, P extends Page<T> = Page<T>>(path: string, active: ActiveOrganization, enabled: boolean, onInvalid: () => void, refresh = 0) {
  const [list, setList] = useState<P | null>(null), [loading, setLoading] = useState(false)
  const [error, setError] = useState<ReturnType<typeof apiError> | null>(null)
  const revision = active.revision ?? undefined
  useEffect(() => {
    const controller = new AbortController()
    if (!enabled) return () => controller.abort()
    const load = async () => {
      setLoading(true); setList(null); setError(null)
      await tenancyRequest<P>(path, { revision, signal: controller.signal }).then((result) => {
        if (!controller.signal.aborted) setList(result)
      }).catch((failure: unknown) => {
        if (controller.signal.aborted || isAbortError(failure)) return
        const next = apiError(failure); setError(next)
        if (shouldReloadContext(next)) onInvalid()
      }).finally(() => { if (!controller.signal.aborted) setLoading(false) })
    }
    void load()
    return () => controller.abort()
  }, [path, revision, enabled, onInvalid, refresh])
  return { list, loading, error }
}
