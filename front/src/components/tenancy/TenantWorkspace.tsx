import { lazy, Suspense, useCallback, useEffect, useRef, useState, type FormEvent, type Ref } from 'react'
import type { ApiFieldErrors, ApiSuccessResponse } from '../../api/contracts'
import { ApiClientError, isAbortError } from '../../api/errors'
import {
  tenancyRequest,
  type AccessibleOrganization,
  type ActiveOrganization,
  type Invitation,
  type Membership,
  type Page,
  type TenantRole,
} from '../../api/tenancy'
import { t } from '../../i18n/fr'
import { ScopedTeamsPanel } from './ScopedTeamsPanel'
import { ContextualModulesPanel } from './ContextualModulesPanel'
import { RoleManagementPanel } from './RoleManagementPanel'
import { PeoplePanel } from '../people/PeoplePanel'
import { OrganizationGamesPanel } from '../teams/OrganizationGamesPanel'
import { TeamsPanel } from '../teams/TeamsPanel'
import { ExternalOpponentsPanel } from '../teams/ExternalOpponentsPanel'
import { SensitiveAccessPanel } from './SensitiveAccessPanel'
import { PartnershipsPanel } from '../partnerships/PartnershipsPanel'
import { ContractsPanel } from '../contracts/ContractsPanel'
const AcademyPersonalPanel = lazy(() => import('../academy/AcademyPersonalPanel').then((module) => ({ default: module.AcademyPersonalPanel })))
const AcademyPanel = lazy(() => import('../academy/AcademyPanel').then((module) => ({ default: module.AcademyPanel })))
const ObservationPanel = lazy(() => import('../performance/ObservationPanel').then((module) => ({ default: module.ObservationPanel })))
const TenantSearchPanel = lazy(() => import('../reporting/TenantSearchPanel').then((module) => ({ default: module.TenantSearchPanel })))
const DashboardPanel = lazy(() => import('../reporting/DashboardPanel').then((module) => ({ default: module.DashboardPanel })))
const SponsorshipDashboardPanel = lazy(() => import('../partnerships/SponsorshipDashboardPanel').then((module) => ({ default: module.SponsorshipDashboardPanel })))
const MediaPanel = lazy(() => import('../media/MediaPanel').then((module) => ({ default: module.MediaPanel })))
const CommunicationPanel = lazy(() => import('../communication/CommunicationPanel').then((module) => ({ default: module.CommunicationPanel })))
const WorkTasksPanel = lazy(() => import('../communication/WorkTasksPanel').then((module) => ({ default: module.WorkTasksPanel })))
const AvailabilityPanel = lazy(() => import('../calendar/AvailabilityPanel').then((module) => ({ default: module.AvailabilityPanel })))
const CalendarPanel = lazy(() => import('../calendar/CalendarPanel').then((module) => ({ default: module.CalendarPanel })))
const ExternalCompetitionsPanel = lazy(() => import('../competitions/ExternalCompetitionsPanel').then((module) => ({ default: module.ExternalCompetitionsPanel })))
const MatchesPanel = lazy(() => import('../matches/MatchesPanel').then((module) => ({ default: module.MatchesPanel })))
import { Button } from '../Button'
import { FormField } from '../FormField'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { AsyncState } from '../feedback/AsyncState'
import { useNotifications } from '../feedback/notificationContext'
import { SectionLinks } from '../layout/SectionLinks'
import { organizationSections } from './organizationSections'

type Workspace = {
  active: ActiveOrganization
  organizations: Page<AccessibleOrganization>
  inbox: Page<Invitation>
  memberships: Page<Membership> | null
  invitations: Page<Invitation> | null
}
type Pages = { organizations: number; inbox: number; memberships: number; invitations: number }
type Action = {
  label: string
  organizationName: string
  path: string
  method: 'POST' | 'PUT' | 'DELETE'
  body?: unknown
  context?: ActiveOrganization
  revision?: string
}
const firstPages: Pages = { organizations: 1, inbox: 1, memberships: 1, invitations: 1 }
const selectClass = 'ui-input'

function organizationUrl(section: string, tool?: string) {
  const url = new URL(window.location.href)
  if (section === 'apercu') url.searchParams.delete('rubrique')
  else url.searchParams.set('rubrique', section)
  if (tool) url.searchParams.set('outil', tool)
  else url.searchParams.delete('outil')
  return url
}

function requestedSection() {
  const query = new URLSearchParams(window.location.search)
  return { section: query.get('rubrique') ?? 'apercu', tool: query.get('outil') }
}

