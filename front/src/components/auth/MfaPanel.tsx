import { useEffect, useRef, useState, type FormEvent } from 'react'
import { getCurrentUser, type AuthenticatedUser } from '../../api/auth'
import { mfaRequest, type MfaEnrollment } from '../../api/mfa'
import { ApiClientError } from '../../api/errors'
import { t } from '../../i18n/fr'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { Alert } from '../feedback/Alert'
import { useNotifications } from '../feedback/notificationContext'

type Operation = 'enrollment' | 'confirmation' | 'challenge' | 'recovery-codes' | 'disable'

export function MfaPanel({ user, onUserChanged }: {
  user: AuthenticatedUser
  onUserChanged: (user: AuthenticatedUser | null) => void
}) {
  const [enrollment, setEnrollment] = useState<MfaEnrollment | null>(null)
  const [recoveryCodes, setRecoveryCodes] = useState<string[]>([])
  const [useRecovery, setUseRecovery] = useState(false)
  const [management, setManagement] = useState<Operation | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiClientError | null>(null)
  const mounted = useRef(true)
  const formRef = useRef<HTMLFormElement>(null)
  const codesRef = useRef<HTMLDivElement>(null)
  const { notify } = useNotifications()
  const status = user.mfa
  const pending = (status.required || status.enabled) && !status.verified

  useEffect(() => {
    mounted.current = true
    return () => { mounted.current = false }
  }, [])

  useEffect(() => {
    const field = error ? Object.keys(error.fieldErrors)[0] : null
    const control = field ? formRef.current?.elements.namedItem(field) : null
    if (control instanceof HTMLElement) control.focus()
  }, [error])

  useEffect(() => {
    if (recoveryCodes.length) codesRef.current?.focus()
  }, [recoveryCodes])

  async function submit(event: FormEvent<HTMLFormElement>, operation: Operation) {
    event.preventDefault()
    const form = event.currentTarget
    const data = new FormData(form)
    const body: Record<string, string> = {}
    for (const [key, value] of data.entries()) body[key] = String(value).trim()
    // Password whitespace is significant.
    if (data.has('password')) body.password = String(data.get('password'))
    setBusy(true)
    setError(null)
    try {
      const result = await mfaRequest<MfaEnrollment | { recovery_codes: string[] }>(operation, body)
      if (!mounted.current) return
      form.reset()
      if (operation === 'enrollment') {
        setEnrollment(result.data as MfaEnrollment)
        setRecoveryCodes([])
      } else {
        setEnrollment(null)
        setManagement(null)
        if ('recovery_codes' in result.data) setRecoveryCodes(result.data.recovery_codes)
        // Drop the previous workspace while rechecking the server's session status.
        const account = await getCurrentUser()
        if (!mounted.current) return
        onUserChanged(account)
      }
      if (result.notification) notify(result.notification)
    } catch (failure) {
      if (!mounted.current) return
      const apiError = failure instanceof ApiClientError ? failure : new ApiClientError(t('mfa.error'))
      setError(apiError)
      notify({ type: 'error', message: apiError.message, requestId: apiError.requestId })
      if (apiError.status === 401) onUserChanged(null)
    } finally {
      if (mounted.current) setBusy(false)
    }
  }

  const codeField = useRecovery ? 'recovery_code' : 'code'
  const passwordForm = (operation: Operation, label: string) => (
    <form ref={formRef} noValidate className="grid gap-3" onSubmit={(event) => void submit(event, operation)}>
      {operation === 'disable' && <Alert tone="warning" title={t('mfa.disableWarning')} />}
      <FormField name="password" type="password" required autoComplete="current-password" label={t('auth.passwordLabel')}
        disabled={busy} error={error?.fieldErrors.password?.[0]} />
      <Button type="submit" disabled={operation === 'enrollment' && !user.email_verified} isLoading={busy}>{label}</Button>
      {status.enabled && <Button disabled={busy} onClick={() => { setManagement(null); setError(null) }}>{t('tenant.cancel')}</Button>}
    </form>
  )

  return (
    <section className="mt-6 grid gap-4 border-t border-white/10 pt-6" aria-labelledby="mfa-title" aria-busy={busy}>
      <h3 id="mfa-title" className="font-bold">{t('mfa.title')}</h3>
      {error && <Alert tone="error" title={error.message} requestId={error.requestId}>
        {error.retryAfter !== undefined && <p>{t('mfa.retryAfter', { seconds: error.retryAfter })}</p>}
      </Alert>}
      {pending && <Alert tone="warning" title={t(status.enabled ? 'mfa.challengeRequired' : 'mfa.enrollmentRequired')} />}
      {recoveryCodes.length > 0 && (
        <div ref={codesRef} tabIndex={-1} className="grid gap-3 rounded-xl border border-amber-400/40 p-4" role="region" aria-label={t('mfa.recoveryTitle')}>
          <h4 className="font-bold">{t('mfa.recoveryTitle')}</h4>
          <p className="text-sm text-slate-300">{t('mfa.recoveryDescription')}</p>
          <ul className="grid gap-2 font-mono text-sm break-all">{recoveryCodes.map((code) => <li key={code}>{code}</li>)}</ul>
          <Button onClick={() => setRecoveryCodes([])}>{t('mfa.codesSaved')}</Button>
        </div>
      )}
      {enrollment ? (
        <form ref={formRef} noValidate className="grid gap-3" onSubmit={(event) => void submit(event, 'confirmation')}>
          <p className="text-sm text-slate-300">{t('mfa.setupInstructions')}</p>
          <p className="text-sm">{user.email}</p>
          <p className="font-mono break-all rounded-xl bg-slate-900 p-3" aria-label={t('mfa.secret')}>{enrollment.secret}</p>
          <FormField name="code" required inputMode="numeric" autoComplete="one-time-code" maxLength={6} label={t('mfa.code')}
            disabled={busy} error={error?.fieldErrors.code?.[0]} />
          <Button type="submit" isLoading={busy}>{t('mfa.confirm')}</Button>
          <Button disabled={busy} onClick={() => { setEnrollment(null); setManagement(null); setError(null) }}>{t('tenant.cancel')}</Button>
        </form>
      ) : status.enabled && !status.verified ? (
        <form ref={formRef} noValidate className="grid gap-3" onSubmit={(event) => void submit(event, 'challenge')}>
          <FormField key={codeField} name={codeField} required inputMode={useRecovery ? 'text' : 'numeric'} autoComplete={useRecovery ? 'off' : 'one-time-code'}
            maxLength={useRecovery ? 35 : 6} label={t(useRecovery ? 'mfa.recoveryCode' : 'mfa.code')}
            disabled={busy} error={error?.fieldErrors[codeField]?.[0] ?? error?.fieldErrors.code?.[0]} />
          <Button type="submit" isLoading={busy}>{t('mfa.verify')}</Button>
          <Button disabled={busy} onClick={() => { setUseRecovery(!useRecovery); setError(null) }}>
            {t(useRecovery ? 'mfa.useAuthenticator' : 'mfa.useRecovery')}
          </Button>
        </form>
      ) : !status.enabled ? (
        <>
          <p className="text-sm text-slate-300">{t('mfa.enrollmentDescription')}</p>
          {!user.email_verified && <p className="text-sm text-amber-200">{t('mfa.verifyEmail')}</p>}
          {passwordForm('enrollment', t('mfa.begin'))}
        </>
      ) : management ? (
        passwordForm(management, t(management === 'enrollment' ? 'mfa.replace' : management === 'disable' ? 'mfa.disable' : 'mfa.regenerate'))
      ) : (
        <>
          <p role="status" className="text-sm text-emerald-300">{t('mfa.verified')}</p>
          <div className="flex flex-wrap gap-3">
            <Button disabled={busy} onClick={() => setManagement('enrollment')}>{t('mfa.replace')}</Button>
            <Button disabled={busy} onClick={() => setManagement('recovery-codes')}>{t('mfa.regenerate')}</Button>
            {!status.required && <Button disabled={busy} onClick={() => setManagement('disable')}>{t('mfa.disable')}</Button>}
          </div>
        </>
      )}
    </section>
  )
}
