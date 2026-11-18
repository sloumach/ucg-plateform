import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react'
import { tenancyRequest, type ActiveOrganization, type Page } from '../../api/tenancy'
import type { ApiSuccessResponse } from '../../api/contracts'
import { ApiClientError, isAbortError } from '../../api/errors'
import type { OrganizationRole, PermissionCatalogue, PermissionKey, RoleAssignment, ScopeType } from '../../api/roles'
import { t } from '../../i18n/fr'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { ScopeSourcePicker } from './ScopeSourcePicker'
import { RoleAssignmentMemberPicker } from './RoleAssignmentMemberPicker'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { AsyncState } from '../feedback/AsyncState'
import { useNotifications } from '../feedback/notificationContext'

const selectClass = 'ui-input'
type Data = { roles: Page<OrganizationRole> | null; assignments: Page<RoleAssignment> | null; catalogue: PermissionCatalogue | null }
type Pending = { label: string; path: string; method: 'POST' | 'PUT'; body: Record<string, unknown>; form: HTMLFormElement }
const permissionNames: Record<PermissionKey, Parameters<typeof t>[0]> = {
  'calendar.read': 'roles.permission.calendar.read', 'calendar.write': 'roles.permission.calendar.write',
  'academy.feedback.manage': 'roles.permission.academy.feedback.manage',
  'academy.notes.manage': 'roles.permission.academy.notes.manage',
  'academy.self.read': 'roles.permission.academy.self.read',
  'academy.attendance.manage': 'roles.permission.academy.attendance.manage',
  'academy.read': 'roles.permission.academy.read', 'academy.manage': 'roles.permission.academy.manage',
  'sports.registrations.read': 'roles.permission.sports.registrations.read', 'sports.registrations.write': 'roles.permission.sports.registrations.write',
  'sports.statistics.import': 'roles.permission.sports.statistics.import',
  'sports.statistics.read': 'roles.permission.sports.statistics.read', 'sports.statistics.write': 'roles.permission.sports.statistics.write', 'sports.statistics.correct': 'roles.permission.sports.statistics.correct',
  'sports.competitions.read': 'roles.permission.sports.competitions.read', 'sports.competitions.write': 'roles.permission.sports.competitions.write',
  'sports.correct': 'roles.permission.sports.correct',
  'sports.notes.read': 'roles.permission.sports.notes.read', 'sports.notes.write': 'roles.permission.sports.notes.write',
  'sports.strategies.read': 'roles.permission.sports.strategies.read', 'sports.strategies.write': 'roles.permission.sports.strategies.write',
  'games.manage': 'roles.permission.games.manage',
  'teams.manage': 'roles.permission.teams.manage',
  'organization.view': 'roles.permission.organization.view', 'organization.update': 'roles.permission.organization.update',
  'memberships.manage': 'roles.permission.memberships.manage', 'invitations.manage': 'roles.permission.invitations.manage',
  'roles.assign': 'roles.permission.roles.assign', 'sports.read': 'roles.permission.sports.read', 'sports.write': 'roles.permission.sports.write',
  'sports.validate': 'roles.permission.sports.validate', 'sports.publish': 'roles.permission.sports.publish',
  'administrative.read': 'roles.permission.administrative.read', 'administrative.write': 'roles.permission.administrative.write',
  'administrative.validate': 'roles.permission.administrative.validate', 'financial.read': 'roles.permission.financial.read',
  'financial.write': 'roles.permission.financial.write', 'financial.validate': 'roles.permission.financial.validate',
  'audit.view': 'roles.permission.audit.view',
  'reporting.direction': 'roles.permission.reporting.direction',
  'reporting.export': 'roles.permission.reporting.export',
  'reporting.manager': 'roles.permission.reporting.manager',
  'reporting.coach': 'roles.permission.reporting.coach',
  'reporting.player': 'roles.permission.reporting.player',
  'reporting.administration': 'roles.permission.reporting.administration',
  'partnerships.read': 'roles.permission.partnerships.read',
  'partnerships.write': 'roles.permission.partnerships.write',
  'partnerships.validate': 'roles.permission.partnerships.validate',
  'media.read': 'roles.permission.media.read',
  'media.write': 'roles.permission.media.write',
  'media.publish': 'roles.permission.media.publish',
  'communication.read': 'roles.permission.communication.read',
  'communication.write': 'roles.permission.communication.write',
  'communication.publish': 'roles.permission.communication.publish',
  'communication.comment': 'roles.permission.communication.comment',
  'tasks.read': 'roles.permission.tasks.read',
  'tasks.manage': 'roles.permission.tasks.manage',
  'calendar.export': 'roles.permission.calendar.export',
  'calendar.publish': 'roles.permission.calendar.publish',
  'calendar.resources.manage': 'roles.permission.calendar.resources.manage',
  'calendar.conflicts.override': 'roles.permission.calendar.conflicts.override',
  'calendar.invitations.manage': 'roles.permission.calendar.invitations.manage',
  'calendar.invitations.respond': 'roles.permission.calendar.invitations.respond',
  'availability.self': 'roles.permission.availability.self',
  'availability.read': 'roles.permission.availability.read',
  'availability.manage': 'roles.permission.availability.manage',
}
function permissionName(permission: PermissionKey) { return permissionNames[permission] ? t(permissionNames[permission]) : permission }
function scopeName(scope: ScopeType) { return t(('roles.scope.' + scope) as Parameters<typeof t>[0]) }