function sameOrganizationAccess(previous: ActiveOrganization, current: ActiveOrganization) {
  const before = previous.context
  const after = current.context
  if (previous.revision !== current.revision) return false
  if (!before || !after) return before === after
  return before.organization_id === after.organization_id
    && before.actor_user_id === after.actor_user_id
    && before.membership_id === after.membership_id
    && before.is_owner === after.is_owner
    && before.name === after.name
    && before.slug === after.slug
    && before.timezone === after.timezone
    && before.language === after.language
    && before.roles.length === after.roles.length && before.roles.every((role) => after.roles.includes(role))
    && before.permissions.length === after.permissions.length && before.permissions.every((permission) => after.permissions.includes(permission))
}

export function TenantWorkspace({ userId, onContextChange }: {
  userId: number
  onContextChange?: (context: ActiveOrganization['context']) => void
}) {
  const [requested, setRequested] = useState(requestedSection)
  const [workspace, setWorkspace] = useState<Workspace | null>(null)
  const [pages, setPages] = useState<Pages>(firstPages)
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiClientError | null>(null)
  const [errorTarget, setErrorTarget] = useState<string | null>(null)
  const [action, setAction] = useState<Action | null>(null)
  const [accessEditor, setAccessEditor] = useState<string | null>(null)
  const generation = useRef(0)
  const readController = useRef<AbortController | null>(null)
  const confirmRegion = useRef<HTMLDivElement>(null)
  const previousFocus = useRef<HTMLElement | null>(null)
  const sectionTitle = useRef<HTMLHeadingElement>(null)
  const accessEditorTitle = useRef<HTMLHeadingElement>(null)
  const focusSectionTitle = useRef(false)
  const { notify } = useNotifications()
  const active = workspace?.active
  const selected = active?.context
  const available = organizationSections(selected ?? null)
  const section = available.find((item) => item.id === requested.section) ?? available[0]
  const tool = section.tools.find((item) => item.id === requested.tool) ?? section.tools[0]
  const overview = section.id === 'apercu'

  const reload = useCallback(async (nextPages: Pages, background?: { active: ActiveOrganization; refreshLists: boolean }) => {
    const version = ++generation.current
    readController.current?.abort()
    const controller = new AbortController()
    readController.current = controller
    const isCurrent = () => version === generation.current && !controller.signal.aborted
    function clearWorkspace() {
      setWorkspace(null)
      onContextChange?.(null)
      setAction(null)
      setAccessEditor(null)
      setError(null)
      setErrorTarget(null)
    }
    if (!background) {
      clearWorkspace()
      setLoading(true)
    }
    const fetchSelection = () => tenancyRequest<ApiSuccessResponse<ActiveOrganization>>('active-organization', { signal: controller.signal })
    const fetchLists = () => Promise.all([
      tenancyRequest<Page<AccessibleOrganization>>('accessible-organizations?per_page=10&page=' + nextPages.organizations, { signal: controller.signal }),
      tenancyRequest<Page<Invitation>>('my-invitations?per_page=10&page=' + nextPages.inbox, { signal: controller.signal }),
    ])
    try {
      const [selection, lists] = await Promise.all([fetchSelection(), background ? null : fetchLists()])
      if (!isCurrent()) return
      if (selection.data.context && selection.data.context.actor_user_id !== userId) {
        clearWorkspace()
        return
      }
      const unchanged = background !== undefined && sameOrganizationAccess(background.active, selection.data)
      let active = selection.data
      // Preserve the context object so unchanged access does not restart child reads or reset editors.
      if (unchanged) {
        active = background.active.confirmed === active.confirmed ? background.active : { ...background.active, confirmed: active.confirmed }
      }
      if (unchanged && !background.refreshLists) {
        setWorkspace((current) => current && current.active.confirmed !== active.confirmed ? { ...current, active } : current)
        return
      }
      if (background && !unchanged) {
        clearWorkspace()
        setLoading(true)
      }
      const [organizations, inbox] = lists ?? await fetchLists()
      if (!isCurrent()) return
      const options = { signal: controller.signal, revision: active.revision ?? undefined }
      const prefix = 'tenants/' + active.context?.organization_id
      const [memberships, invitations] = await Promise.all([
        active.context?.permissions.includes('memberships.manage') ? tenancyRequest<Page<Membership>>(prefix + '/memberships?per_page=10&page=' + nextPages.memberships, options) : null,
        active.context?.permissions.includes('invitations.manage') ? tenancyRequest<Page<Invitation>>(prefix + '/invitations?per_page=10&page=' + nextPages.invitations, options) : null,
      ])
      if (!isCurrent()) return
      setWorkspace({ active, organizations, inbox, memberships, invitations })
      if (!unchanged) onContextChange?.(active.context)
      if (selection.notification) notify(selection.notification)
    } catch (failure) {
      if (isCurrent() && !isAbortError(failure)) {
        clearWorkspace()
        setError(asApiError(failure))
      }
    } finally {
      if (isCurrent()) setLoading(false)
    }
  }, [notify, onContextChange, userId])

  const cancelReads = useCallback(() => {
    generation.current++
    readController.current?.abort()
  }, [])

  useEffect(() => {
    let disposed = false
    void Promise.resolve().then(() => { if (!disposed) void reload(pages) })
    return () => {
      disposed = true
      cancelReads()
    }
  }, [pages, reload, cancelReads])

  useEffect(() => {
    function onFocus() {
      if (busy || loading) return
      // Object-scoped access can change independently of the organization snapshot.
      const scopedView = tool?.id === 'equipes-autorisees' || tool?.id === 'acces-limites'
      void reload(pages, active && !scopedView ? { active, refreshLists: overview } : undefined)
    }
    window.addEventListener('focus', onFocus)
    return () => window.removeEventListener('focus', onFocus)
  }, [active, busy, loading, overview, pages, reload, tool?.id])

  useEffect(() => {
    if (action) {
      previousFocus.current = document.activeElement instanceof HTMLElement ? document.activeElement : null
      confirmRegion.current?.focus()
    } else {
      previousFocus.current?.focus()
    }
  }, [action])

  useEffect(() => {
    const onHistoryChange = () => {
      focusSectionTitle.current = true
      setAccessEditor(null)
      setRequested(requestedSection())
    }
    window.addEventListener('popstate', onHistoryChange)
    return () => window.removeEventListener('popstate', onHistoryChange)
  }, [])

  function navigate(next: string, tool?: string) {
    if (action || busy) return
    window.history.pushState(null, '', organizationUrl(next, tool))
    focusSectionTitle.current = true
    setAccessEditor(null)
    setRequested(requestedSection())
  }

  function cancelAction() {
    setAction(null)
  }

  function closeAccessEditor() {
    setAccessEditor(null)
    setError(null)
    setErrorTarget(null)
    sectionTitle.current?.focus()
  }

  useEffect(() => { if (accessEditor) accessEditorTitle.current?.focus() }, [accessEditor])

  async function selectOrganization(id: string) {
    setBusy(true)
    setWorkspace(null)
    onContextChange?.(null)
    setAction(null)
    setError(null)
    generation.current++
    readController.current?.abort()
    try {
      await tenancyRequest<ApiSuccessResponse<ActiveOrganization>>('active-organization', { method: 'POST', body: { organization_id: id } })
      if (requested.section !== 'apercu' || requested.tool) {
        window.history.replaceState(null, '', organizationUrl('apercu'))
        setRequested(requestedSection())
      }
      setPages(firstPages)
      await reload(firstPages)
      sectionTitle.current?.focus()
    } catch (failure) {
      setError(asApiError(failure))
      setLoading(false)
    } finally {
      setBusy(false)
    }
  }

  async function performAction() {
    if (!action) return
    const pending = action
    setBusy(true)
    setError(null)
    try {
      if (pending.context?.context && pending.context.revision) {
        await tenancyRequest('active-organization/confirmation', {
          method: 'POST',
          body: { organization_id: pending.context.context.organization_id, revision: pending.context.revision, confirm: true },
        })
      }
      const result = await tenancyRequest<ApiSuccessResponse<unknown>>(pending.path, {
        method: pending.method, body: pending.body, revision: pending.context?.revision ?? pending.revision,
      })
      notify(result.notification ?? { type: 'success', message: t('tenant.done') })
      await reload(pages)
    } catch (failure) {
      const apiError = asApiError(failure)
      if (apiError.code === 'TENANT_CONTEXT_CHANGED' || apiError.status === 404 || apiError.status === 403) {
        await reload(pages)
        notify({ type: 'warning', message: t('tenant.changed'), requestId: apiError.requestId })
      } else {
        setError(apiError)
        setErrorTarget(pending.path)
      }
    } finally {
      setAction(null)
      setBusy(false)
    }
  }

  function invite(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!workspace?.active.context) return
    const data = new FormData(event.currentTarget)
    setError(null)
    setAction({
      label: t('tenant.send'), organizationName: workspace.active.context.name,
      path: 'tenants/' + workspace.active.context.organization_id + '/invitations', method: 'POST',
      context: workspace.active,
      body: { email: data.get('email'), roles: [data.get('role')], starts_at: toIso(data.get('starts_at')) ?? undefined, ends_at: toIso(data.get('ends_at')) },
    })
  }

  function changePage(list: keyof Pages, page: number) {
    setPages((current) => ({ ...current, [list]: page }))
  }

  const invalidatePeopleContext = useCallback(() => { void reload(pages) }, [reload, pages])

  const ownMembership = selected?.membership_id
  const disabled = loading || busy || action !== null

  useEffect(() => {
    if (focusSectionTitle.current) {
      sectionTitle.current?.focus()
      focusSectionTitle.current = false
    }
  }, [requested])

  return (
    <section className="mt-5 grid min-w-0 gap-5" aria-labelledby="tenant-title" aria-busy={loading || busy}>
      {selected && (
        <SectionLinks
          label={t('tenant.sections.navigation')}
          links={available.map(({ id, label }) => ({ id, label: t(label), href: organizationUrl(id).href }))}
          current={section.id}
          onNavigate={navigate}
        />
      )}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 id="tenant-title" ref={sectionTitle} tabIndex={-1} className="scroll-mt-36 text-xl font-bold sm:text-2xl">
          {t(overview ? 'tenant.title' : section.label)}
        </h2>
        {overview && <Button disabled={disabled} onClick={() => void reload(pages)}>{t('tenant.refresh')}</Button>}
      </div>
      {section.tools.length > 1 && tool && (
        <SectionLinks
          label={t('workspace.tools')}
          links={section.tools.map(({ id, label }) => ({ id, label: t(label), href: organizationUrl(section.id, id).href }))}
          current={tool.id}
          onNavigate={(id) => navigate(section.id, id)}
        />
      )}
      {overview && selected && (
        <div role="status" className="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl border border-cyan-400/25 bg-cyan-400/10 px-4 py-3">
          <p className="font-semibold wrap-anywhere">{t('tenant.active', { name: selected.name })}</p>
          <p className="rounded-md bg-cyan-300/10 px-2 py-1 text-xs font-medium text-cyan-100">{roleNames(selected.roles)}</p>
        </div>
      )}
      {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
      {loading && <AsyncState variant="loading" title={t('tenant.loading')} />}
      {action && (
        <div ref={confirmRegion} tabIndex={-1} role="region" aria-label={t('tenant.confirmTitle')} className="grid gap-4 rounded-xl border border-amber-400/40 bg-amber-400/10 p-5">
          <h3 className="text-lg font-bold">{t('tenant.confirmTitle')}</h3>
          <p>{t('tenant.confirmMessage', { action: action.label, name: action.organizationName })}</p>
          <div className="flex flex-wrap gap-3">
            <Button variant="primary" isLoading={busy} loadingLabel={t('tenant.busy')} onClick={() => void performAction()}>{t('tenant.confirm')}</Button>
            <Button disabled={busy} onClick={cancelAction}>{t('tenant.cancel')}</Button>
          </div>
        </div>
      )}
      {workspace && (
        <>
          {tool?.id === 'equipes-autorisees' && selected && <ScopedTeamsPanel key={'team-scopes:' + selected.organization_id + ':' + workspace.active.revision} active={workspace.active} disabled={disabled} onInvalid={invalidatePeopleContext} />}
          {tool?.id === 'acces-limites' && selected && <ContextualModulesPanel key={'scoped:' + selected.organization_id + ':' + workspace.active.revision} active={workspace.active} disabled={disabled} onInvalid={invalidatePeopleContext} />}
          {(tool?.id === 'roles' || tool?.id === 'affectations') && selected && (
            <RoleManagementPanel key={selected.organization_id + ':' + tool.id} view={tool.id} active={workspace.active} disabled={disabled} onChanged={() => void reload(pages)} />
          )}
          {tool?.id === 'recherche' && selected && <Suspense fallback={<p>{t('search.loading')}</p>}><TenantSearchPanel active={workspace.active} disabled={disabled} onInvalid={invalidatePeopleContext} /></Suspense>}
          {tool?.id === 'tableaux' && selected && <Suspense fallback={<p>{t('dashboards.loading')}</p>}><DashboardPanel active={workspace.active} disabled={disabled} onInvalid={invalidatePeopleContext} /></Suspense>}
          {tool?.id === 'fiches' && selected && <PeoplePanel key={selected.organization_id + ':' + workspace.active.revision + ':' + selected.permissions.join(',')} active={workspace.active} disabled={disabled} onInvalid={invalidatePeopleContext} />}
          {tool?.id === 'jeux' && selected && <OrganizationGamesPanel key={'games:' + selected.organization_id + ':' + workspace.active.revision + ':' + selected.permissions.join(',')} active={workspace.active} disabled={disabled} onInvalid={invalidatePeopleContext} />}
          {tool?.id === 'liste' && selected && <TeamsPanel key={'teams:' + selected.organization_id + ':' + workspace.active.revision + ':' + selected.permissions.join(',')} active={workspace.active} disabled={disabled} onInvalid={invalidatePeopleContext} />}
          {tool?.id === 'adversaires' && selected && <ExternalOpponentsPanel key={'opponents:' + selected.organization_id + ':' + workspace.active.revision + ':' + selected.permissions.join(',')} active={workspace.active} disabled={disabled} onInvalid={invalidatePeopleContext} />}
          {tool?.id === 'journal' && selected && <SensitiveAccessPanel key={'sensitive-audit:' + selected.organization_id + ':' + workspace.active.revision + ':' + selected.permissions.join(',')} active={workspace.active} disabled={disabled} onInvalid={invalidatePeopleContext} />}
          {tool?.id === 'medias' && selected && <Suspense fallback={<p role="status">{t('media.loading')}</p>}><MediaPanel key={'media:' + selected.organization_id + ':' + workspace.active.revision + ':' + selected.permissions.join(',')} active={workspace.active} disabled={disabled} onInvalid={invalidatePeopleContext} /></Suspense>}
          {tool?.id === 'taches' && selected && <Suspense fallback={<p role="status">{t('tasks.loading')}</p>}><WorkTasksPanel key={'tasks:' + selected.organization_id + ':' + workspace.active.revision + ':' + selected.permissions.join(',')} active={workspace.active} disabled={disabled} onInvalid={invalidatePeopleContext} /></Suspense>}
          {tool?.id === 'communications' && selected && <Suspense fallback={<p role="status">{t('communication.loading')}</p>}><CommunicationPanel key={'communication:' + selected.organization_id + ':' + workspace.active.revision + ':' + selected.permissions.join(',')} active={workspace.active} disabled={disabled} onInvalid={invalidatePeopleContext} /></Suspense>}
          {tool?.id === 'disponibilites' && selected && <Suspense fallback={<p role="status">{t('availability.loading')}</p>}><AvailabilityPanel key={'availability:' + selected.organization_id + ':' + workspace.active.revision + ':' + selected.permissions.join(',')} active={workspace.active} disabled={disabled} onInvalid={invalidatePeopleContext} /></Suspense>}
          {tool?.id === 'activites' && selected && <Suspense fallback={<p role="status">{t('calendar.loading')}</p>}><CalendarPanel key={'calendar:' + selected.organization_id + ':' + workspace.active.revision + ':' + selected.permissions.join(',')} active={workspace.active} disabled={disabled} onInvalid={invalidatePeopleContext} /></Suspense>}
          {tool?.id === 'parcours' && selected && <Suspense fallback={<p role="status">{t('academy.loading')}</p>}><AcademyPersonalPanel key={'academy-self:' + selected.organization_id + ':' + workspace.active.revision + ':' + selected.permissions.join(',')} active={workspace.active} disabled={disabled} onInvalid={invalidatePeopleContext} /></Suspense>}
          {tool?.id === 'cohortes' && selected && <Suspense fallback={<p role="status">{t('academy.loading')}</p>}><AcademyPanel key={'academy:' + selected.organization_id + ':' + workspace.active.revision + ':' + selected.permissions.join(',')} active={workspace.active} disabled={disabled} onInvalid={invalidatePeopleContext} /></Suspense>}
          {tool?.id === 'statistiques' && selected && <Suspense fallback={<p role="status">{t('observations.loading')}</p>}><ObservationPanel key={'observations:' + selected.organization_id + ':' + workspace.active.revision + ':' + selected.permissions.join(',')} active={workspace.active} disabled={disabled} onInvalid={invalidatePeopleContext} /></Suspense>}
          {tool?.id === 'competitions' && selected && <Suspense fallback={<p role="status">{t('competitions.loading')}</p>}><ExternalCompetitionsPanel key={'competitions:' + selected.organization_id + ':' + workspace.active.revision + ':' + selected.permissions.join(',')} active={workspace.active} disabled={disabled} onInvalid={invalidatePeopleContext} /></Suspense>}
          {tool?.id === 'matchs' && selected && <Suspense fallback={<p role="status">{t('matches.loading')}</p>}><MatchesPanel key={'matches:' + selected.organization_id + ':' + workspace.active.revision + ':' + selected.permissions.join(',')} active={workspace.active} disabled={disabled} onInvalid={invalidatePeopleContext} /></Suspense>}
          {tool?.id === 'campagnes' && selected && <Suspense fallback={<p role="status">{t('sponsorships.loading')}</p>}><SponsorshipDashboardPanel key={'campaign-dashboard:' + selected.organization_id + ':' + workspace.active.revision + ':' + selected.permissions.join(',')} active={workspace.active} disabled={disabled} onInvalid={invalidatePeopleContext} /></Suspense>}
          {tool?.id === 'partenariats' && selected && <PartnershipsPanel key={'partnerships:' + selected.organization_id + ':' + workspace.active.revision + ':' + selected.permissions.join(',')} active={workspace.active} disabled={disabled} onInvalid={invalidatePeopleContext} />}
          {tool?.id === 'contrats' && selected && <ContractsPanel key={'contracts:' + selected.organization_id + ':' + workspace.active.revision + ':' + selected.permissions.join(',')} active={workspace.active} disabled={disabled} onInvalid={invalidatePeopleContext} />}
          {overview && (
            <div className="grid min-w-0 gap-5 lg:grid-cols-2">
              <div className="ui-panel grid content-start gap-3 p-4 sm:p-5">
                <h3 className="text-lg font-semibold">{t('tenant.sections.chooseOrganization')}</h3>
                {workspace.organizations.data.length === 0 && <p className="text-slate-400">{t('tenant.empty')}</p>}
                {workspace.organizations.data.map((org) => (
                  <div key={org.id} className="ui-card flex flex-wrap items-center justify-between gap-3 p-3">
                    <div className="min-w-0">
                      <p className="font-medium wrap-anywhere">{org.name}</p>
                      <p className="mt-1 text-sm text-slate-400">{roleNames(org.roles)}</p>
                    </div>
                    {org.id === selected?.organization_id ? (
                      <span className="rounded-md bg-cyan-300/10 px-2 py-1 text-xs font-medium text-cyan-100">{t('tenant.sections.current')}</span>
                    ) : (
                      <Button variant={!selected ? 'primary' : 'secondary'} disabled={disabled} aria-label={t('tenant.select', { name: org.name })} onClick={() => void selectOrganization(org.id)}>
                        {t('tenant.sections.open')}
                      </Button>
                    )}
                  </div>
                ))}
                <Pagination currentPage={workspace.organizations.meta.current_page} totalPages={workspace.organizations.meta.last_page} disabled={disabled} onPageChange={(page) => changePage('organizations', page)} />
              </div>
              <div className="ui-panel grid content-start gap-3 p-4 sm:p-5">
                <h3 className="text-lg font-semibold">{t('tenant.inbox')}</h3>
                {workspace.inbox.data.length === 0 && <p className="text-slate-400">{t('tenant.noInvitations')}</p>}
                {workspace.inbox.data.map((invitation) => (
                  <div key={invitation.id} className="grid gap-3 rounded-xl border border-white/10 p-4">
                    <p>{invitation.organization_name} · {roleNames(invitation.roles)} · {t(`tenant.status.${invitation.status}`)}</p>
                    <Period start={invitation.starts_at} end={invitation.ends_at} />
                    <p className="text-sm text-slate-400">{t('tenant.expires', { date: formatDate(invitation.expires_at) })}</p>
                    {invitation.status === 'pending' && <div className="flex gap-3">{(['accepted', 'declined'] as const).map((decision) => (
                      <Button key={decision} disabled={disabled} onClick={() => setAction({
                        label: t(decision === 'accepted' ? 'tenant.accept' : 'tenant.decline'), organizationName: invitation.organization_name,
                        path: 'my-invitations/' + invitation.id, method: 'PUT', body: { decision, confirm: true },
                        revision: workspace.active.revision ?? undefined,
                      })}>{t(decision === 'accepted' ? 'tenant.accept' : 'tenant.decline')}</Button>
                    ))}</div>}
                  </div>
                ))}
                <Pagination currentPage={workspace.inbox.meta.current_page} totalPages={workspace.inbox.meta.last_page} disabled={disabled} onPageChange={(page) => changePage('inbox', page)} />
                {selected && ownMembership && <Button className="justify-self-start" disabled={disabled} onClick={() => setAction({
                  label: t('tenant.leave'), organizationName: selected.name, path: 'my-memberships/' + ownMembership, method: 'DELETE', body: { confirm: true }, context: workspace.active,
                })}>{t('tenant.leave')}</Button>}
              </div>
            </div>
          )}
          {selected && (tool?.id === 'membres' || tool?.id === 'invitations') && (
            <>
              {tool.id === 'invitations' && workspace.invitations && accessEditor === 'invite' && <form noValidate className="ui-editor grid items-start gap-4 p-5 md:grid-cols-2" onSubmit={invite}>
                <h3 ref={accessEditorTitle} tabIndex={-1} className="scroll-mt-28 text-xl font-bold md:col-span-2">{t('tenant.invite')}</h3>
                <FormField name="email" type="email" required label={t('tenant.email')} disabled={disabled} error={errorTarget?.endsWith('/invitations') ? error?.fieldErrors.email?.[0] : undefined} />
                <label className="grid gap-2">{t('tenant.role')}<select name="role" className={selectClass} disabled={disabled}>
                  <option value="member">{t('tenant.role.member')}</option>
                  {selected.is_owner && <option value="administrator">{t('tenant.role.administrator')}</option>}
                </select></label>
                <FormField name="starts_at" type="datetime-local" step="1" label={t('tenant.start')} hint={t('tenant.dateHint')} disabled={disabled} error={errorTarget?.endsWith('/invitations') ? error?.fieldErrors.starts_at?.[0] : undefined} />
                <FormField name="ends_at" type="datetime-local" step="1" label={t('tenant.end')} disabled={disabled} error={errorTarget?.endsWith('/invitations') ? error?.fieldErrors.ends_at?.[0] : undefined} />
                <div className="flex flex-wrap gap-2 md:col-span-2">
                  <Button type="submit" variant="primary" disabled={disabled}>{t('tenant.send')}</Button>
                  <Button disabled={disabled} onClick={closeAccessEditor}>{t('tenant.cancel')}</Button>
                </div>
              </form>}
              {tool.id === 'membres' && workspace.memberships && <div className="grid gap-3">
                {!accessEditor && <h3 className="text-xl font-bold">{t('tenant.members')}</h3>}
                {!accessEditor && workspace.memberships.data.length === 0 && <p className="ui-state">{t('tenant.noMembers')}</p>}
                {workspace.memberships.data.map((member) => accessEditor === member.id ? (
                  <MembershipEditor key={member.id} titleRef={accessEditorTitle} membership={member} active={workspace.active} disabled={disabled} errors={errorTarget?.endsWith('/memberships/' + member.id) ? error?.fieldErrors ?? {} : {}} onAction={setAction} onCancel={closeAccessEditor} />
                ) : !accessEditor && <article key={member.id} className="ui-card flex flex-wrap items-center justify-between gap-3 p-4">
                  <div className="grid min-w-0 gap-1">
                    <p className="font-semibold">{t('tenant.account', { id: member.user_id })} · {roleNames(member.roles)} · {t(`tenant.status.${member.status}`)}</p>
                    <Period start={member.starts_at} end={member.ends_at} />
                  </div>
                  {(selected.is_owner || !member.roles.includes('administrator')) && <Button disabled={disabled} aria-label={t('tenant.editMembershipFor', { id: member.user_id })} onClick={() => setAccessEditor(member.id)}>{t('tenant.editMembership')}</Button>}
                </article>)}
                {!accessEditor && <Pagination currentPage={workspace.memberships.meta.current_page} totalPages={workspace.memberships.meta.last_page} disabled={disabled} onPageChange={(page) => changePage('memberships', page)} />}
              </div>}
              {tool.id === 'invitations' && workspace.invitations && !accessEditor && <div className="grid gap-3">
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <h3 className="text-xl font-bold">{t('tenant.manageInvitations')}</h3>
                  <Button variant="primary" disabled={disabled} onClick={() => setAccessEditor('invite')}>{t('tenant.invite')}</Button>
                </div>
                {workspace.invitations.data.length === 0 && <p className="ui-state">{t('tenant.noInvitations')}</p>}
                {workspace.invitations.data.map((invitation) => (
                  <div key={invitation.id} className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-white/10 p-4">
                    <p className="min-w-0 break-all">{invitation.email} · {roleNames(invitation.roles)} · {t(`tenant.status.${invitation.status}`)}</p>
                    {invitation.status === 'pending' && (selected.is_owner || !invitation.roles.includes('administrator')) && (
                      <Button disabled={disabled} onClick={() => setAction({
                        label: t('tenant.revoke'), organizationName: selected.name, path: 'tenants/' + selected.organization_id + '/invitations/' + invitation.id,
                        method: 'DELETE', context: workspace.active,
                      })}>{t('tenant.revoke')}</Button>
                    )}
                  </div>
                ))}
                <Pagination currentPage={workspace.invitations.meta.current_page} totalPages={workspace.invitations.meta.last_page} disabled={disabled} onPageChange={(page) => changePage('invitations', page)} />
              </div>}
            </>
          )}
        </>
      )}
    </section>
  )
}

