import { useEffect, useRef, useState } from 'react'
import { tenancyRequest, type ActiveOrganization } from '../../api/tenancy'
import type { ApiSuccessResponse } from '../../api/contracts'
import type { CalendarConflictReport } from '../../api/calendarConflicts'
import { isAbortError } from '../../api/errors'
import { apiError, shouldReloadContext } from '../people/peopleFormValues'

export function useCalendarConflictReview(active: ActiveOrganization, root: string, onInvalid: () => void) {
  const [review, setReview] = useState<{ report: CalendarConflictReport; key: string } | null>(null), [busy, setBusy] = useState(false)
  const [error, setError] = useState<ReturnType<typeof apiError> | null>(null)
  const controller = useRef<AbortController | null>(null)
  useEffect(() => () => controller.current?.abort(), [])
  function clear() { controller.current?.abort(); setReview(null); setError(null); setBusy(false) }
  async function inspect(body: Record<string, unknown>) {
    controller.current?.abort(); const request = new AbortController(); controller.current = request
    setBusy(true); setReview(null); setError(null)
    try {
      const result = await tenancyRequest<ApiSuccessResponse<CalendarConflictReport>>(root + '/activity-conflicts', { method: 'POST', body, revision: active.revision ?? undefined, signal: request.signal })
      if (!request.signal.aborted) setReview({ report: result.data, key: JSON.stringify(body) })
    } catch (failure) {
      if (request.signal.aborted || isAbortError(failure)) return
      const next = apiError(failure); setError(next); if (shouldReloadContext(next)) onInvalid()
    } finally { if (!request.signal.aborted) setBusy(false) }
  }
  return { review, busy, error, clear, inspect }
}
