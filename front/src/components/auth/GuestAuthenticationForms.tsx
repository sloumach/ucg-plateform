import { useEffect, useRef, useState, type FormEvent } from 'react'
import {
  login,
  requestPasswordReset,
  resetPassword,
  type AuthenticatedUser,
} from '../../api/auth'
import type { ApiFieldErrors } from '../../api/contracts'
import { ApiClientError } from '../../api/errors'
import { t } from '../../i18n/fr'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { Alert } from '../feedback/Alert'
import { useNotifications } from '../feedback/notificationContext'

type AuthView = 'forgot' | 'login' | 'reset'

export function GuestAuthenticationForms({
  onAuthenticated,
  sessionError,
}: {
  onAuthenticated: (user: AuthenticatedUser) => void
  sessionError: ApiClientError | null
}) {
  const query = new URLSearchParams(window.location.search)
  const resetToken = query.get('reset_token') ?? ''
  const resetEmail = query.get('email') ?? ''
  const [view, setView] = useState<AuthView>(resetToken ? 'reset' : 'login')
  const [formError, setFormError] = useState<ApiClientError | null>(null)
  const [fieldErrors, setFieldErrors] = useState<ApiFieldErrors>({})
  const [submitting, setSubmitting] = useState(false)
  const formRef = useRef<HTMLFormElement>(null)
  const { notify } = useNotifications()

  useEffect(() => {
    const firstInvalidField = Object.keys(fieldErrors)[0]
    const control = firstInvalidField
      ? formRef.current?.elements.namedItem(firstInvalidField)
      : null

    if (control instanceof HTMLElement) {
      control.focus()
    }
  }, [fieldErrors])

  async function handleLogin(event: FormEvent<HTMLFormElement>) {
    const form = startSubmission(event)

    try {
      const user = await login(
        String(form.get('email') ?? ''),
        String(form.get('password') ?? ''),
      )
      onAuthenticated(user)
      notify({ type: 'success', message: t((user.mfa.required || user.mfa.enabled) && !user.mfa.verified ? 'mfa.challengeRequired' : 'auth.loginSucceeded') })
    } catch (error) {
      handleError(error, t('auth.loginUnavailable'))
    } finally {
      setSubmitting(false)
    }
  }

  async function handleRecovery(event: FormEvent<HTMLFormElement>) {
    const form = startSubmission(event)

    try {
      const notification = await requestPasswordReset(String(form.get('email') ?? ''))
      notify(notification ?? { type: 'success', message: t('auth.recoverySubmit') })
      changeView('login')
    } catch (error) {
      handleError(error, t('auth.recoveryUnavailable'))
    } finally {
      setSubmitting(false)
    }
  }

  async function handleReset(event: FormEvent<HTMLFormElement>) {
    const form = startSubmission(event)

    try {
      const notification = await resetPassword(
        resetToken,
        String(form.get('email') ?? ''),
        String(form.get('password') ?? ''),
        String(form.get('password_confirmation') ?? ''),
      )
      notify(notification ?? { type: 'success', message: t('auth.resetSubmit') })
      window.history.replaceState({}, '', window.location.pathname)
      changeView('login')
    } catch (error) {
      handleError(error, t('auth.resetUnavailable'))
    } finally {
      setSubmitting(false)
    }
  }

  function startSubmission(event: FormEvent<HTMLFormElement>): FormData {
    event.preventDefault()
    setSubmitting(true)
    setFormError(null)
    setFieldErrors({})

    return new FormData(event.currentTarget)
  }

  function handleError(error: unknown, fallback: string) {
    const apiError = error instanceof ApiClientError ? error : new ApiClientError(fallback)
    setFieldErrors(apiError.fieldErrors)
    setFormError(apiError)
  }

  function changeView(nextView: AuthView) {
    setView(nextView)
    setFormError(null)
    setFieldErrors({})
  }

  function clearFieldError(field: string) {
    setFieldErrors((current) => {
      if (!current[field]) return current
      const next = { ...current }
      delete next[field]
      return next
    })
    if (formError?.code === 'VALIDATION_FAILED') setFormError(null)
  }

  const sharedFeedback = (
    <>
      {sessionError && view === 'login' && (
        <Alert
          tone="warning"
          title={t('auth.sessionUnavailableTitle')}
          requestId={sessionError.requestId}
        >
          {sessionError.message}
        </Alert>
      )}
      {formError && (
        <Alert tone="error" title={formError.message} requestId={formError.requestId} />
      )}
    </>
  )

  if (view === 'forgot') {
    return (
      <form ref={formRef} noValidate aria-busy={submitting} onSubmit={handleRecovery} className="grid gap-5">
        <FormHeading title={t('auth.recoveryTitle')} description={t('auth.recoveryDescription')} />
        {sharedFeedback}
        <FormField name="email" type="email" autoComplete="email" required label={t('auth.emailLabel')} error={fieldErrors.email?.[0]} onChange={() => clearFieldError('email')} />
        <Button type="submit" variant="primary" isLoading={submitting} loadingLabel={t('auth.recoverySubmitting')} className="w-full">{t('auth.recoverySubmit')}</Button>
        <Button onClick={() => changeView('login')} className="w-full">{t('auth.backToLogin')}</Button>
      </form>
    )
  }

  if (view === 'reset') {
    return (
      <form ref={formRef} noValidate aria-busy={submitting} onSubmit={handleReset} className="grid gap-5">
        <FormHeading title={t('auth.resetTitle')} description={t('auth.resetDescription')} />
        {sharedFeedback}
        <FormField name="email" type="email" autoComplete="email" defaultValue={resetEmail} required label={t('auth.emailLabel')} error={fieldErrors.email?.[0]} onChange={() => clearFieldError('email')} />
        <FormField name="password" type="password" autoComplete="new-password" required label={t('auth.passwordLabel')} error={fieldErrors.password?.[0]} onChange={() => clearFieldError('password')} />
        <FormField name="password_confirmation" type="password" autoComplete="new-password" required label={t('auth.passwordConfirmationLabel')} error={fieldErrors.password_confirmation?.[0]} onChange={() => clearFieldError('password_confirmation')} />
        <Button type="submit" variant="primary" isLoading={submitting} loadingLabel={t('auth.resetSubmitting')} className="w-full">{t('auth.resetSubmit')}</Button>
      </form>
    )
  }

  return (
    <form ref={formRef} noValidate aria-busy={submitting} onSubmit={handleLogin} className="grid gap-5">
      <FormHeading title={t('auth.loginTitle')} description={t('auth.loginDescription')} />
      {sharedFeedback}
      <FormField id="email" name="email" type="email" autoComplete="email" required label={t('auth.emailLabel')} error={fieldErrors.email?.[0]} onChange={() => clearFieldError('email')} />
      <FormField id="password" name="password" type="password" autoComplete="current-password" required label={t('auth.passwordLabel')} error={fieldErrors.password?.[0]} onChange={() => clearFieldError('password')} />
      <Button type="submit" variant="primary" isLoading={submitting} loadingLabel={t('auth.submitting')} className="w-full font-black">{t('auth.submit')}</Button>
      <Button onClick={() => changeView('forgot')} className="w-full">{t('auth.forgotPassword')}</Button>
    </form>
  )
}

function FormHeading({ title, description }: { title: string; description: string }) {
  return (
    <div>
      <p className="text-sm font-semibold tracking-[0.18em] text-cyan-300 uppercase">{t('auth.secureArea')}</p>
      <h2 className="mt-3 text-3xl font-black">{title}</h2>
      <p className="mt-3 text-sm leading-6 text-slate-400">{description}</p>
    </div>
  )
}
