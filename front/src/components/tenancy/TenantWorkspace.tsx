import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react'
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
import { Button } from '../Button'
import { FormField } from '../FormField'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { AsyncState } from '../feedback/AsyncState'
import { useNotifications } from '../feedback/notificationContext'

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
const selectClass = 'rounded-xl border border-white/15 bg-slate-900 px-3 py-3 text-slate-100'

export function TenantWorkspace({ onContextChange }: { onContextChange?: (context: ActiveOrganization['context']) => void }) {
  const [workspace, setWorkspace] = useState<Workspace | null>(null)
  const [pages, setPages] = useState<Pages>(firstPages)
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiClientError | null>(null)
  const [errorTarget, setErrorTarget] = useState<string | null>(null)
  const [action, setAction] = useState<Action | null>(null)
  const generation = useRef(0)
  const readController = useRef<AbortController | null>(null)
  const confirmRegion = useRef<HTMLDivElement>(null)
  const previousFocus = useRef<HTMLElement | null>(null)
  const { notify } = useNotifications()

  // A new read discards all tenant data; obsolete responses never repopulate it.
  const reload = useCallback(async (nextPages: Pages) => {
    const version = ++generation.current
    readController.current?.abort()
    const controller = new AbortController()
    readController.current = controller
    setWorkspace(null)
    onContextChange?.(null)
    setLoading(true)
    setAction(null)
    setError(null)
    setErrorTarget(null)
    try {
      const [selection, organizations, inbox] = await Promise.all([
        tenancyRequest<ApiSuccessResponse<ActiveOrganization>>('active-organization', { signal: controller.signal }),
        tenancyRequest<Page<AccessibleOrganization>>('accessible-organizations?per_page=10&page=' + nextPages.organizations, { signal: controller.signal }),
        tenancyRequest<Page<Invitation>>('my-invitations?per_page=10&page=' + nextPages.inbox, { signal: controller.signal }),
      ])
      const active = selection.data
      const canManage = active.context?.permissions.includes('memberships.manage')
      const options = { signal: controller.signal, revision: active.revision ?? undefined }
      const prefix = 'tenants/' + active.context?.organization_id
      const [memberships, invitations] = canManage ? await Promise.all([
        tenancyRequest<Page<Membership>>(prefix + '/memberships?per_page=10&page=' + nextPages.memberships, options),
        tenancyRequest<Page<Invitation>>(prefix + '/invitations?per_page=10&page=' + nextPages.invitations, options),
      ]) : [null, null]
      if (version !== generation.current || controller.signal.aborted) return
      setWorkspace({ active, organizations, inbox, memberships, invitations })
      onContextChange?.(active.context)
      if (selection.notification) notify(selection.notification)
    } catch (failure) {
      if (version === generation.current && !isAbortError(failure) && !controller.signal.aborted) {
        setError(asApiError(failure))
      }
    } finally {
      if (version === generation.current && !controller.signal.aborted) setLoading(false)
    }
  }, [notify, onContextChange])

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
      if (!busy) void reload(pages)
    }
    window.addEventListener('focus', onFocus)
    return () => window.removeEventListener('focus', onFocus)
  }, [busy, pages, reload])

  useEffect(() => {
    if (action) {
      previousFocus.current = document.activeElement instanceof HTMLElement ? document.activeElement : null
      confirmRegion.current?.focus()
    } else {
      previousFocus.current?.focus()
    }
  }, [action])

  function cancelAction() {
    setAction(null)
  }

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
      setPages(firstPages)
      await reload(firstPages)
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

  const active = workspace?.active
  const selected = active?.context
  const ownMembership = selected?.membership_id
  const disabled = loading || busy || action !== null

  return (
    <section className="grid gap-6 border-t border-white/10 py-8" aria-labelledby="tenant-title" aria-busy={loading || busy}>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 id="tenant-title" className="text-2xl font-bold">{t('tenant.title')}</h2>
        <Button disabled={busy || loading} onClick={() => void reload(pages)}>{t('tenant.refresh')}</Button>
      </div>
      <p role="status" className="rounded-xl border border-cyan-400/30 bg-cyan-400/10 p-4 font-semibold">
        {selected ? t('tenant.active', { name: selected.name }) : t('tenant.none')}
        {selected && <span className="ml-3 text-sm text-cyan-200">{roleNames(selected.roles)}</span>}
      </p>
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
          <div className="grid gap-3">
            {workspace.organizations.data.length === 0 && <p className="text-slate-400">{t('tenant.empty')}</p>}
            <div className="flex flex-wrap gap-3">
              {workspace.organizations.data.map((org) => (
                <Button key={org.id} disabled={disabled || org.id === selected?.organization_id} onClick={() => void selectOrganization(org.id)}>
                  {t('tenant.select', { name: org.name })} · {roleNames(org.roles)}
                </Button>
              ))}
            </div>
            <Pagination currentPage={workspace.organizations.meta.current_page} totalPages={workspace.organizations.meta.last_page} disabled={disabled} onPageChange={(page) => changePage('organizations', page)} />
          </div>
          <div className="grid gap-3">
            <h3 className="text-xl font-bold">{t('tenant.inbox')}</h3>
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
          </div>
          {selected && workspace.memberships && workspace.invitations && (
            <>
              <form noValidate className="grid gap-4 rounded-xl border border-white/10 p-5 md:grid-cols-2" onSubmit={invite}>
                <h3 className="text-xl font-bold md:col-span-2">{t('tenant.invite')}</h3>
                <FormField name="email" type="email" required label={t('tenant.email')} disabled={disabled} error={errorTarget?.endsWith('/invitations') ? error?.fieldErrors.email?.[0] : undefined} />
                <label className="grid gap-2">{t('tenant.role')}<select name="role" className={selectClass} disabled={disabled}>
                  <option value="member">{t('tenant.role.member')}</option>
                  {selected.is_owner && <option value="administrator">{t('tenant.role.administrator')}</option>}
                </select></label>
                <FormField name="starts_at" type="datetime-local" step="1" label={t('tenant.start')} hint={t('tenant.dateHint')} disabled={disabled} error={errorTarget?.endsWith('/invitations') ? error?.fieldErrors.starts_at?.[0] : undefined} />
                <FormField name="ends_at" type="datetime-local" step="1" label={t('tenant.end')} disabled={disabled} error={errorTarget?.endsWith('/invitations') ? error?.fieldErrors.ends_at?.[0] : undefined} />
                <Button type="submit" variant="primary" disabled={disabled}>{t('tenant.send')}</Button>
              </form>
              <div className="grid gap-3">
                <h3 className="text-xl font-bold">{t('tenant.members')}</h3>
                {workspace.memberships.data.length === 0 && <p className="text-slate-400">{t('tenant.noMembers')}</p>}
                {workspace.memberships.data.map((member) => (
                  <MembershipEditor key={member.id} membership={member} active={workspace.active} disabled={disabled} errors={errorTarget?.endsWith('/memberships/' + member.id) ? error?.fieldErrors ?? {} : {}} onAction={setAction} />
                ))}
                <Pagination currentPage={workspace.memberships.meta.current_page} totalPages={workspace.memberships.meta.last_page} disabled={disabled} onPageChange={(page) => changePage('memberships', page)} />
              </div>
              <div className="grid gap-3">
                <h3 className="text-xl font-bold">{t('tenant.manageInvitations')}</h3>
                {workspace.invitations.data.length === 0 && <p className="text-slate-400">{t('tenant.noInvitations')}</p>}
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
              </div>
            </>
          )}
          {selected && ownMembership && <Button disabled={disabled} onClick={() => setAction({
            label: t('tenant.leave'), organizationName: selected.name, path: 'my-memberships/' + ownMembership, method: 'DELETE', body: { confirm: true }, context: workspace.active,
          })}>{t('tenant.leave')}</Button>}
        </>
      )}
    </section>
  )
}

function MembershipEditor({ membership, active, disabled, errors, onAction }: {
  membership: Membership; active: ActiveOrganization; disabled: boolean; errors: ApiFieldErrors; onAction: (action: Action) => void
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
    <form noValidate aria-label={t('tenant.account', { id: membership.user_id })} onSubmit={submit} className="grid gap-3 rounded-xl border border-white/10 p-4 md:grid-cols-2">
      <p className="font-semibold md:col-span-2">{t('tenant.account', { id: membership.user_id })} · {roleNames(membership.roles)} · {t(`tenant.status.${membership.status}`)}</p>
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
        <Button type="submit" disabled={disabled}>{t('tenant.update')}</Button>
      </> : <Period start={membership.starts_at} end={membership.ends_at} />}
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
  return <p className="text-sm text-slate-400">{t('tenant.until', { start: formatDate(start), end: end ? formatDate(end) : t('tenant.noEnd') })}</p>
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
