import { useEffect, useRef, useState, type MouseEvent } from 'react'
import type { AuthenticatedUser } from '../../api/auth'
import type { ActiveOrganization } from '../../api/tenancy'
import { t } from '../../i18n/fr'
import { Button } from '../Button'
import { AccountSecurityPanel } from '../auth/AccountSecurityPanel'
import { MfaPanel } from '../auth/MfaPanel'
import { MetricCataloguePanel } from '../performance/MetricCataloguePanel'
import { ClientOnboardingPanel } from '../tenancy/ClientOnboardingPanel'
import { TenantWorkspace } from '../tenancy/TenantWorkspace'
import { GameCataloguePanel } from '../teams/GameCataloguePanel'
import { Brand } from './Brand'
import { SectionLinks } from './SectionLinks'

const sections = {
  organisations: 'workspace.organizations',
  clients: 'workspace.clients',
  catalogues: 'workspace.catalogues',
  compte: 'workspace.account',
} as const

type Section = keyof typeof sections

const workspaceTools = {
  catalogues: [
    { id: 'jeux', label: 'workspace.catalogueGames' },
    { id: 'metriques', label: 'workspace.catalogueMetrics' },
  ],
  compte: [
    { id: 'profil', label: 'workspace.accountSessions' },
    { id: 'securite', label: 'workspace.accountMfa' },
  ],
} as const

function workspaceUrl(section: Section, tool?: string) {
  const url = new URL(window.location.href)
  if (section === 'organisations') url.searchParams.delete('espace')
  else url.searchParams.set('espace', section)
  if (tool) url.searchParams.set('vue', tool)
  else url.searchParams.delete('vue')
  return url
}

function requestedSection() {
  const query = new URLSearchParams(window.location.search)
  return { section: query.get('espace') ?? 'organisations', tool: query.get('vue') }
}

