import { useEffect, useRef, useState, type FormEvent } from 'react'
import {
  getCurrentUser,
  login,
  logout,
  type AuthenticatedUser,
} from './api/auth'
import type { ApiFieldErrors } from './api/contracts'
import { ApiClientError, isAbortError } from './api/errors'
import { getSystemStatus, type SystemStatus } from './api/systemStatus'
import { Button } from './components/Button'
import { FormField } from './components/FormField'
import { Alert } from './components/feedback/Alert'
import { AsyncState } from './components/feedback/AsyncState'
import { NotificationProvider } from './components/feedback/NotificationProvider'
import { useNotifications } from './components/feedback/notificationContext'
import { t } from './i18n/fr'
import { TenantWorkspace } from './components/tenancy/TenantWorkspace'
import type { ActiveOrganization } from './api/tenancy'

const technologies = [
  'Laravel 13',
  'React 19',
  'TypeScript',
  'Tailwind 4',
  'PostgreSQL 17',
]

type LoadState = 'error' | 'loading' | 'ready'

function App() {
  return (
    <NotificationProvider>
      <Application />
    </NotificationProvider>
  )
}

function Application() {
  const [systemStatus, setSystemStatus] = useState<SystemStatus | null>(null)
  const [systemState, setSystemState] = useState<LoadState>('loading')
  const [systemError, setSystemError] = useState<ApiClientError | null>(null)
  const [user, setUser] = useState<AuthenticatedUser | null>(null)
  const [activeTenant, setActiveTenant] = useState<ActiveOrganization['context']>(null)
  const [authLoading, setAuthLoading] = useState(true)
  const [sessionError, setSessionError] = useState<ApiClientError | null>(null)
  const [formError, setFormError] = useState<ApiClientError | null>(null)
  const [fieldErrors, setFieldErrors] = useState<ApiFieldErrors>({})
  const [submitting, setSubmitting] = useState(false)
  const loginForm = useRef<HTMLFormElement>(null)
  const { notify } = useNotifications()

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

  useEffect(() => {
    const firstInvalidField = Object.keys(fieldErrors)[0]

    if (!firstInvalidField) {
      return
    }

    const control = loginForm.current?.elements.namedItem(firstInvalidField)

    if (control instanceof HTMLElement) {
      control.focus()
    }
  }, [fieldErrors])

  async function handleLogin(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setSubmitting(true)
    setFormError(null)
    setFieldErrors({})

    const form = new FormData(event.currentTarget)

    try {
      const authenticatedUser = await login(
        String(form.get('email') ?? ''),
        String(form.get('password') ?? ''),
      )

      setUser(authenticatedUser)
      notify({ type: 'success', message: t('auth.loginSucceeded') })
    } catch (error) {
      const apiError =
        error instanceof ApiClientError
          ? error
          : new ApiClientError(t('auth.loginUnavailable'))

      setFieldErrors(apiError.fieldErrors)
      setFormError(apiError)
    } finally {
      setSubmitting(false)
    }
  }

  async function handleLogout() {
    setSubmitting(true)

    try {
      const notification = await logout()
      setUser(null)
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

  function clearFieldError(field: string) {
    setFieldErrors((current) => {
      if (!current[field]) {
        return current
      }

      const next = { ...current }
      delete next[field]

      return next
    })

    if (formError?.code === 'VALIDATION_FAILED') {
      setFormError(null)
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

  return (
    <main className="min-h-screen bg-slate-950 text-slate-100">
      <div className="mx-auto flex min-h-screen w-full max-w-6xl flex-col px-6 py-10 lg:px-10">
        <header className="sticky top-0 z-30 flex flex-wrap items-center justify-between gap-4 border-b border-white/10 bg-slate-950 py-4">
          <div className="flex items-center gap-3">
            <div className="grid size-11 shrink-0 place-items-center rounded-xl bg-cyan-400 font-black text-slate-950">
              UCG
            </div>
            <div>
              <p className="text-sm font-semibold tracking-[0.18em] text-cyan-300 uppercase">
                {t('app.name')}
              </p>
              <p className="text-sm text-slate-400">{t('app.subtitle')}</p>
            </div>
          </div>
          <span className="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs font-medium text-slate-300">
            {t('app.ticket')}
          </span>
          {user && <p aria-label={t('tenant.header')} className="w-full break-words text-sm font-semibold text-cyan-200">
            {activeTenant?.name ?? t('tenant.none')}
          </p>}
        </header>

        <section className="grid flex-1 items-center gap-12 py-16 lg:grid-cols-[1.15fr_0.85fr]">
          <div className="max-w-3xl">
            <p className="mb-5 text-sm font-semibold tracking-[0.24em] text-cyan-300 uppercase">
              {t('foundation.eyebrow')}
            </p>
            <h1 className="text-5xl leading-[1.05] font-black tracking-tight text-balance sm:text-6xl">
              {t('foundation.title')}
            </h1>
            <p className="mt-7 max-w-2xl text-lg leading-8 text-slate-300">
              {t('foundation.description')}
            </p>

            <div
              className="mt-10 flex flex-wrap gap-3"
              aria-label={t('foundation.technologiesLabel')}
            >
              {technologies.map((technology) => (
                <span
                  key={technology}
                  className="rounded-lg border border-white/10 bg-white/5 px-4 py-2 text-sm text-slate-300"
                >
                  {technology}
                </span>
              ))}
            </div>

            <div className="mt-10 max-w-2xl">
              {systemState === 'loading' && (
                <div role="status" className="flex items-center gap-3 text-sm text-slate-400">
                  <span
                    aria-hidden="true"
                    className="size-2.5 animate-pulse rounded-full bg-amber-300 motion-reduce:animate-none"
                  />
                  {t('system.loading')}
                </div>
              )}
              {systemState === 'ready' && systemStatus && (
                <div role="status" className="flex items-center gap-3 text-sm text-slate-300">
                  <span
                    aria-hidden="true"
                    className="size-2.5 rounded-full bg-emerald-400"
                  />
                  {t('system.operational', {
                    name: systemStatus.name,
                    version: systemStatus.apiVersion,
                  })}
                </div>
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
            </div>
          </div>

          <aside className="rounded-3xl border border-white/10 bg-white/[0.04] p-7 shadow-2xl shadow-cyan-950/30 backdrop-blur">
            {authLoading ? (
              <AsyncState variant="loading" title={t('auth.loading')} />
            ) : user ? (
              <div>
                <p className="text-sm font-semibold tracking-[0.18em] text-emerald-300 uppercase">
                  {t('auth.active')}
                </p>
                <h2 className="mt-4 text-3xl font-black">
                  {t('auth.welcome', { name: user.name })}
                </h2>
                <p className="mt-3 text-slate-400">{user.email}</p>
                <div className="mt-8">
                  <Alert tone="success" title={t('auth.sessionDescription')} />
                </div>
                <Button
                  onClick={() => void handleLogout()}
                  isLoading={submitting}
                  loadingLabel={t('auth.loggingOut')}
                  className="mt-8 w-full"
                >
                  {t('auth.logout')}
                </Button>
              </div>
            ) : (
              <form
                ref={loginForm}
                noValidate
                aria-busy={submitting}
                onSubmit={(event) => void handleLogin(event)}
                className="grid gap-5"
              >
                <div>
                  <p className="text-sm font-semibold tracking-[0.18em] text-cyan-300 uppercase">
                    {t('auth.secureArea')}
                  </p>
                  <h2 className="mt-3 text-3xl font-black">{t('auth.loginTitle')}</h2>
                  <p className="mt-3 text-sm leading-6 text-slate-400">
                    {t('auth.loginDescription')}
                  </p>
                </div>

                {sessionError && (
                  <Alert
                    tone="warning"
                    title={t('auth.sessionUnavailableTitle')}
                    requestId={sessionError.requestId}
                  >
                    {sessionError.message}
                  </Alert>
                )}

                {formError && (
                  <Alert
                    tone="error"
                    title={formError.message}
                    requestId={formError.requestId}
                  />
                )}

                <FormField
                  id="email"
                  name="email"
                  type="email"
                  autoComplete="email"
                  required
                  label={t('auth.emailLabel')}
                  error={fieldErrors.email?.[0]}
                  onChange={() => clearFieldError('email')}
                />

                <FormField
                  id="password"
                  name="password"
                  type="password"
                  autoComplete="current-password"
                  required
                  label={t('auth.passwordLabel')}
                  error={fieldErrors.password?.[0]}
                  onChange={() => clearFieldError('password')}
                />

                <Button
                  type="submit"
                  variant="primary"
                  isLoading={submitting}
                  loadingLabel={t('auth.submitting')}
                  className="mt-2 w-full font-black"
                >
                  {t('auth.submit')}
                </Button>
              </form>
            )}
          </aside>
        </section>
        {user && <TenantWorkspace key={user.id} onContextChange={setActiveTenant} />}
      </div>
    </main>
  )
}

export default App