const permissionGroups = [
  { label: 'roles.group.organization', prefixes: ['organization', 'memberships', 'invitations', 'roles', 'audit'] },
  { label: 'roles.group.sports', prefixes: ['sports', 'games', 'teams'] },
  { label: 'roles.group.calendar', prefixes: ['calendar', 'availability'] },
  { label: 'roles.group.academy', prefixes: ['academy'] },
  { label: 'roles.group.reporting', prefixes: ['reporting'] },
  { label: 'roles.group.administration', prefixes: ['administrative', 'financial', 'partnerships'] },
  { label: 'roles.group.communication', prefixes: ['media', 'communication', 'tasks'] },
] as const

function normalizeSearch(value: string) {
  return value.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase()
}

export function RoleManagementPanel({ active, disabled: parentDisabled, onChanged, view }: {
  active: ActiveOrganization; disabled: boolean; onChanged: () => void; view: 'roles' | 'affectations'
}) {
  const context = active.context
  const orgId = context?.organization_id
  const canAssign = Boolean(context?.permissions.includes('roles.assign'))
  const showingRoles = view === 'roles'
  const title = t(showingRoles ? 'roles.title' : canAssign ? 'roles.assignments' : 'roles.myAssignments')
  const [data, setData] = useState<Data | null>(null)
  const [error, setError] = useState<ApiClientError | null>(null)
  const [errorTarget, setErrorTarget] = useState<string | null>(null)
  const [initialStart, setInitialStart] = useState('')
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [scopeType, setScopeType] = useState<ScopeType>('organization')
  const [rolePage, setRolePage] = useState(1)
  const [assignmentPage, setAssignmentPage] = useState(1)
  const [memberRequired, setMemberRequired] = useState(false)
  const [editing, setEditing] = useState<OrganizationRole | null | undefined>(undefined)
  const [assignmentEditor, setAssignmentEditor] = useState<string | null>(null)
  const [pending, setPending] = useState<Pending | null>(null)
  const generation = useRef(0)
  const readController = useRef<AbortController | null>(null)
  const alive = useRef(true)
  const confirmRegion = useRef<HTMLDivElement>(null)
  const panelHeading = useRef<HTMLHeadingElement>(null)
  const editorHeading = useRef<HTMLHeadingElement>(null)
  const returnFocus = useRef<HTMLElement | null>(null)
  const { notify } = useNotifications()
  const revision = active.revision ?? undefined
  const load = useCallback(async () => {
    if (!orgId) return
    const current = ++generation.current
    readController.current?.abort()
    const controller = new AbortController()
    readController.current = controller
    setLoading(true); setError(null)
    const prefix = 'tenants/' + orgId + '/'
    const options = { signal: controller.signal, revision }
    try {
      const [assignments, roles, catalogue] = await Promise.all([
        showingRoles ? null : tenancyRequest<Page<RoleAssignment>>(prefix + (canAssign ? 'role-assignments' : 'my-role-assignments') + '?per_page=10&page=' + assignmentPage, options),
        canAssign ? tenancyRequest<Page<OrganizationRole>>(prefix + 'roles?per_page=10&page=' + rolePage, options) : null,
        canAssign ? tenancyRequest<ApiSuccessResponse<PermissionCatalogue>>(prefix + 'permission-catalogue', options) : null,
      ])
      if (alive.current && current === generation.current && !controller.signal.aborted) setData({ assignments, roles, catalogue: catalogue?.data ?? null })
    } catch (failure) {
      if (alive.current && current === generation.current && !controller.signal.aborted && !isAbortError(failure)) {
        setData(null)
        setAssignmentEditor(null)
        setError(apiError(failure))
      }
    } finally {
      if (alive.current && current === generation.current && !controller.signal.aborted) setLoading(false)
    }
  }, [orgId, revision, canAssign, rolePage, assignmentPage, showingRoles])

  const cancelReads = useCallback(() => { generation.current++; readController.current?.abort() }, [])
  useEffect(() => {
    let disposed = false
    alive.current = true
    void Promise.resolve().then(() => { if (!disposed) void load() })
    return () => { disposed = true; alive.current = false; cancelReads() }
  }, [load, cancelReads])
  useEffect(() => { if (pending) confirmRegion.current?.focus() }, [pending])
  useEffect(() => { if (assignmentEditor || editing !== undefined) editorHeading.current?.focus() }, [assignmentEditor, editing])
  useEffect(() => { if (!busy && !pending) returnFocus.current?.focus() }, [busy, pending, error])
  if (!context) return null
  const disabled = parentDisabled || loading || busy || pending !== null

  function propose(event: FormEvent<HTMLFormElement>, label: string, path: string, method: 'POST' | 'PUT', body: Record<string, unknown>, memberLabel?: string) {
    event.preventDefault(); setError(null); setErrorTarget(null)
    const target = memberLabel ?? (typeof body.name === 'string' ? body.name : body.user_id ? t('tenant.account', { id: body.user_id as number }) : '')
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
      setPending(null); setEditing(undefined); setAssignmentEditor(null)
      onChanged()
    } catch (failure) {
      if (!alive.current) return
      const nextError = apiError(failure)
      setError(nextError); setErrorTarget(action.path); setPending(null)
      const field = Object.keys(nextError.fieldErrors)[0]?.split('.')[0]
      const focusField = field === 'permissions' ? 'permission_search' : field === 'user_id' ? 'member_search' : field
      const element = focusField
        ? action.form.elements.namedItem(focusField)
        : null
      if (element instanceof HTMLElement) returnFocus.current = element
      if (nextError.status === 401 || nextError.status === 403 || nextError.status === 409) {
        notify({ type: 'error', message: nextError.message, requestId: nextError.requestId })
        onChanged()
      }
    } finally { if (alive.current) setBusy(false) }
  }
  const fieldError = (name: string, path: string) => path === errorTarget ? error?.fieldErrors[name]?.[0] : undefined
  const assignmentsPath = 'tenants/' + orgId + '/role-assignments'
  const assignments = data?.assignments
  const editedAssignment = assignments?.data.find(({ id }) => id === assignmentEditor)

  function openAssignment(editor: string) {
    setError(null)
    setErrorTarget(null)
    setMemberRequired(false)
    setScopeType('organization')
    setInitialStart(localDate(new Date().toISOString()))
    setAssignmentEditor(editor)
  }

  function closeAssignment() {
    setAssignmentEditor(null)
    setError(null)
    setErrorTarget(null)
    panelHeading.current?.focus()
  }

  function closeRole() {
    setEditing(undefined)
    setError(null)
    setErrorTarget(null)
    panelHeading.current?.focus()
  }

  return <section aria-labelledby={'roles-title-' + orgId} className="ui-panel grid min-w-0 grid-cols-1 gap-4 p-3 sm:p-5">
    <div className="flex flex-wrap items-center justify-between gap-3">
      <h3 id={'roles-title-' + orgId} ref={panelHeading} tabIndex={-1} className="text-xl font-bold">{title}</h3>
      <div className="flex flex-wrap gap-2">
        {!assignmentEditor && editing === undefined && <Button disabled={disabled} onClick={() => void load()}>{t(showingRoles ? 'roles.refresh' : 'roles.refreshAssignments')}</Button>}
        {assignments && canAssign && !assignmentEditor && <Button variant="primary" disabled={disabled} onClick={() => openAssignment('new')}>{t('roles.newAssignment')}</Button>}
      </div>
    </div>
    {!showingRoles && <p className="text-slate-400">{t('roles.description')}</p>}
    {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
    {loading && <AsyncState variant="loading" title={t('roles.loading')} />}
    {pending && <div ref={confirmRegion} tabIndex={-1} role="region" aria-label={t('tenant.confirmTitle')} className="grid min-w-0 gap-3 rounded-xl border border-amber-400/40 bg-amber-400/10 p-4">
      <p>{t('tenant.confirmMessage', { action: pending.label, name: context.name })}</p>
      <Button isLoading={busy} loadingLabel={t('tenant.busy')} onClick={() => void save()}>{t('tenant.confirm')}</Button>
      <Button disabled={busy} onClick={cancel}>{t('tenant.cancel')}</Button>
    </div>}
    {showingRoles && data && <>
      {editing === undefined && data.catalogue?.can_manage && <Button variant="primary" className="justify-self-start" disabled={disabled} onClick={() => setEditing(null)}>{t('roles.create')}</Button>}
      {editing !== undefined && data.catalogue?.can_manage && <>
        <h4 ref={editorHeading} tabIndex={-1} className="scroll-mt-28 font-semibold">{editing ? t('roles.edit', { name: editing.name }) : t('roles.create')}</h4>
        <RoleEditor key={editing?.id ?? 'new'} role={editing} catalogue={data.catalogue} disabled={disabled}
          errors={errorTarget?.includes('/roles') ? error?.fieldErrors ?? {} : {}} onCancel={closeRole} onSubmit={(event, body) => propose(event, t('roles.saveRole'), 'tenants/' + orgId + '/roles' + (editing ? '/' + editing.id : ''), editing ? 'PUT' : 'POST', body)} />
      </>}
      {editing === undefined && data.roles && <>
        <h4 className="font-semibold">{t('roles.catalogue')}</h4>
        {data.roles.data.length === 0 && <p>{t('roles.noRoles')}</p>}
        {data.roles.data.map((role) => <div key={role.id} className="ui-card grid gap-2 p-4">
          <p className="font-semibold">{role.name} · {t(role.active ? 'roles.active' : 'roles.inactive')}</p>
          <details className="text-sm text-muted">
            <summary className="w-fit cursor-pointer py-1 font-medium text-cyan-100">{t('roles.permissionDetails', { count: role.permissions.length })}</summary>
            <p className="mt-1">{role.permissions.map(permissionName).join(', ')}</p>
          </details>
          {role.privileged && <p className="text-sm text-amber-200">{t('roles.mfaRequired')}</p>}
          {data.catalogue?.can_manage && <Button disabled={disabled} onClick={() => setEditing(role)}>{t('roles.edit', { name: role.name })}</Button>}
        </div>)}
        <Pagination currentPage={data.roles.meta.current_page} totalPages={data.roles.meta.last_page} disabled={disabled} onPageChange={(page) => { setEditing(undefined); setRolePage(page) }} />
      </>}
    </>}
    {assignments && <>
      {canAssign && assignmentEditor === 'new' && data.roles && data.catalogue && <form noValidate aria-label={t('roles.assign')} className="ui-editor grid min-w-0 grid-cols-1 items-start gap-3 p-3 sm:p-4 md:grid-cols-2" onSubmit={(event) => {
        event.preventDefault()
        if (disabled) return
        const member = event.currentTarget.querySelector<HTMLInputElement>('input[name="user_id"]:checked, input[name="user_id"][type="hidden"]')
        if (!member) {
          setMemberRequired(true)
          event.currentTarget.querySelector<HTMLInputElement>('input[name="member_search"]')?.focus()
          return
        }
        const form = new FormData(event.currentTarget)
        propose(event, t('roles.assign'), assignmentsPath, 'POST', {
          role_id: form.get('role_id'), user_id: form.get('user_id'), scope_type: form.get('scope_type'),
          scope_id: form.get('scope_type') === 'organization' ? orgId : form.get('scope_id'), status: 'active',
          starts_at: iso(form.get('starts_at')), ends_at: iso(form.get('ends_at')),
        }, member.dataset.memberLabel)
      }}>
        <h4 ref={editorHeading} tabIndex={-1} className="scroll-mt-28 font-semibold md:col-span-2">{t('roles.assign')}</h4>
        <RoleAssignmentMemberPicker key={orgId + ':' + revision} organizationId={context.organization_id} revision={revision} disabled={disabled}
          error={memberRequired ? t('roles.memberRequired') : fieldError('user_id', assignmentsPath)} onSelectionChange={() => setMemberRequired(false)} />
        <label className="grid content-start gap-2 text-sm leading-5 font-semibold">{t('roles.role')}<select name="role_id" required className={`${selectClass} text-base font-normal`} disabled={disabled}>
          <option value="">{t('roles.choose')}</option>{data.roles.data.filter((role) => role.active && role.permissions.every((key) => { const permission = data.catalogue!.permissions.find((entry) => entry.key === key); return permission?.scope_types ? permission.scope_types.includes(scopeType) : scopeType === 'organization' || !permission?.organization_only })).map((role) => <option key={role.id} value={role.id}>{role.name}</option>)}
        </select></label>
        <label className="grid content-start gap-2 text-sm leading-5 font-semibold">{t('roles.scope')}<select name="scope_type" className={`${selectClass} text-base font-normal`} disabled={disabled} value={scopeType} onChange={(event) => setScopeType(event.target.value as ScopeType)}>
          {data.catalogue.scope_types.map((type) => <option key={type} value={type}>{scopeName(type)}</option>)}
        </select></label>
        {data.roles.meta.last_page > 1 && <div className="md:col-span-2">
          <p className="mb-2 text-sm text-muted">{t('roles.rolePages')}</p>
          <Pagination currentPage={data.roles.meta.current_page} totalPages={data.roles.meta.last_page} disabled={disabled} onPageChange={setRolePage} />
        </div>}

        {scopeType !== 'organization' && <div className="min-w-0 md:col-span-2"><ScopeSourcePicker key={orgId + ':' + scopeType} active={active} type={scopeType} disabled={disabled} error={error?.fieldErrors.scope_id?.[0]} /></div>}
        <FormField name="starts_at" type="datetime-local" step="1" defaultValue={initialStart} label={t('roles.start')} required disabled={disabled} error={error?.fieldErrors.starts_at?.[0]} />
        <FormField name="ends_at" type="datetime-local" step="1" label={t('roles.end')} disabled={disabled} error={error?.fieldErrors.ends_at?.[0]} />
        <p className="text-sm text-slate-400 md:col-span-2">{t('roles.delegationHint')}</p>
        <div className="flex flex-wrap gap-2 md:col-span-2">
          <Button type="submit" variant="primary" disabled={disabled}>{t('roles.assign')}</Button>
          <Button disabled={disabled} onClick={closeAssignment}>{t('tenant.cancel')}</Button>
        </div>
      </form>}
      {canAssign && editedAssignment && editedAssignment.status !== 'revoked' && <form key={editedAssignment.id} noValidate className="ui-editor grid min-w-0 grid-cols-1 items-start gap-3 p-3 sm:p-4 md:grid-cols-2" aria-label={assignmentLabel(editedAssignment)}
        onSubmit={(event) => {
          const form = new FormData(event.currentTarget)
          propose(event, t('roles.saveAssignment'), assignmentsPath + '/' + editedAssignment.id, 'PUT', {
            role_id: editedAssignment.role_id, user_id: editedAssignment.user_id, scope_type: editedAssignment.scope_type, scope_id: editedAssignment.scope_id,
            status: form.get('status'), starts_at: iso(form.get('starts_at')), ends_at: iso(form.get('ends_at')), version: editedAssignment.version,
          })
        }}>
        <h4 ref={editorHeading} tabIndex={-1} className="scroll-mt-28 font-semibold md:col-span-2">{assignmentLabel(editedAssignment)}</h4>
        <p className="text-sm text-muted md:col-span-2">{scopeName(editedAssignment.scope_type)}{editedAssignment.scope_type !== 'organization' && ` · ${editedAssignment.scope_id}`}</p>
        <label className="grid content-start gap-2 md:col-span-2">{t('roles.assignmentStatus')}<select name="status" className={selectClass} defaultValue={editedAssignment.status} disabled={disabled}>
          {(['active', 'suspended', 'revoked'] as const).map((status) => <option key={status} value={status}>{t('tenant.status.' + status as Parameters<typeof t>[0])}</option>)}
        </select></label>
        <FormField name="starts_at" type="datetime-local" step="1" defaultValue={localDate(editedAssignment.starts_at)} label={t('roles.start')} required disabled={disabled} error={fieldError('starts_at', assignmentsPath + '/' + editedAssignment.id)} />
        <FormField name="ends_at" type="datetime-local" step="1" defaultValue={editedAssignment.ends_at ? localDate(editedAssignment.ends_at) : ''} label={t('roles.end')} disabled={disabled} error={fieldError('ends_at', assignmentsPath + '/' + editedAssignment.id)} />
        <div className="flex flex-wrap gap-2 md:col-span-2">
          <Button type="submit" variant="primary" disabled={disabled}>{t('roles.saveAssignment')}</Button>
          <Button disabled={disabled} onClick={closeAssignment}>{t('tenant.cancel')}</Button>
        </div>
      </form>}
      {!assignmentEditor && <>
        {assignments.data.length === 0 && <p className="ui-state">{t('roles.noAssignments')}</p>}
        {assignments.data.length > 0 && <ul className="grid min-w-0 gap-2">
          {assignments.data.map((assignment) => <li key={assignment.id} className="ui-card flex flex-wrap items-center justify-between gap-3 p-4">
            <div className="grid min-w-0 gap-1">
              <p className="font-semibold">{assignment.role_name} · {t('tenant.account', { id: assignment.user_id })} · {t('tenant.status.' + assignment.status as Parameters<typeof t>[0])}</p>
              <p className="text-sm text-muted">{scopeName(assignment.scope_type)}{assignment.scope_type !== 'organization' && ` · ${assignment.scope_id}`}</p>
              <p className="text-sm text-muted">{t(assignment.ends_at ? 'tenant.until' : 'tenant.since', { start: new Date(assignment.starts_at).toLocaleString('fr'), end: assignment.ends_at ? new Date(assignment.ends_at).toLocaleString('fr') : '' })}</p>
            </div>
            {canAssign && assignment.status !== 'revoked' && <Button disabled={disabled} aria-label={t('roles.editAssignmentFor', { name: assignment.role_name, id: assignment.user_id })} onClick={() => openAssignment(assignment.id)}>{t('roles.editAssignment')}</Button>}
          </li>)}
        </ul>}
        <Pagination currentPage={assignments.meta.current_page} totalPages={assignments.meta.last_page} disabled={disabled} onPageChange={setAssignmentPage} />
      </>}
    </>}
  </section>
}

