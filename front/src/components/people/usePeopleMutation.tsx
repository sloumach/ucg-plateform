import { useEffect, useRef, useState, type FormEvent } from 'react'
import { tenancyRequest, type ActiveOrganization } from '../../api/tenancy'
import type { ApiSuccessResponse } from '../../api/contracts'
import { t } from '../../i18n/fr'
import { Button } from '../Button'
import { Alert } from '../feedback/Alert'
import { useNotifications } from '../feedback/notificationContext'
import { apiError } from './peopleFormValues'
import type { ApiClientError } from '../../api/errors'

type Pending = { label: string; path: string; method: 'POST' | 'PATCH'; body: Record<string, unknown>; form: HTMLFormElement }
export function usePeopleMutation(active: ActiveOrganization, onSaved: () => void, onInvalid: () => void) {
  const [pending, setPending] = useState<Pending | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiClientError | null>(null)
  const [target, setTarget] = useState<string | null>(null)
  const alive = useRef(true)
  const returnFocus = useRef<HTMLElement | null>(null)
  const region = useRef<HTMLDivElement>(null)
  const { notify } = useNotifications()
  useEffect(() => { alive.current = true; return () => { alive.current = false } }, [])
  useEffect(() => { if (pending) region.current?.focus(); else if (!busy) returnFocus.current?.focus() }, [pending, busy])

  function propose(event: FormEvent<HTMLFormElement>, name: string, path: string, body: Record<string, unknown>, editing = false) {
    event.preventDefault()
    setError(null); setTarget(null)
    if (editing && Object.keys(body).filter((key) => key !== 'version').length === 0) {
      notify({ type: 'info', message: t('people.noChanges') }); return
    }
    returnFocus.current = event.currentTarget.querySelector('button[type="submit"]')
    setPending({ label: name, path, body, method: editing ? 'PATCH' : 'POST', form: event.currentTarget })
  }
  async function save() {
    if (!pending || !active.context || !active.revision || busy) return
    const action = pending
    setBusy(true)
    try {
      if (!active.confirmed) await tenancyRequest('active-organization/confirmation', { method: 'POST', body: { organization_id: active.context.organization_id, revision: active.revision, confirm: true } })
      // A tenant switch can unmount this panel while confirmation is in flight.
      if (!alive.current) return
      const result = await tenancyRequest<ApiSuccessResponse<unknown>>(action.path, { method: action.method, body: action.body, revision: active.revision })
      if (!alive.current) return
      notify(result.notification ?? { type: 'success', message: t('tenant.done') })
      setPending(null); onSaved()
    } catch (failure) {
      if (!alive.current) return
      const next = apiError(failure)
      setError(next); setTarget(action.path); setPending(null)
      const field = Object.keys(next.fieldErrors)[0]
      const element = field ? Array.from(action.form.elements).find((item) => item.getAttribute('name') === field) : null
      if (element instanceof HTMLElement) returnFocus.current = element
      if ([401, 403, 404, 409].includes(next.status ?? 0)) {
        notify({ type: 'warning', message: next.message, requestId: next.requestId })
        onInvalid()
      }
    } finally { if (alive.current) setBusy(false) }
  }
  const feedback = <>
    {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
    {pending && <div ref={region} tabIndex={-1} role="region" aria-label={t('tenant.confirmTitle')} className="grid min-w-0 gap-3 rounded-xl border border-amber-400/40 bg-amber-400/10 p-4">
      <p>{t('tenant.confirmMessage', { action: pending.label, name: active.context?.name ?? '' })}</p>
      <div className="flex flex-wrap gap-3">
        <Button isLoading={busy} loadingLabel={t('tenant.busy')} onClick={() => void save()}>{t('tenant.confirm')}</Button>
        <Button disabled={busy} onClick={() => setPending(null)}>{t('tenant.cancel')}</Button>
      </div>
    </div>}
  </>
  return { propose, feedback, blocked: busy || pending !== null, errors: (path: string) => target === path ? error?.fieldErrors ?? {} : {} }
}
