import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react'
import { tenancyRequest, type ActiveOrganization, type Page } from '../../api/tenancy'
import type { ApiSuccessResponse } from '../../api/contracts'
import { ApiClientError, isAbortError } from '../../api/errors'
import type { OrganizationRole, PermissionCatalogue, PermissionKey, RoleAssignment, ScopeType } from '../../api/roles'
import { t } from '../../i18n/fr'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { AsyncState } from '../feedback/AsyncState'
import { useNotifications } from '../feedback/notificationContext'

const selectClass = 'min-w-0 w-full rounded-xl border border-white/15 bg-slate-900 px-3 py-3 text-slate-100'
type Data = { roles: Page<OrganizationRole> | null; assignments: Page<RoleAssignment>; catalogue: PermissionCatalogue | null }
type Pending = { label: string; path: string; method: 'POST' | 'PUT'; body: Record<string, unknown>; form: HTMLFormElement }
const permissionNames: Record<PermissionKey, Parameters<typeof t>[0]> = {
  'organization.view': 'roles.permission.organization.view', 'organization.update': 'roles.permission.organization.update',
  'memberships.manage': 'roles.permission.memberships.manage', 'invitations.manage': 'roles.permission.invitations.manage',
  'roles.assign': 'roles.permission.roles.assign', 'sports.read': 'roles.permission.sports.read', 'sports.write': 'roles.permission.sports.write',
  'sports.validate': 'roles.permission.sports.validate', 'sports.publish': 'roles.permission.sports.publish',
  'administrative.read': 'roles.permission.administrative.read', 'administrative.write': 'roles.permission.administrative.write',
  'administrative.validate': 'roles.permission.administrative.validate', 'financial.read': 'roles.permission.financial.read',
  'financial.write': 'roles.permission.financial.write', 'financial.validate': 'roles.permission.financial.validate',
}
function permissionName(permission: PermissionKey) { return permissionNames[permission] ? t(permissionNames[permission]) : permission }
function scopeName(scope: ScopeType) { return t(('roles.scope.' + scope) as Parameters<typeof t>[0]) }