function assignmentLabel(assignment: RoleAssignment) {
  return t('roles.assignment', { name: assignment.role_name, id: assignment.user_id })
}

function RoleEditor({ role, catalogue, disabled, errors, onCancel, onSubmit }: {
  role: OrganizationRole | null; catalogue: PermissionCatalogue; disabled: boolean; errors: Record<string, string[]>
  onCancel: () => void; onSubmit: (event: FormEvent<HTMLFormElement>, body: Record<string, unknown>) => void
}) {
  const [query, setQuery] = useState('')
  const [selectedOnly, setSelectedOnly] = useState(false)
  const [expanded, setExpanded] = useState<string | null>(null)
  const [selected, setSelected] = useState(() => new Set(catalogue.permissions
    .filter(({ key }) => role?.permissions.includes(key)).map(({ key }) => key)))
  const words = normalizeSearch(query).trim().split(/\s+/).filter(Boolean)
  const filtering = words.length > 0 || selectedOnly
  const permissions = catalogue.permissions.filter((permission) => !selectedOnly || selected.has(permission.key))
    .filter((permission) => {
      const text = normalizeSearch(permissionName(permission.key) + ' ' + permission.key)
      return words.every((word) => text.includes(word))
    })
  const groups = permissionGroups.map(({ label, prefixes }) => ({
    label,
    permissions: permissions.filter(({ key }) => prefixes.some((prefix) => key.startsWith(prefix + '.'))),
  }))
  const otherPermissions = permissions.filter(({ key }) => !permissionGroups.some(({ prefixes }) => prefixes.some((prefix) => key.startsWith(prefix + '.'))))
  const visibleGroups = [...groups, { label: 'roles.group.other' as const, permissions: otherPermissions }].filter((group) => group.permissions.length > 0)
  const permissionErrors = Object.entries(errors).filter(([field]) => field === 'permissions' || field.startsWith('permissions.'))

  return <form noValidate aria-label={t('roles.saveRole')} className="ui-editor grid min-w-0 gap-4 p-4" onSubmit={(event) => {
    const form = new FormData(event.currentTarget)
    const chosen = catalogue.permissions.filter(({ key }) => selected.has(key)).map(({ key }) => key)
    onSubmit(event, { key: form.get('key'), name: form.get('name'), permissions: chosen, active: form.has('active'), ...(role ? { version: role.version } : {}) })
  }}>
    <div className="grid min-w-0 gap-3 sm:grid-cols-2">
      <FormField name="key" label={t('roles.key')} defaultValue={role?.key ?? ''} readOnly={role !== null} disabled={disabled} required error={errors.key?.[0]} />
      <FormField name="name" label={t('roles.name')} defaultValue={role?.name ?? ''} disabled={disabled} required error={errors.name?.[0]} />
    </div>
    <fieldset disabled={disabled} className="grid min-w-0 gap-3">
      <legend className="mb-2 font-semibold">{t('roles.permissions')}</legend>
      <div className="flex flex-wrap items-end gap-3">
        <div className="min-w-48 flex-1">
          <FormField name="permission_search" type="search" label={t('roles.searchPermissions')} value={query} onChange={(event) => setQuery(event.target.value)} />
        </div>
        <Button aria-pressed={selectedOnly} className="aria-pressed:border-accent aria-pressed:text-accent" onClick={() => setSelectedOnly(!selectedOnly)}>
          {t('roles.selectedOnly')}
        </Button>
      </div>
      <p role="status" className="text-sm text-muted">{t('roles.selectionCount', { count: selected.size, total: catalogue.permissions.length })}</p>
      {permissionErrors.length > 0 && <p role="alert" className="text-sm text-rose-200">{permissionErrors[0][1][0]}</p>}
      <div className="grid min-w-0 gap-2">
        {visibleGroups.length === 0 && <p className="ui-state text-sm">{t('roles.noMatchingPermissions')}</p>}
        {visibleGroups.map((group) => {
          const open = filtering || expanded === group.label
          return <div key={group.label} className="ui-card min-w-0">
            {filtering ? (
              <h4 className="px-3 pt-3 text-sm font-semibold">{t(group.label)}</h4>
            ) : (
              <Button aria-expanded={open} className="w-full justify-start border-transparent bg-transparent" onClick={() => setExpanded(open ? null : group.label)}>
                <span>{t(group.label)}</span>
                <span className="ms-auto shrink-0 text-xs text-muted">{group.permissions.filter(({ key }) => selected.has(key)).length}/{group.permissions.length}</span>
              </Button>
            )}
            {open && <div className="grid min-w-0 gap-2 p-3 md:grid-cols-2">
              {group.permissions.map((permission) => <label key={permission.key} className="flex min-w-0 items-start gap-2 rounded-lg border border-outline bg-field p-2.5 text-sm has-checked:border-cyan-600">
                <input type="checkbox" name="permissions" value={permission.key} checked={selected.has(permission.key)} className="mt-0.5 size-4 shrink-0"
                  onChange={(event) => {
                    const next = new Set(selected)
                    if (event.target.checked) next.add(permission.key)
                    else next.delete(permission.key)
                    setSelected(next)
                  }} />
                <span className="min-w-0 wrap-anywhere">
                  {permissionName(permission.key)}
                  {permission.privileged && <span className="ms-2 inline-block text-xs text-amber-200">{t('roles.mfaBadge')}</span>}
                </span>
              </label>)}
            </div>}
          </div>
        })}
      </div>
    </fieldset>
    <label className="flex items-center gap-3"><input name="active" type="checkbox" defaultChecked={role?.active ?? true} disabled={disabled} />{t('roles.active')}</label>
    <div className="flex flex-wrap gap-3"><Button variant="primary" type="submit" disabled={disabled}>{t('roles.saveRole')}</Button><Button disabled={disabled} onClick={onCancel}>{t('tenant.cancel')}</Button></div>
  </form>
}
function apiError(failure: unknown) { return failure instanceof ApiClientError ? failure : new ApiClientError(t('roles.error')) }
function localDate(value: string) { const date = new Date(value); return new Date(date.getTime() - date.getTimezoneOffset() * 60_000).toISOString().slice(0, 19) }
function iso(value: FormDataEntryValue | null) { if (!value) return null; const date = new Date(String(value)); return Number.isNaN(date.getTime()) ? String(value) : date.toISOString().replace('.000Z', '+00:00') }
