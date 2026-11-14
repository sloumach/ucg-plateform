import { useRef, useState, type FormEvent } from 'react'
import { tenancyRequest } from '../../api/tenancy'
import { t } from '../../i18n/fr'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { Alert } from '../feedback/Alert'
import { apiError } from '../people/peopleFormValues'
import { AuthLayout } from '../layout/AuthLayout'

export function ClientActivationForm({ token, onCompleted }: { token: string; onCompleted: () => void }) {
  const [busy, setBusy] = useState(false)
  const [done, setDone] = useState(false)
  const [error, setError] = useState<ReturnType<typeof apiError> | null>(null)
  const submitting = useRef(false)

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (submitting.current) return
    const form = event.currentTarget
    const values = new FormData(form)
    submitting.current = true
    setBusy(true)
    setError(null)
    try {
      await tenancyRequest('client-onboarding/confirm', { method: 'POST', body: {
        token, password: values.get('password'), password_confirmation: values.get('password_confirmation'),
      } })
      form.reset()
      window.history.replaceState(null, '', window.location.pathname + window.location.search)
      setDone(true)
    } catch (failure) {
      setError(apiError(failure))
    } finally {
      submitting.current = false
      setBusy(false)
    }
  }

  return <AuthLayout>
      <h1 className="text-3xl font-bold tracking-tight">{t('clients.activateTitle')}</h1>
      {done ? <>
        <Alert tone="success" title={t('clients.activated')} />
        <Button variant="primary" onClick={onCompleted}>{t('clients.back')}</Button>
      </> : <form aria-label={t('clients.activateTitle')} onSubmit={(event) => void submit(event)} className="grid min-w-0 gap-4">
        <p className="text-sm leading-6 text-muted">{t('clients.activateHint')}</p>
        <FormField name="password" type="password" autoComplete="new-password" required minLength={12} maxLength={72} label={t('clients.password')} hint={t('clients.passwordHint')} disabled={busy} error={error?.fieldErrors.password?.[0]} />
        <FormField name="password_confirmation" type="password" autoComplete="new-password" required minLength={12} maxLength={72} label={t('clients.passwordConfirmation')} disabled={busy} error={error?.fieldErrors.password_confirmation?.[0]} />
        {error && <Alert tone="error" title={Object.values(error.fieldErrors)[0]?.[0] ?? error.message} requestId={error.requestId} />}
        <Button type="submit" variant="primary" isLoading={busy} loadingLabel={t('tenant.busy')}>{t('clients.activate')}</Button>
      </form>}
  </AuthLayout>
}
