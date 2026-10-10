import { useState, type FormEvent } from 'react'
import {
  revokeOtherSessions,
  sendEmailVerification,
  type AuthenticatedUser,
} from '../../api/auth'
import { ApiClientError } from '../../api/errors'
import { t } from '../../i18n/fr'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { Alert } from '../feedback/Alert'
import { useNotifications } from '../feedback/notificationContext'

export function AccountSecurityPanel({ user }: { user: AuthenticatedUser }) {
  const [submitting, setSubmitting] = useState<'sessions' | 'verification' | null>(null)
  const [passwordError, setPasswordError] = useState<string>()
  const { notify } = useNotifications()

  async function handleVerification() {
    setSubmitting('verification')
    try {
      const notification = await sendEmailVerification()
      notify(notification ?? { type: 'success', message: t('auth.sendVerification') })
    } catch (error) {
      notifyError(error, t('auth.verificationUnavailable'))
    } finally {
      setSubmitting(null)
    }
  }

  async function handleRevoke(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setSubmitting('sessions')
    setPasswordError(undefined)
    const formElement = event.currentTarget
    const form = new FormData(formElement)

    try {
      const notification = await revokeOtherSessions(String(form.get('password') ?? ''))
      formElement.reset()
      notify(notification ?? { type: 'success', message: t('auth.sessionsRevoke') })
    } catch (error) {
      if (error instanceof ApiClientError && error.fieldErrors.password?.[0]) {
        setPasswordError(error.fieldErrors.password[0])
      }
      notifyError(error, t('auth.sessionsRevokeUnavailable'))
    } finally {
      setSubmitting(null)
    }
  }

  function notifyError(error: unknown, fallback: string) {
    notify({
      type: 'error',
      message: error instanceof ApiClientError ? error.message : fallback,
      requestId: error instanceof ApiClientError ? error.requestId : undefined,
    })
  }

  return (
    <div className="mt-6 grid gap-5 border-t border-white/10 pt-6">
      {!user.email_verified && (
        <Alert
          tone="warning"
          title={t('auth.emailNotVerified')}
          actions={
            <Button onClick={() => void handleVerification()} isLoading={submitting === 'verification'} className="py-2 text-sm">
              {t('auth.sendVerification')}
            </Button>
          }
        />
      )}
      <form onSubmit={(event) => void handleRevoke(event)} className="grid gap-4">
        <div>
          <h3 className="font-bold">{t('auth.sessionsTitle')}</h3>
          <p className="mt-1 text-sm text-slate-400">{t('auth.sessionsDescription')}</p>
        </div>
        <FormField name="password" type="password" autoComplete="current-password" required label={t('auth.passwordLabel')} error={passwordError} onChange={() => setPasswordError(undefined)} />
        <Button type="submit" isLoading={submitting === 'sessions'} loadingLabel={t('auth.sessionsRevoking')} className="w-full">{t('auth.sessionsRevoke')}</Button>
      </form>
    </div>
  )
}
