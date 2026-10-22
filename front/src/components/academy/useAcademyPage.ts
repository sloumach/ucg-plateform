import { useEffect, useState } from 'react'
import { tenancyRequest, type ActiveOrganization, type Page } from '../../api/tenancy'
import { isAbortError } from '../../api/errors'
import { apiError, shouldReloadContext } from '../people/peopleFormValues'

export function useAcademyPage<T>(active: ActiveOrganization, path: string | null, page: number, refresh: number, onInvalid: () => void) {
  const key = [path, page, refresh, active.revision].join('|')
  const [loaded, setLoaded] = useState<{ key: string; data: Page<T> | null; error: ReturnType<typeof apiError> | null } | null>(null)
  useEffect(() => {
    if (!path) return
    const controller = new AbortController()
    void tenancyRequest<Page<T>>(path + (path.includes('?') ? '&' : '?') + new URLSearchParams({ page: String(page), per_page: '10' }), { revision: active.revision ?? undefined, signal: controller.signal })
      .then((next) => { if (!controller.signal.aborted) setLoaded({ key, data: next, error: null }) })
      .catch((failure: unknown) => {
        if (controller.signal.aborted || isAbortError(failure)) return
        const next = apiError(failure); setLoaded({ key, data: null, error: next })
        if (shouldReloadContext(next)) onInvalid()
      })
    return () => controller.abort()
  }, [path, page, refresh, active.revision, key, onInvalid])
  return { result: loaded?.key === key ? loaded.data : null, loading: path !== null && loaded?.key !== key, error: loaded?.key === key ? loaded.error : null }
}
