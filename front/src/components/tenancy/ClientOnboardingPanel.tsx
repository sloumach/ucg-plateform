import { useEffect, useRef, useState, type FormEvent } from 'react'
import type { ApiSuccessResponse } from '../../api/contracts'
import { tenancyRequest } from '../../api/tenancy'
import { t } from '../../i18n/fr'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { Alert } from '../feedback/Alert'
import { useNotifications } from '../feedback/notificationContext'
import { apiError } from '../people/peopleFormValues'

export function ClientOnboardingPanel() {
  const [open, setOpen] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ReturnType<typeof apiError> | null>(null)
  const [preview, setPreview] = useState<string | null>(null)
  const submitting = useRef(false)
  const formRef = useRef<HTMLFormElement>(null)
  const { notify } = useNotifications()
  useEffect(() => {
    if (busy || !error) return
    const field = formRef.current?.elements.namedItem(Object.keys(error.fieldErrors)[0] ?? '')
    if (field instanceof HTMLElement) field.focus()
  }, [busy, error])

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (submitting.current) return
    const form = event.currentTarget
    const values = Object.fromEntries(new FormData(form))
    submitting.current = true
    setBusy(true)
    setError(null)
    setPreview(null)
    try {
      const result = await tenancyRequest<ApiSuccessResponse<{ activation_url: string | null }>>('platform/client-invitations', {
        method: 'POST',
        body: { ...values, confirm: values.confirm === 'on', language: 'fr', settings: { week_starts_on: 1, date_format: 'd/m/Y' } },
      })
      setPreview(result.data.activation_url)
      notify(result.notification ?? { type: 'success', message: t('clients.sent') })
      form.reset()
    } catch (failure) {
      const next = apiError(failure)
      setError(next)
    } finally {
      submitting.current = false
      setBusy(false)
    }
  }

  return <section aria-label={t('clients.title')} className="ui-panel grid gap-4 p-4 sm:p-6">
    <h2 className="text-xl font-bold">{t('clients.title')}</h2>
    <Button variant={open ? 'secondary' : 'primary'} disabled={busy} aria-expanded={open} className="justify-self-start" onClick={() => setOpen(!open)}>{t(open ? 'tenant.cancel' : 'clients.add')}</Button>
    {open && <>
      <p>{t('clients.explanation')}</p>
      <form ref={formRef} aria-label={t('clients.add')} onSubmit={(event) => void submit(event)} className="ui-editor grid min-w-0 gap-4 p-4 sm:p-5">
        <fieldset disabled={busy} className="grid min-w-0 gap-4 sm:grid-cols-2">
          <legend className="mb-3 font-semibold">{t('clients.contact')}</legend>
          <FormField name="client_name" label={t('clients.client_name')} required maxLength={255} error={error?.fieldErrors.client_name?.[0]} />
          <FormField name="email" type="email" label={t('clients.email')} required maxLength={254} error={error?.fieldErrors.email?.[0]} />
        </fieldset>
        <fieldset disabled={busy} className="grid min-w-0 gap-4 sm:grid-cols-2">
          <legend className="mb-3 font-semibold">{t('clients.organization')}</legend>
          <FormField name="name" label={t('clients.name')} required maxLength={160} error={error?.fieldErrors.name?.[0]} />
          <FormField name="slug" label={t('clients.slug')} hint={t('clients.slugHint')} required maxLength={80} pattern="[a-z0-9]+(-[a-z0-9]+)*" error={error?.fieldErrors.slug?.[0]} />
          <FormField name="timezone" label={t('clients.timezone')} required defaultValue="Africa/Tunis" maxLength={64} error={error?.fieldErrors.timezone?.[0]} />
          <FormField name="country" label={t('clients.country')} hint={t('clients.countryHint')} required defaultValue="TN" maxLength={2} pattern="[A-Z]{2}" error={error?.fieldErrors.country?.[0]} />
        </fieldset>
        <FormField name="reason" label={t('clients.reason')} required minLength={10} maxLength={500} disabled={busy} error={error?.fieldErrors.reason?.[0]} />
        <label className="flex items-start gap-2"><input name="confirm" type="checkbox" required disabled={busy} />{t('clients.confirm')}</label>
        {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
        <Button variant="primary" type="submit" className="justify-self-start" isLoading={busy} loadingLabel={t('tenant.busy')}>{t('clients.send')}</Button>
      </form>
    </>}
    {preview && <div className="grid min-w-0 gap-3 rounded-xl border border-amber-400/40 p-4">
      <p>{t('clients.preview')}</p>
      <a href={preview} target="_blank" rel="noreferrer" className="text-cyan-300 underline">{t('clients.openPreview')}</a>
    </div>}
  </section>
}
