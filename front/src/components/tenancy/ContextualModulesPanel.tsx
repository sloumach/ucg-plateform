import { lazy, Suspense, useEffect, useState } from 'react'
import { tenancyRequest, type ActiveOrganization } from '../../api/tenancy'
import type { ApiSuccessResponse } from '../../api/contracts'
import { ApiClientError, isAbortError } from '../../api/errors'
import { t } from '../../i18n/fr'
import { Button } from '../Button'
import { Alert } from '../feedback/Alert'
const AcademyPanel = lazy(() => import('../academy/AcademyPanel').then((module) => ({ default: module.AcademyPanel })))
const CalendarPanel = lazy(() => import('../calendar/CalendarPanel').then((module) => ({ default: module.CalendarPanel })))
import { OrganizationGamesPanel } from '../teams/OrganizationGamesPanel'

export function ContextualModulesPanel({ active, disabled, onInvalid }: { active: ActiveOrganization; disabled: boolean; onInvalid: () => void }) {
  const [open, setOpen] = useState(false), [permissions, setPermissions] = useState<string[] | null>(null)
  const [failure, setFailure] = useState<ApiClientError | null>(null)
  useEffect(() => {
    if (!open) return
    const controller = new AbortController()
    void Promise.resolve().then(async () => {
      if (controller.signal.aborted) return
      setPermissions(null); setFailure(null)
      try {
        const response = await tenancyRequest<ApiSuccessResponse<{ permissions: string[] }>>('tenants/' + active.context!.organization_id + '/contextual-permissions',
          { signal: controller.signal, revision: active.revision ?? undefined })
        if (!controller.signal.aborted) setPermissions(response.data.permissions)
      } catch (cause) {
        if (!controller.signal.aborted && !isAbortError(cause)) setFailure(cause instanceof ApiClientError ? cause : new ApiClientError(t('roles.sourcesFailed'), { status: 0 }))
      }
    })
    return () => controller.abort()
  }, [active.context, active.revision, open])
  if (!active.context) return null
  const scoped = (prefix: string): ActiveOrganization => ({ ...active, context: { ...active.context!, permissions: [...new Set([...active.context!.permissions, ...(permissions ?? []).filter((key) => prefix === 'games' ? key === 'sports.read' || key === 'games.manage' : key.startsWith(prefix))])] } })
  return <section aria-label={t('roles.limitedTitle')} className="grid min-w-0 gap-3 rounded-xl border border-white/10 p-4">
    <Button disabled={disabled} onClick={() => { setPermissions(null); setFailure(null); setOpen((value) => !value) }}>{t('roles.limitedTitle')}</Button>
    {open && <>
      <p>{t('roles.limitedHint')}</p>
      {failure && <Alert tone="error" title={failure.message} requestId={failure.requestId} />}
      {!failure && permissions === null && <p role="status">{t('roles.loading')}</p>}
      {permissions?.length === 0 && <p>{t('roles.noLimited')}</p>}
      <Suspense fallback={<p role="status">{t('roles.loading')}</p>}>
        {permissions?.some((key) => key.startsWith('academy.')) && <AcademyPanel active={scoped('academy.')} disabled={disabled} onInvalid={onInvalid} limited />}
        {permissions?.some((key) => key.startsWith('calendar.')) && <CalendarPanel active={scoped('calendar.')} disabled={disabled} onInvalid={onInvalid} limited />}
        {permissions?.some((key) => key === 'sports.read' || key === 'games.manage') && <OrganizationGamesPanel active={scoped('games')} disabled={disabled} onInvalid={onInvalid} />}
      </Suspense>
    </>}
  </section>
}
