import { useEffect, useState } from 'react'
import { AUTH_SESSION_CHANGED_EVENT, getCurrentUser, logout, type AuthenticatedUser } from './api/auth'
import { ApiClientError, isAbortError } from './api/errors'
import { getSystemStatus, type SystemStatus } from './api/systemStatus'
import { Button } from './components/Button'
import { GuestAuthenticationForms } from './components/auth/GuestAuthenticationForms'
import { ClientActivationForm } from './components/auth/ClientActivationForm'
import { Alert } from './components/feedback/Alert'
import { AsyncState } from './components/feedback/AsyncState'
import { NotificationProvider } from './components/feedback/NotificationProvider'
import { useNotifications } from './components/feedback/notificationContext'
import { AuthenticatedWorkspace } from './components/layout/AuthenticatedWorkspace'
import { AuthLayout } from './components/layout/AuthLayout'
import { t } from './i18n/fr'
import type { ActiveOrganization } from './api/tenancy'

type LoadState = 'error' | 'loading' | 'ready'

function App() {
  return (
    <NotificationProvider>
      <Application />
    </NotificationProvider>
  )
}

function Application() {
  const [clientSetupToken, setClientSetupToken] = useState(() => new URLSearchParams(window.location.hash.slice(1)).get('client_setup'))
  const [systemStatus, setSystemStatus] = useState<SystemStatus | null>(null)
  const [systemState, setSystemState] = useState<LoadState>('loading')
  const [systemError, setSystemError] = useState<ApiClientError | null>(null)
  const [user, setUser] = useState<AuthenticatedUser | null>(null)
  const [activeTenant, setActiveTenant] = useState<ActiveOrganization['context']>(null)
  const [authLoading, setAuthLoading] = useState(true)
  const [sessionError, setSessionError] = useState<ApiClientError | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const { notify } = useNotifications()
  const userId = user?.id

  function updateAccount(account: AuthenticatedUser | null) {
    setUser((current) => current !== null && (account === null || account.id === current.id) ? account : current)
    if (account === null || ((account.mfa.required || account.mfa.enabled) && !account.mfa.verified)) setActiveTenant(null)
  }

  useEffect(() => {
    if (userId === undefined) return
    let controller: AbortController | undefined
    async function refreshAccount() {
      controller?.abort()
      const request = new AbortController()
      controller = request
      try {
        const account = await getCurrentUser(request.signal)
        if (request.signal.aborted) return
        if (account?.id !== userId) setActiveTenant(null)
        setUser((current) => current?.id === userId ? account : current)
        if (account && (account.mfa.required || account.mfa.enabled) && !account.mfa.verified) setActiveTenant(null)
      } catch (error) {
        if (!request.signal.aborted && !isAbortError(error)) {
          setSessionError(error instanceof ApiClientError ? error : new ApiClientError(t('auth.sessionUnavailableMessage')))
          setUser(null)
          setActiveTenant(null)
        }
      }
    }
    window.addEventListener(AUTH_SESSION_CHANGED_EVENT, refreshAccount)
    window.addEventListener('focus', refreshAccount)
    return () => {
      controller?.abort()
      window.removeEventListener(AUTH_SESSION_CHANGED_EVENT, refreshAccount)
      window.removeEventListener('focus', refreshAccount)
    }
  }, [userId])

  useEffect(() => {
    const controller = new AbortController()

    void getSystemStatus(controller.signal)
      .then((status) => {
        if (!controller.signal.aborted) {
          setSystemStatus(status)
          setSystemError(null)
          setSystemState('ready')
        }
      })
      .catch((error: unknown) => {
        if (!isAbortError(error) && !controller.signal.aborted) {
          setSystemStatus(null)
          setSystemError(
            error instanceof ApiClientError
              ? error
              : new ApiClientError(t('system.unavailableMessage')),
          )
          setSystemState('error')
        }
      })
    void getCurrentUser(controller.signal)
      .then((currentUser) => {
        if (!controller.signal.aborted) {
          setUser(currentUser)
        }
      })
      .catch((error: unknown) => {
        if (!isAbortError(error) && !controller.signal.aborted) {
          setSessionError(
            error instanceof ApiClientError
              ? error
              : new ApiClientError(t('auth.sessionUnavailableMessage')),
          )
        }
      })
      .finally(() => {
        if (!controller.signal.aborted) {
          setAuthLoading(false)
        }
      })

    return () => controller.abort()
  }, [])

  async function handleLogout() {
    setSubmitting(true)

    try {
      const notification = await logout()
      const url = new URL(window.location.href)
      url.searchParams.delete('espace')
      url.searchParams.delete('rubrique')
      url.searchParams.delete('outil')
      url.searchParams.delete('vue')
      window.history.replaceState(null, '', url)
      setUser(null)
      setActiveTenant(null)
      setSessionError(null)
      notify(
        notification ?? {
          type: 'success',
          message: t('auth.logoutSucceeded'),
        },
      )
    } catch (error) {
      notify({
        type: 'error',
        message:
          error instanceof ApiClientError ? error.message : t('auth.logoutFailed'),
        requestId: error instanceof ApiClientError ? error.requestId : undefined,
      })
    } finally {
      setSubmitting(false)
    }
  }

  async function retrySystemStatus() {
    setSystemState('loading')
    setSystemError(null)

    try {
      setSystemStatus(await getSystemStatus())
      setSystemState('ready')
    } catch (error) {
      setSystemStatus(null)
      setSystemError(
        error instanceof ApiClientError
          ? error
          : new ApiClientError(t('system.unavailableMessage')),
      )
      setSystemState('error')
    }
  }

  if (clientSetupToken) return <ClientActivationForm token={clientSetupToken} onCompleted={() => setClientSetupToken(null)} />

  if (user) {
    return (
      <AuthenticatedWorkspace
        key={user.id}
        user={user}
        activeTenant={activeTenant}
        onContextChange={setActiveTenant}
        onAccountChanged={updateAccount}
        onLogout={() => void handleLogout()}
        loggingOut={submitting}
      />
    )
  }

  return (
    <AuthLayout footer={
      <>
          {systemState === 'ready' && systemStatus && (
            <p role="status" className="flex items-center justify-center gap-2 text-xs text-slate-400">
              <span aria-hidden="true" className="size-1.5 rounded-full bg-emerald-400" />
              {t('system.operational', { name: systemStatus.name, version: systemStatus.apiVersion })}
            </p>
          )}
          {systemState === 'error' && (
            <Alert
              tone="error"
              title={t('system.unavailableTitle')}
              requestId={systemError?.requestId}
              actions={
                <Button className="py-2 text-sm" onClick={() => void retrySystemStatus()}>
                  {t('common.retry')}
                </Button>
              }
            >
              {t('system.unavailableMessage')}
            </Alert>
          )}
      </>
    }>
      {authLoading ? (
        <AsyncState variant="loading" title={t('auth.loading')} />
      ) : (
        <GuestAuthenticationForms onAuthenticated={setUser} sessionError={sessionError} />
      )}
    </AuthLayout>
  )
}

export default App