export function RoleManagementPanel({ active, disabled: parentDisabled, onChanged }: {
  active: ActiveOrganization; disabled: boolean; onChanged: () => void
}) {
  const context = active.context
  const orgId = context?.organization_id
  const canAssign = Boolean(context?.permissions.includes('roles.assign'))
  const [data, setData] = useState<Data | null>(null)
  const [error, setError] = useState<ApiClientError | null>(null)
  const [errorTarget, setErrorTarget] = useState<string | null>(null)
  const [initialStart] = useState(() => localDate(new Date().toISOString()))
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [rolePage, setRolePage] = useState(1)
  const [assignmentPage, setAssignmentPage] = useState(1)
  const [editing, setEditing] = useState<OrganizationRole | null | undefined>(undefined)
  const [pending, setPending] = useState<Pending | null>(null)
  const generation = useRef(0)
  const readController = useRef<AbortController | null>(null)
  const alive = useRef(true)
  const confirmRegion = useRef<HTMLDivElement>(null)
  const returnFocus = useRef<HTMLElement | null>(null)
  const { notify } = useNotifications()
  const revision = active.revision ?? undefined
  const load = useCallback(async () => {
    if (!orgId) return
    const current = ++generation.current
    readController.current?.abort()
    const controller = new AbortController()
    readController.current = controller
    setLoading(true); setData(null); setError(null)
    const prefix = 'tenants/' + orgId + '/'
    const options = { signal: controller.signal, revision }
    try {
      const [assignments, roles, catalogue] = await Promise.all([
        tenancyRequest<Page<RoleAssignment>>(prefix + (canAssign ? 'role-assignments' : 'my-role-assignments') + '?per_page=10&page=' + assignmentPage, options),
        canAssign ? tenancyRequest<Page<OrganizationRole>>(prefix + 'roles?per_page=10&page=' + rolePage, options) : null,
        canAssign ? tenancyRequest<ApiSuccessResponse<PermissionCatalogue>>(prefix + 'permission-catalogue', options) : null,
      ])
      if (alive.current && current === generation.current && !controller.signal.aborted) setData({ assignments, roles, catalogue: catalogue?.data ?? null })
    } catch (failure) {
      if (alive.current && current === generation.current && !controller.signal.aborted && !isAbortError(failure)) setError(apiError(failure))
    } finally {
      if (alive.current && current === generation.current && !controller.signal.aborted) setLoading(false)
    }
  }, [orgId, revision, canAssign, rolePage, assignmentPage])

  const cancelReads = useCallback(() => { generation.current++; readController.current?.abort() }, [])
  useEffect(() => {
    let disposed = false
    alive.current = true
    void Promise.resolve().then(() => { if (!disposed) void load() })
    return () => { disposed = true; alive.current = false; cancelReads() }
  }, [load, cancelReads])
  useEffect(() => { if (pending) confirmRegion.current?.focus() }, [pending])
  useEffect(() => { if (!busy && !pending) returnFocus.current?.focus() }, [busy, pending, error])
  if (!context) return null
  const disabled = parentDisabled || loading || busy || pending !== null

  function propose(event: FormEvent<HTMLFormElement>, label: string, path: string, method: 'POST' | 'PUT', body: Record<string, unknown>) {
    event.preventDefault(); setError(null); setErrorTarget(null)
    const target = typeof body.name === 'string' ? body.name : body.user_id ? t('tenant.account', { id: body.user_id as number }) : ''
    setPending({ label: target ? label + ' — ' + target : label, path, method, body, form: event.currentTarget })
  }
  function cancel() { returnFocus.current = pending?.form.querySelector<HTMLButtonElement>('button[type="submit"]') ?? null; setPending(null) }
  async function save() {
    if (!pending || !orgId) return
    const action = pending
    setBusy(true)
    try {
      if (!active.confirmed) await tenancyRequest('active-organization/confirmation', { method: 'POST', body: { organization_id: orgId, revision, confirm: true } })
      const response = await tenancyRequest<ApiSuccessResponse<unknown>>(action.path, { method: action.method, body: action.body, revision })
      if (!alive.current) return
      if (response.notification) notify(response.notification)
      setPending(null); setEditing(undefined)
      onChanged()
    } catch (failure) {
      if (!alive.current) return
      const nextError = apiError(failure)
      setError(nextError); setErrorTarget(action.path); setPending(null)
      const field = Object.keys(nextError.fieldErrors)[0]?.split('.')[0]
      const element = field ? Array.from(action.form.elements).find((item) => item instanceof HTMLElement && item.getAttribute('name') === field) : null
      if (element instanceof HTMLElement) returnFocus.current = element
      if (nextError.status === 401 || nextError.status === 403 || nextError.status === 409) {
        notify({ type: 'error', message: nextError.message, requestId: nextError.requestId })
        onChanged()
      }
    } finally { if (alive.current) setBusy(false) }
  }
  const fieldError = (name: string, path: string) => path === errorTarget ? error?.fieldErrors[name]?.[0] : undefined

  return <section aria-labelledby={'roles-title-' + orgId} className="grid gap-4 rounded-2xl border border-white/10 p-5">
    <div className="flex flex-wrap items-center justify-between gap-3">
      <h3 id={'roles-title-' + orgId} className="text-xl font-bold">{t('roles.title')}</h3>
      <Button disabled={busy || parentDisabled} onClick={() => void load()}>{t('roles.refresh')}</Button>
    </div>
    <p className="text-slate-400">{t('roles.description')}</p>
    {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
    {loading && <AsyncState variant="loading" title={t('roles.loading')} />}
    {pending && <div ref={confirmRegion} tabIndex={-1} role="region" aria-label={t('tenant.confirmTitle')} className="grid min-w-0 gap-3 rounded-xl border border-amber-400/40 bg-amber-400/10 p-4">
      <p>{t('tenant.confirmMessage', { action: pending.label, name: context.name })}</p>
      <Button isLoading={busy} loadingLabel={t('tenant.busy')} onClick={() => void save()}>{t('tenant.confirm')}</Button>
      <Button disabled={busy} onClick={cancel}>{t('tenant.cancel')}</Button>
    </div>}
    {data && <>
      {data.catalogue?.can_manage && <Button disabled={disabled} onClick={() => setEditing(null)}>{t('roles.create')}</Button>}
      {editing !== undefined && data.catalogue?.can_manage && <RoleEditor key={editing?.id ?? 'new'} role={editing} catalogue={data.catalogue} disabled={disabled}
        errors={errorTarget?.includes('/roles') ? error?.fieldErrors ?? {} : {}} onCancel={() => setEditing(undefined)} onSubmit={(event, body) => propose(event, t('roles.saveRole'), 'tenants/' + orgId + '/roles' + (editing ? '/' + editing.id : ''), editing ? 'PUT' : 'POST', body)} />}
      {data.roles && <>
        <h4 className="font-semibold">{t('roles.catalogue')}</h4>
        {data.roles.data.length === 0 && <p>{t('roles.noRoles')}</p>}
        {data.roles.data.map((role) => <div key={role.id} className="grid gap-2 rounded-xl border border-white/10 p-4">
          <p className="font-semibold">{role.name} · {t(role.active ? 'roles.active' : 'roles.inactive')}</p>
          <p>{role.permissions.map(permissionName).join(', ')}</p>
          {role.privileged && <p className="text-sm text-amber-200">{t('roles.mfaRequired')}</p>}
          {data.catalogue?.can_manage && <Button disabled={disabled} onClick={() => setEditing(role)}>{t('roles.edit', { name: role.name })}</Button>}
        </div>)}
        <Pagination currentPage={data.roles.meta.current_page} totalPages={data.roles.meta.last_page} disabled={disabled} onPageChange={(page) => { setEditing(undefined); setRolePage(page) }} />
      </>}
      {canAssign && data.roles && data.catalogue && <form noValidate aria-label={t('roles.assign')} className="grid gap-3 rounded-xl border border-white/10 p-4 md:grid-cols-2" onSubmit={(event) => {
        const form = new FormData(event.currentTarget)
        propose(event, t('roles.assign'), 'tenants/' + orgId + '/role-assignments', 'POST', {
          role_id: form.get('role_id'), user_id: form.get('user_id'), scope_type: form.get('scope_type'),
          scope_id: form.get('scope_type') === 'organization' ? orgId : form.get('scope_id'), status: 'active',
          starts_at: iso(form.get('starts_at')), ends_at: iso(form.get('ends_at')),
        })
      }}>
        <h4 className="font-semibold md:col-span-2">{t('roles.assign')}</h4>
        <label className="grid gap-2">{t('roles.role')}<select name="role_id" required className={selectClass} disabled={disabled}>
          <option value="">{t('roles.choose')}</option>{data.roles.data.filter((role) => role.active).map((role) => <option key={role.id} value={role.id}>{role.name}</option>)}
        </select></label>
        <FormField name="user_id" type="number" min="1" required label={t('roles.account')} disabled={disabled} error={error?.fieldErrors.user_id?.[0]} />
        <label className="grid gap-2">{t('roles.scope')}<select name="scope_type" className={selectClass} disabled={disabled}>
          {data.catalogue.scope_types.map((type) => <option key={type} value={type}>{scopeName(type)}</option>)}
        </select></label>
        {data.catalogue.scope_types.length > 1 && <FormField name="scope_id" label={t('roles.scopeId')} disabled={disabled} error={error?.fieldErrors.scope_id?.[0]} />}
        <FormField name="starts_at" type="datetime-local" step="1" defaultValue={initialStart} label={t('roles.start')} required disabled={disabled} error={error?.fieldErrors.starts_at?.[0]} />
        <FormField name="ends_at" type="datetime-local" step="1" label={t('roles.end')} disabled={disabled} error={error?.fieldErrors.ends_at?.[0]} />
        <p className="text-sm text-slate-400 md:col-span-2">{t('roles.delegationHint')}</p>
        <Button type="submit" disabled={disabled}>{t('roles.assign')}</Button>
      </form>}
      <h4 className="font-semibold">{t(canAssign ? 'roles.assignments' : 'roles.myAssignments')}</h4>
      {data.assignments.data.length === 0 && <p>{t('roles.noAssignments')}</p>}
      {data.assignments.data.map((assignment) => <form key={assignment.id} noValidate className="grid gap-3 rounded-xl border border-white/10 p-4 md:grid-cols-2" aria-label={t('roles.assignment', { name: assignment.role_name, id: assignment.user_id })}
        onSubmit={(event) => {
          const form = new FormData(event.currentTarget)
          propose(event, t('roles.saveAssignment'), 'tenants/' + orgId + '/role-assignments/' + assignment.id, 'PUT', {
            role_id: assignment.role_id, user_id: assignment.user_id, scope_type: assignment.scope_type, scope_id: assignment.scope_id,
            status: form.get('status'), starts_at: iso(form.get('starts_at')), ends_at: iso(form.get('ends_at')), version: assignment.version,
          })
        }}>
        <p className="font-semibold md:col-span-2">{assignment.role_name} · {t('tenant.account', { id: assignment.user_id })} · {scopeName(assignment.scope_type)} · {t('tenant.status.' + assignment.status as Parameters<typeof t>[0])}</p>
        {assignment.scope_type !== 'organization' && <p className="text-sm text-slate-400 md:col-span-2">{assignment.scope_id}</p>}
        {canAssign && assignment.status !== 'revoked' ? <>
          <label className="grid gap-2">{t('roles.assignmentStatus')}<select name="status" className={selectClass} defaultValue={assignment.status} disabled={disabled}>
            {(['active', 'suspended', 'revoked'] as const).map((status) => <option key={status} value={status}>{t('tenant.status.' + status as Parameters<typeof t>[0])}</option>)}
          </select></label>
          <FormField name="starts_at" type="datetime-local" step="1" defaultValue={localDate(assignment.starts_at)} label={t('roles.start')} required disabled={disabled} error={fieldError('starts_at', 'tenants/' + orgId + '/role-assignments/' + assignment.id)} />
          <FormField name="ends_at" type="datetime-local" step="1" defaultValue={assignment.ends_at ? localDate(assignment.ends_at) : ''} label={t('roles.end')} disabled={disabled} error={fieldError('ends_at', 'tenants/' + orgId + '/role-assignments/' + assignment.id)} />
          <Button type="submit" disabled={disabled}>{t('roles.saveAssignment')}</Button>
        </> : <p className="text-sm text-slate-400 md:col-span-2">{t('tenant.until', { start: new Date(assignment.starts_at).toLocaleString('fr'), end: assignment.ends_at ? new Date(assignment.ends_at).toLocaleString('fr') : t('tenant.noEnd') })}</p>}
      </form>)}
      <Pagination currentPage={data.assignments.meta.current_page} totalPages={data.assignments.meta.last_page} disabled={disabled} onPageChange={setAssignmentPage} />
    </>}
  </section>
}

function RoleEditor({ role, catalogue, disabled, errors, onCancel, onSubmit }: {
  role: OrganizationRole | null; catalogue: PermissionCatalogue; disabled: boolean; errors: Record<string, string[]>
  onCancel: () => void; onSubmit: (event: FormEvent<HTMLFormElement>, body: Record<string, unknown>) => void
}) {
  return <form noValidate aria-label={t('roles.saveRole')} className="grid gap-3 rounded-xl border border-cyan-400/30 p-4" onSubmit={(event) => {
    const form = new FormData(event.currentTarget)
    onSubmit(event, { key: form.get('key'), name: form.get('name'), permissions: form.getAll('permissions'), active: form.has('active'), ...(role ? { version: role.version } : {}) })
  }}>
    <FormField name="key" label={t('roles.key')} defaultValue={role?.key ?? ''} readOnly={role !== null} disabled={disabled} required error={errors.key?.[0]} />
    <FormField name="name" label={t('roles.name')} defaultValue={role?.name ?? ''} disabled={disabled} required error={errors.name?.[0]} />
    <fieldset disabled={disabled} className="grid gap-2">
      <legend className="mb-2 font-semibold">{t('roles.permissions')}</legend>
      {catalogue.permissions.map((permission) => <label key={permission.key} className="flex items-center gap-3">
        <input type="checkbox" name="permissions" value={permission.key} defaultChecked={role?.permissions.includes(permission.key)} />
        {permissionName(permission.key)}{permission.privileged && <span className="text-sm text-amber-200">{t('roles.mfaBadge')}</span>}
      </label>)}
      {(errors.permissions || errors['permissions.0']) && <p role="alert" className="text-rose-200">{errors.permissions?.[0] ?? errors['permissions.0']?.[0]}</p>}
    </fieldset>
    <label className="flex items-center gap-3"><input name="active" type="checkbox" defaultChecked={role?.active ?? true} disabled={disabled} />{t('roles.active')}</label>
    <div className="flex gap-3"><Button type="submit" disabled={disabled}>{t('roles.saveRole')}</Button><Button disabled={disabled} onClick={onCancel}>{t('tenant.cancel')}</Button></div>
  </form>
}
function apiError(failure: unknown) { return failure instanceof ApiClientError ? failure : new ApiClientError(t('roles.error')) }
function localDate(value: string) { const date = new Date(value); return new Date(date.getTime() - date.getTimezoneOffset() * 60_000).toISOString().slice(0, 19) }
function iso(value: FormDataEntryValue | null) { if (!value) return null; const date = new Date(String(value)); return Number.isNaN(date.getTime()) ? String(value) : date.toISOString().replace('.000Z', '+00:00') }
