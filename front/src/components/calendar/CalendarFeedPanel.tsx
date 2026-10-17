import { useEffect, useState } from 'react'
import { tenancyRequest, type ActiveOrganization } from '../../api/tenancy'
import { isAbortError } from '../../api/errors'
import type { ApiSuccessResponse } from '../../api/contracts'
import { feedText as text, type CalendarFeed, type CalendarFeedSecret } from '../../api/calendarFeed'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { Alert } from '../feedback/Alert'
import { useNotifications } from '../feedback/notificationContext'
import { apiError, shouldReloadContext } from '../people/peopleFormValues'
import { usePeopleMutation } from '../people/usePeopleMutation'

type Props = { active: ActiveOrganization; root: string; disabled: boolean; onInvalid: () => void }
export function CalendarFeedPanel(props: Props) {
  const [open, setOpen] = useState(false)
  const permissions = props.active.context?.permissions ?? []
  if (!permissions.includes('calendar.read') || !permissions.includes('calendar.export')) return null
  return <section aria-label={text('title')} className="grid min-w-0 gap-3 rounded-xl border border-slate-700 p-3">
    <h3 className="font-bold">{text('title')}</h3>
    <p className="text-sm text-slate-400">{text('hint')}</p>
    <Button disabled={props.disabled} onClick={() => setOpen(!open)}>{text(open ? 'close' : 'open')}</Button>
    {open && <CalendarFeedEditor key={props.active.context!.organization_id + ':' + props.active.revision} {...props} />}
  </section>
}

function CalendarFeedEditor({ active, root, disabled, onInvalid }: Props) {
  const path = root + '/feed', { notify } = useNotifications()
  const [feed, setFeed] = useState<CalendarFeed | null>(null), [secret, setSecret] = useState<string | null>(null)
  const [loading, setLoading] = useState(true), [error, setError] = useState<ReturnType<typeof apiError> | null>(null)
  const mutation = usePeopleMutation<CalendarFeed | CalendarFeedSecret>(active, (data) => {
    if ('url' in data) { setFeed(data.feed); setSecret(data.url) } else { setFeed(data); setSecret(null) }
  }, onInvalid)
  useEffect(() => {
    const controller = new AbortController()
    void tenancyRequest<ApiSuccessResponse<CalendarFeed | null>>(path, { revision: active.revision ?? undefined, signal: controller.signal }).then((result) => {
      if (!controller.signal.aborted) setFeed(result.data)
    }).catch((failure: unknown) => {
      if (controller.signal.aborted || isAbortError(failure)) return
      const next = apiError(failure); setError(next)
      if (shouldReloadContext(next)) onInvalid()
    }).finally(() => { if (!controller.signal.aborted) setLoading(false) })
    return () => controller.abort()
  }, [path, active.revision, onInvalid])
  const blocked = disabled || loading || error !== null || mutation.blocked
  const errors = mutation.errors(path)
  return <div className="grid min-w-0 gap-3">
    {loading && <p role="status">{text('loading')}</p>}
    {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
    {mutation.feedback}
    {!loading && !error && <>
      <p>{feed ? text(feed.status) : text('none')}</p>
      {feed && <p className="text-sm [overflow-wrap:anywhere]">{text('expires')} : {new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'UTC' }).format(new Date(feed.expires_at))} UTC</p>}
      <p className="text-sm text-amber-200">{text('warning')}</p>
      <form key={feed?.version ?? 'new'} aria-label={text('form')} className="grid min-w-0 gap-3 sm:grid-cols-3" onSubmit={(event) => {
        const values = new FormData(event.currentTarget)
        setSecret(null)
        mutation.propose(event, text(feed ? 'rotate' : 'create'), path, { expected_version: feed?.version ?? null,
          before_days: Number(values.get('before_days')), after_days: Number(values.get('after_days')), expires_in_days: Number(values.get('expires_in_days')), confirm_export: values.has('confirm_export') })
      }}>
        <FormField name="before_days" label={text('before')} type="number" min="0" max="30" step="1" defaultValue={feed?.before_days ?? 7} required disabled={blocked} error={errors.before_days?.[0]} />
        <FormField name="after_days" label={text('after')} type="number" min="1" max="90" step="1" defaultValue={feed?.after_days ?? 60} required disabled={blocked} error={errors.after_days?.[0]} />
        <FormField name="expires_in_days" label={text('validity')} type="number" min="1" max="90" step="1" defaultValue="30" required disabled={blocked} error={errors.expires_in_days?.[0]} />
        <p className="text-sm text-slate-400 sm:col-span-3">{text('bounds')}</p>
        <div className="sm:col-span-3"><FormField name="confirm_export" label={text('consent')} type="checkbox" required disabled={blocked} error={errors.confirm_export?.[0]} /></div>
        <div className="sm:col-span-3"><Button type="submit" disabled={blocked}>{text(feed ? 'rotate' : 'create')}</Button></div>
      </form>
      {secret && <div className="grid min-w-0 gap-2 rounded-xl border border-cyan-400/30 p-3">
        <p className="text-sm text-cyan-200">{text('once')}</p>
        <label className="grid min-w-0 gap-2">{text('url')}<textarea readOnly value={secret} rows={4} className="w-full min-w-0 resize-y rounded-lg bg-slate-950 p-3 text-sm [overflow-wrap:anywhere]" spellCheck={false} autoComplete="off" /></label>
        <Button disabled={blocked} onClick={() => { void (async () => {
          try { await navigator.clipboard.writeText(secret); notify({ type: 'success', message: text('copied') }) }
          catch { notify({ type: 'warning', message: text('copyFailed') }) }
        })() }}>{text('copy')}</Button>
      </div>}
      {feed?.status === 'active' && <form aria-label={text('revoke')} className="grid gap-3" onSubmit={(event) => {
        const values = new FormData(event.currentTarget)
        mutation.propose(event, text('revoke'), path, { version: feed.version, confirm_revocation: values.has('confirm_revocation') }, true)
      }}>
        <FormField name="confirm_revocation" label={text('revokeConsent')} type="checkbox" required disabled={blocked} error={errors.confirm_revocation?.[0]} />
        <Button type="submit" disabled={blocked}>{text('revoke')}</Button>
      </form>}
    </>}
  </div>
}