export function AuthenticatedWorkspace({
  user, activeTenant, onContextChange, onAccountChanged, onLogout, loggingOut,
}: {
  user: AuthenticatedUser
  activeTenant: ActiveOrganization['context']
  onContextChange: (context: ActiveOrganization['context']) => void
  onAccountChanged: (user: AuthenticatedUser | null) => void
  onLogout: () => void
  loggingOut: boolean
}) {
  const [requested, setRequested] = useState(requestedSection)
  const [showRecoveryCodes, setShowRecoveryCodes] = useState(false)
  const heading = useRef<HTMLHeadingElement>(null)
  const mfaPending = (user.mfa.required || user.mfa.enabled) && !user.mfa.verified
  const available = (Object.keys(sections) as Section[]).filter((id) => {
    if (id === 'clients') return user.can_provision_clients === true
    if (id === 'catalogues') return user.email_verified
    return true
  })
  const verificationInProgress = mfaPending || showRecoveryCodes
  const section = verificationInProgress ? 'compte' : available.find((id) => id === requested.section) ?? 'organisations'
  const title = t(mfaPending ? 'workspace.verify' : sections[section])
  const tools = section === 'compte' || section === 'catalogues' ? workspaceTools[section] : []
  const tool = tools.find(({ id }) => id === requested.tool) ?? tools[0]
  const previousTitle = useRef(title)

  useEffect(() => {
    const onHistoryChange = () => setRequested(requestedSection())
    window.addEventListener('popstate', onHistoryChange)
    return () => window.removeEventListener('popstate', onHistoryChange)
  }, [])

  useEffect(() => {
    if (!showRecoveryCodes && previousTitle.current !== title) heading.current?.focus()
    previousTitle.current = title
  }, [title, showRecoveryCodes])

  function navigate(event: MouseEvent<HTMLAnchorElement>, next: Section) {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return
    event.preventDefault()
    if (requested.section === next) return
    window.history.pushState(null, '', workspaceUrl(next))
    setRequested(requestedSection())
  }

  return (
    <div className="flex min-h-screen flex-col bg-canvas text-slate-100">
      <header className="workspace-header sticky top-0 z-30">
        <div className="mx-auto flex max-w-screen-2xl flex-wrap items-center justify-between gap-2 px-4 py-3 sm:gap-x-6 sm:px-6">
          <Brand />
          <Button onClick={onLogout} isLoading={loggingOut} loadingLabel={t('auth.loggingOut')} className="px-3 py-2 text-sm sm:order-last">
            {t('auth.logout')}
          </Button>
          {!mfaPending && activeTenant && <div className="flex w-full min-w-0 flex-wrap items-baseline gap-x-2 gap-y-1 border-t border-slate-800 pt-2 text-sm sm:w-auto sm:flex-1 sm:border-0 sm:pt-0">
            <span aria-hidden="true" className="text-slate-400">{t('tenant.sections.currentLabel')}</span>
            <div aria-label={t('tenant.header')} role="group" className="min-w-0 font-medium wrap-anywhere text-slate-100">{activeTenant.name}</div>
          </div>}
        </div>
      </header>

      <div className={verificationInProgress ? 'mx-auto w-full max-w-xl' : 'mx-auto grid w-full max-w-screen-2xl flex-1 content-start md:grid-cols-[12rem_minmax(0,1fr)] md:content-stretch lg:grid-cols-[15rem_minmax(0,1fr)]'}>
        {!verificationInProgress && (
          <nav aria-label={t('workspace.navigation')} className="workspace-sidebar border-b px-4 py-3 md:border-r md:border-b-0">
            <ul className="flex flex-wrap gap-1 md:sticky md:top-20 md:flex-col">
              {available.map((id) => (
                <li key={id}>
                  <a
                    href={workspaceUrl(id).href}
                    aria-current={section === id ? 'page' : undefined}
                    onClick={(event) => navigate(event, id)}
                    className="workspace-nav-link"
                  >
                    {t(sections[id])}
                  </a>
                </li>
              ))}
            </ul>
          </nav>
        )}

        <main className="min-w-0 px-4 py-6 sm:px-6 md:px-8 md:py-9">
          <h1 ref={heading} tabIndex={-1} className="scroll-mt-36 text-3xl font-bold wrap-anywhere tracking-tight sm:text-4xl">
            {title}
          </h1>

          {!verificationInProgress && tool && (
            <div className="mt-5">
              <SectionLinks
                label={t('workspace.tools')}
                links={tools.map(({ id, label }) => ({ id, label: t(label), href: workspaceUrl(section, id).href }))}
                current={tool.id}
                onNavigate={(id) => {
                  window.history.pushState(null, '', workspaceUrl(section, id))
                  setRequested(requestedSection())
                }}
              />
            </div>
          )}
          <div hidden={section !== 'compte' || (!verificationInProgress && tool?.id !== 'profil')} className="ui-panel mt-6 max-w-2xl p-4 sm:p-6">
            <h2 className="text-lg font-semibold wrap-anywhere">{user.name}</h2>
            <p className="mt-1 text-sm wrap-anywhere text-slate-300">{user.email}</p>
            <AccountSecurityPanel user={user} />
          </div>
          <div hidden={section !== 'compte' || (!verificationInProgress && tool?.id !== 'securite')} className="ui-panel mt-6 max-w-2xl p-4 sm:p-6">
            <MfaPanel user={user} onUserChanged={onAccountChanged} onRecoveryCodesVisibilityChange={setShowRecoveryCodes} />
          </div>

          {!mfaPending && <div hidden={section !== 'organisations'} className="mt-2">
            {!activeTenant && <p className="mt-2 text-slate-300">{t('auth.welcome', { name: user.name })}</p>}
            <TenantWorkspace userId={user.id} onContextChange={onContextChange} />
          </div>}
          {section === 'clients' && <div className="mt-6"><ClientOnboardingPanel /></div>}
          {section === 'catalogues' && (
            <div className="mt-6 grid min-w-0 gap-4">
              {tool?.id === 'jeux' && <GameCataloguePanel />}
              {tool?.id === 'metriques' && <MetricCataloguePanel />}
            </div>
          )}
        </main>
      </div>
    </div>
  )
}
