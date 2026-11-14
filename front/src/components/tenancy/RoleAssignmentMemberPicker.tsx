import { useEffect, useId, useState } from 'react'
import { ApiClientError, isAbortError } from '../../api/errors'
import { tenancyRequest, type Page } from '../../api/tenancy'
import { t } from '../../i18n/fr'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'

type Member = { id: number; name: string; email: string }
type Result = { query: string; members: Page<Member> } | { query: string; failure: ApiClientError }

export function RoleAssignmentMemberPicker({ organizationId, revision, disabled, error, onSelectionChange }: {
  organizationId: string; revision?: string; disabled: boolean; error?: string; onSelectionChange: () => void
}) {
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [result, setResult] = useState<Result | null>(null)
  const [selected, setSelected] = useState<Member | null>(null)
  const [retry, setRetry] = useState(0)
  const errorId = useId()
  const term = search.trim()
  const params = new URLSearchParams({ page: String(page), per_page: '10' })
  if (term) params.set('q', term)
  const query = params.toString()
  const current = result?.query === query ? result : null
  const members = current && 'members' in current ? current.members : null
  const failure = current && 'failure' in current ? current.failure : null
  const options = members?.data ?? []

  useEffect(() => {
    const controller = new AbortController()
    const timer = window.setTimeout(async () => {
      try {
        const members = await tenancyRequest<Page<Member>>('tenants/' + organizationId + '/role-assignment-members?' + query,
          { signal: controller.signal, revision })
        if (!controller.signal.aborted) setResult({ query, members })
      } catch (cause) {
        if (!controller.signal.aborted && !isAbortError(cause)) {
          const failure = cause instanceof ApiClientError ? cause : new ApiClientError(t('roles.membersFailed'))
          setResult({ query, failure })
        }
      }
    }, term ? 250 : 0)
    return () => {
      window.clearTimeout(timer)
      controller.abort()
    }
  }, [organizationId, revision, query, retry, term])

  return <fieldset className="grid min-w-0 gap-2 md:col-span-2">
    <legend className="mb-2 text-sm font-semibold">{t('roles.chooseMember')}</legend>
    <FormField type="search" name="member_search" label={t('roles.memberSearch')} value={search} maxLength={254} disabled={disabled}
      spellCheck={false} autoComplete="off" aria-invalid={error ? true : undefined} aria-describedby={error ? errorId : undefined}
      onKeyDown={(event) => { if (event.key === 'Enter' && !event.nativeEvent.isComposing) event.preventDefault() }}
      onChange={(event) => {
        if (event.target.value.trim() === term) {
          setSearch(event.target.value)
          return
        }
        setResult(null)
        setSelected(null)
        onSelectionChange()
        setSearch(event.target.value)
        setPage(1)
      }} />
    {error && <p id={errorId} role="alert" className="text-sm text-rose-200">{error}</p>}
    {(!current || members?.data.length === 0) && <p role="status" className="text-sm text-muted">
      {t(!current ? 'roles.membersLoading' : 'roles.noMembersFound')}
    </p>}
    {options.length > 0 && <div className="max-h-52 overflow-y-auto rounded-lg border border-control bg-field" aria-live="polite">
      {options.map((member) => <label key={member.id} className="flex min-w-0 cursor-pointer items-center gap-3 border-b border-control p-2.5 last:border-b-0 hover:bg-raised has-checked:bg-raised">
        <input type="radio" name="user_id" value={member.id} aria-label={memberLabel(member)} data-member-label={memberLabel(member)}
          checked={selected?.id === member.id} disabled={disabled}
          onChange={() => { setSelected(member); onSelectionChange() }} className="size-4 shrink-0 accent-cyan-300" />
        <span className="grid min-w-0 text-sm wrap-anywhere">
          <span className="font-semibold">{member.name}</span>
          <span className="text-muted">{member.email}</span>
        </span>
      </label>)}
    </div>}
    {selected && !options.some((member) => member.id === selected.id) && <div className="text-sm wrap-anywhere">
      <input type="hidden" name="user_id" value={selected.id} data-member-label={memberLabel(selected)} disabled={disabled} />
      {t('roles.selectedMember', { name: selected.name, email: selected.email })}
    </div>}
    {failure && <>
      <Alert tone="error" title={failure.message} requestId={failure.requestId} />
      <Button type="button" disabled={disabled} onClick={() => { setResult(null); setRetry((value) => value + 1) }}>{t('common.retry')}</Button>
    </>}
    {members && <Pagination currentPage={members.meta.current_page} totalPages={members.meta.last_page} disabled={disabled}
      onPageChange={setPage} />}
  </fieldset>
}

function memberLabel(member: Member): string {
  return `${member.name} — ${member.email}`
}