function MembershipEditor({ membership, active, disabled, errors, onAction, onCancel, titleRef }: {
  membership: Membership; active: ActiveOrganization; disabled: boolean; errors: ApiFieldErrors; onAction: (action: Action) => void
  onCancel: () => void; titleRef: Ref<HTMLHeadingElement>
}) {
  const context = active.context
  const canEdit = context?.is_owner || !membership.roles.includes('administrator')
  const primaryRole = membership.roles.includes('administrator') ? 'administrator' : 'member'
  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!context) return
    const data = new FormData(event.currentTarget)
    const role = String(data.get('role') ?? primaryRole)
    onAction({
      label: t('tenant.update') + ' — ' + t('tenant.account', { id: membership.user_id }), organizationName: context.name,
      path: 'tenants/' + context.organization_id + '/memberships/' + membership.id, method: 'PUT', context: active,
      body: { status: data.get('status'), roles: context.is_owner && role !== primaryRole ? [role] : membership.roles,
        starts_at: toIso(data.get('starts_at')), ends_at: toIso(data.get('ends_at')) },
    })
  }
  return (
    <form noValidate aria-label={t('tenant.account', { id: membership.user_id })} onSubmit={submit} className="ui-editor grid items-start gap-3 p-4 md:grid-cols-2">
      <h3 ref={titleRef} tabIndex={-1} className="scroll-mt-28 font-semibold md:col-span-2">{t('tenant.editMembershipFor', { id: membership.user_id })}</h3>
      {canEdit ? <>
        <label className="grid gap-2">{t('tenant.role')}<select name="role" className={selectClass} defaultValue={primaryRole} disabled={disabled || !context?.is_owner}>
          <option value="member">{t('tenant.role.member')}</option>
          {context?.is_owner && <option value="administrator">{t('tenant.role.administrator')}</option>}
        </select></label>
        <label className="grid gap-2">{t('tenant.membershipStatus')}<select name="status" className={selectClass} defaultValue={membership.status} disabled={disabled}>
          {(['active', 'suspended', 'revoked'] as const).map((status) => <option value={status} key={status}>{t(`tenant.status.${status}`)}</option>)}
        </select></label>
        <FormField name="starts_at" type="datetime-local" step="1" required label={t('tenant.start')} defaultValue={toLocalDate(membership.starts_at)} disabled={disabled} error={errors.starts_at?.[0]} />
        <FormField name="ends_at" type="datetime-local" step="1" label={t('tenant.end')} defaultValue={membership.ends_at ? toLocalDate(membership.ends_at) : ''} disabled={disabled} error={errors.ends_at?.[0]} />
      </> : <Period start={membership.starts_at} end={membership.ends_at} />}
      <div className="flex flex-wrap gap-2 md:col-span-2">
        {canEdit && <Button type="submit" variant="primary" disabled={disabled}>{t('tenant.update')}</Button>}
        <Button disabled={disabled} onClick={onCancel}>{t('tenant.cancel')}</Button>
      </div>
    </form>
  )
}
function roleNames(roles: TenantRole[]) {
  return roles.map((role) => t(`tenant.role.${role}`)).join(', ')
}
function formatDate(value: string) {
  return new Intl.DateTimeFormat('fr', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value))
}
function Period({ start, end }: { start: string; end: string | null }) {
  return <p className="text-sm text-slate-400">{t(end ? 'tenant.until' : 'tenant.since', { start: formatDate(start), end: end ? formatDate(end) : '' })}</p>
}
function toIso(value: FormDataEntryValue | null) {
  return value ? new Date(String(value)).toISOString().replace('.000Z', '+00:00') : null
}
function toLocalDate(value: string) {
  const date = new Date(value)
  return new Date(date.getTime() - date.getTimezoneOffset() * 60_000).toISOString().slice(0, 19)
}
function asApiError(error: unknown) {
  return error instanceof ApiClientError ? error : new ApiClientError(t('tenant.error'))
}
