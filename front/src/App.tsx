import { type FormEvent, useEffect, useState } from 'react'
import {
  AuthenticationError,
  getCurrentUser,
  login,
  logout,
  type AuthenticatedUser,
} from './api/auth'
import { getSystemStatus, type SystemStatus } from './api/systemStatus'

type Toast = { message: string; tone: 'success' | 'error' }

function App() {
  const [systemStatus, setSystemStatus] = useState<SystemStatus | null>(null)
  const [systemError, setSystemError] = useState(false)
  const [user, setUser] = useState<AuthenticatedUser | null>(null)
  const [authLoading, setAuthLoading] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const [toast, setToast] = useState<Toast | null>(null)

  useEffect(() => {
    const controller = new AbortController()

    Promise.all([
      getSystemStatus(controller.signal)
        .then(setSystemStatus)
        .catch((error: unknown) => {
          if (!(error instanceof DOMException && error.name === 'AbortError')) {
            setSystemError(true)
          }
        }),
      getCurrentUser(controller.signal)
        .then(setUser)
        .catch((error: unknown) => {
          if (!(error instanceof DOMException && error.name === 'AbortError')) {
            setToast({ message: 'Impossible de vérifier la session.', tone: 'error' })
          }
        })
        .finally(() => setAuthLoading(false)),
    ])

    return () => controller.abort()
  }, [])

  async function handleLogin(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setSubmitting(true)
    setToast(null)

    const form = new FormData(event.currentTarget)

    try {
      const authenticatedUser = await login(
        String(form.get('email') ?? ''),
        String(form.get('password') ?? ''),
      )

      setUser(authenticatedUser)
      setToast({ message: 'Connexion réussie.', tone: 'success' })
    } catch (error) {
      setToast({
        message:
          error instanceof AuthenticationError
            ? error.message
            : 'Le service de connexion est indisponible.',
        tone: 'error',
      })
    } finally {
      setSubmitting(false)
    }
  }

  async function handleLogout() {
    setSubmitting(true)

    try {
      const notification = await logout()
      setUser(null)
      setToast({
        message: notification?.message ?? 'Déconnexion effectuée.',
        tone: 'success',
      })
    } catch {
      setToast({ message: 'La déconnexion a échoué.', tone: 'error' })
    } finally {
      setSubmitting(false)
    }
  }

  const isOperational = systemStatus?.status === 'operational'

  return (
    <main className="min-h-screen bg-slate-950 text-slate-100">
      <div className="mx-auto flex min-h-screen w-full max-w-6xl flex-col px-6 py-10 lg:px-10">
        <header className="flex items-center justify-between border-b border-white/10 pb-6">
          <div className="flex items-center gap-3">
            <div className="grid size-11 place-items-center rounded-xl bg-cyan-400 font-black text-slate-950">
              UCG
            </div>
            <div>
              <p className="text-sm font-semibold tracking-[0.18em] text-cyan-300 uppercase">
                Ultra Cyber Game
              </p>
              <p className="text-sm text-slate-400">Plateforme de gestion esports</p>
            </div>
          </div>
          <span className="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs font-medium text-slate-300">
            UCG-ARC-001
          </span>
        </header>

        <section className="grid flex-1 items-center gap-12 py-16 lg:grid-cols-[1.15fr_0.85fr]">
          <div className="max-w-3xl">
            <p className="mb-5 text-sm font-semibold tracking-[0.24em] text-cyan-300 uppercase">
              Fondation technique
            </p>
            <h1 className="text-5xl leading-[1.05] font-black tracking-tight text-balance sm:text-6xl">
              Le socle UCG est prêt pour les premiers parcours métier.
            </h1>
            <p className="mt-7 max-w-2xl text-lg leading-8 text-slate-300">
              React et Laravel communiquent par une API versionnée, sécurisée par Sanctum.
              Cette page valide le premier parcours authentifié de la plateforme.
            </p>

            <div className="mt-10 flex flex-wrap gap-3" aria-label="Technologies installées">
              {['Laravel 13', 'React 19', 'TypeScript', 'Tailwind 4', 'PostgreSQL 17'].map(
                (technology) => (
                  <span
                    key={technology}
                    className="rounded-lg border border-white/10 bg-white/5 px-4 py-2 text-sm text-slate-300"
                  >
                    {technology}
                  </span>
                ),
              )}
            </div>

            <div className="mt-10 flex items-center gap-3 text-sm text-slate-400">
              <span
                className={`size-2.5 rounded-full ${
                  systemError
                    ? 'bg-rose-400'
                    : isOperational
                      ? 'bg-emerald-400'
                      : 'animate-pulse bg-amber-300'
                }`}
              />
              {systemError
                ? 'API indisponible'
                : isOperational
                  ? `${systemStatus.name} ${systemStatus.apiVersion} opérationnelle`
                  : 'Vérification de l’API…'}
            </div>
          </div>

          <aside className="rounded-3xl border border-white/10 bg-white/[0.04] p-7 shadow-2xl shadow-cyan-950/30 backdrop-blur">
            {authLoading ? (
              <p className="py-16 text-center text-slate-400">Vérification de la session…</p>
            ) : user ? (
              <div>
                <p className="text-sm font-semibold tracking-[0.18em] text-emerald-300 uppercase">
                  Session active
                </p>
                <h2 className="mt-4 text-3xl font-black">Bienvenue, {user.name}</h2>
                <p className="mt-3 text-slate-400">{user.email}</p>
                <div className="mt-8 rounded-2xl border border-emerald-400/20 bg-emerald-400/10 p-5 text-sm leading-6 text-emerald-100">
                  Le parcours SPA → Sanctum → session Laravel est opérationnel.
                </div>
                <button
                  type="button"
                  onClick={handleLogout}
                  disabled={submitting}
                  className="mt-8 w-full rounded-xl border border-white/15 px-4 py-3 font-semibold transition hover:bg-white/10 disabled:cursor-wait disabled:opacity-60"
                >
                  Se déconnecter
                </button>
              </div>
            ) : (
              <form onSubmit={handleLogin}>
                <p className="text-sm font-semibold tracking-[0.18em] text-cyan-300 uppercase">
                  Espace sécurisé
                </p>
                <h2 className="mt-3 text-3xl font-black">Connexion</h2>
                <p className="mt-3 text-sm leading-6 text-slate-400">
                  Utilisez un compte créé dans la base locale pour valider le parcours.
                </p>

                <label className="mt-7 block text-sm font-medium" htmlFor="email">
                  Adresse e-mail
                </label>
                <input
                  id="email"
                  name="email"
                  type="email"
                  autoComplete="email"
                  required
                  className="mt-2 w-full rounded-xl border border-white/10 bg-slate-900 px-4 py-3 outline-none transition focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20"
                />

                <label className="mt-5 block text-sm font-medium" htmlFor="password">
                  Mot de passe
                </label>
                <input
                  id="password"
                  name="password"
                  type="password"
                  autoComplete="current-password"
                  required
                  className="mt-2 w-full rounded-xl border border-white/10 bg-slate-900 px-4 py-3 outline-none transition focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20"
                />

                <button
                  type="submit"
                  disabled={submitting}
                  className="mt-7 w-full rounded-xl bg-cyan-400 px-4 py-3 font-black text-slate-950 transition hover:bg-cyan-300 disabled:cursor-wait disabled:opacity-60"
                >
                  {submitting ? 'Connexion…' : 'Se connecter'}
                </button>
              </form>
            )}

            {toast && (
              <p
                role="status"
                className={`mt-6 rounded-xl border p-4 text-sm ${
                  toast.tone === 'success'
                    ? 'border-emerald-400/20 bg-emerald-400/10 text-emerald-100'
                    : 'border-rose-400/20 bg-rose-400/10 text-rose-100'
                }`}
              >
                {toast.message}
              </p>
            )}
          </aside>
        </section>
      </div>
    </main>
  )
}

export default App
