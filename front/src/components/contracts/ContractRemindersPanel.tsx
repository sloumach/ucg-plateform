import { useEffect, useState } from 'react'
import type { ApiSuccessResponse } from '../../api/contracts'
import { tenancyRequest, type ActiveOrganization, type Page } from '../../api/tenancy'
import { isAbortError } from '../../api/errors'
import { contractsPath } from '../../api/contractRecords'
import { reminderText as text, type ReminderPlan, type ReminderDelivery } from '../../api/contractReminders'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { apiError, shouldReloadContext } from '../people/peopleFormValues'
import { SelectField } from '../people/peopleForms'
import { usePeopleMutation } from '../people/usePeopleMutation'

type Props = { active: ActiveOrganization; path: string; contractVersion: string; endDate: string | null; restricted: boolean; editable: boolean; disabled: boolean; onInvalid: () => void; onChanged: () => void }
export function ContractRemindersPanel({ active, path, contractVersion, endDate, restricted, editable, disabled, onInvalid, onChanged }: Props) {
  const permissions = active.context?.permissions ?? []
  const allowed = permissions.includes('administrative.read') && (!restricted || permissions.includes('financial.read'))
  const writable = editable && permissions.includes('administrative.write')
  const [open, setOpen] = useState(false), [loading, setLoading] = useState(false), [loaded, setLoaded] = useState(false)
  const [plan, setPlan] = useState<ReminderPlan | null>(null), [history, setHistory] = useState<Page<ReminderDelivery> | null>(null)
  const [ready, setReady] = useState({ scheduler_enabled: true, email_ready: true })
  const [page, setPage] = useState(1), [refresh, setRefresh] = useState(0), [error, setError] = useState<ReturnType<typeof apiError> | null>(null)
  const revision = active.revision ?? undefined
  const mutation = usePeopleMutation(active, () => { setRefresh((value) => value + 1); onChanged() }, onInvalid)
  useEffect(() => {
    if (!allowed || !open) return
    const controller = new AbortController()
    const load = async () => {
      setLoading(true); setLoaded(false); setPlan(null); setHistory(null); setError(null)
      try {
        const [settings, deliveries] = await Promise.all([
          tenancyRequest<ApiSuccessResponse<ReminderPlan | null> & { meta: { scheduler_enabled?: boolean; email_ready?: boolean } }>(path, { revision, signal: controller.signal }),
          tenancyRequest<Page<ReminderDelivery>>(path + '/history?per_page=10&page=' + page, { revision, signal: controller.signal }),
        ])
        if (!controller.signal.aborted) {
          setPlan(settings.data); setHistory(deliveries); setLoaded(true)
          setReady({ scheduler_enabled: settings.meta?.scheduler_enabled ?? true, email_ready: settings.meta?.email_ready ?? true })
        }
      } catch (failure) {
        if (controller.signal.aborted || isAbortError(failure)) return
        const next = apiError(failure); setError(next); if (shouldReloadContext(next)) onInvalid()
      } finally { if (!controller.signal.aborted) setLoading(false) }
    }
    void load(); return () => controller.abort()
  }, [allowed, open, path, revision, contractVersion, page, refresh, onInvalid])
  if (!allowed) return null
  const blocked = disabled || loading || !loaded || mutation.blocked
  const errors = mutation.errors(path)
  return <section aria-label={text('title')} className="grid min-w-0 gap-3 rounded-xl border border-slate-700 p-3">
    <Button disabled={disabled || mutation.blocked} onClick={() => setOpen((value) => !value)}>{open ? text('close') : text('title')}</Button>
    {open && <>
      <p className="text-sm text-slate-400 [overflow-wrap:anywhere]">{text('hint')}</p>
      <p>{text('deadline')} : {endDate ?? '—'}</p>
      {loading && <p role="status">{text('loading')}</p>}
      {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
      {mutation.feedback}
      {loaded && !ready.scheduler_enabled && <p role="status">{text('schedulerPending')}</p>}
      {loaded && !ready.email_ready && <p role="status">{text('emailPending')}</p>}
      {loaded && writable && <form key={plan?.version ?? 'new'} noValidate aria-label={text('save')} className="grid min-w-0 gap-3" onSubmit={(event) => {
        const values = new FormData(event.currentTarget)
        mutation.propose(event, text('save'), path, { contract_version: contractVersion, expected_version: plan?.version ?? null,
          enabled: values.has('enabled'), notice_on: String(values.get('notice_on') ?? '') || null, renewal_on: String(values.get('renewal_on') ?? '') || null,
          offsets: String(values.get('offsets') ?? '').split(',').map((value) => value.trim()).filter(Boolean).map(Number), channels: values.getAll('channels') }, true)
      }}>
        <label><input type="checkbox" name="enabled" defaultChecked={plan?.enabled ?? false} disabled={blocked} /> {text('enabled')}</label>
        <FormField name="notice_on" label={text('notice')} type="date" defaultValue={plan?.notice_on ?? ''} disabled={blocked} error={errors.notice_on?.[0]} />
        <FormField name="renewal_on" label={text('renewal')} type="date" defaultValue={plan?.renewal_on ?? ''} disabled={blocked} error={errors.renewal_on?.[0]} />
        <FormField name="offsets" label={text('offsets')} hint={text('offsetsHint')} defaultValue={plan?.offsets.join(', ') ?? '90, 30, 7'} maxLength={60} disabled={blocked} error={errors.offsets?.[0]} />
        <fieldset disabled={blocked} className="grid gap-2"><legend>{text('channels')}</legend>
          {(['in_app', 'email'] as const).map((channel) => <label key={channel}><input type="checkbox" name="channels" value={channel} defaultChecked={plan?.channels.includes(channel) ?? true} /> {text(channel)}</label>)}
        </fieldset>
        {Object.entries(errors).filter(([key]) => key.startsWith('offsets.') || key.startsWith('channels')).map(([key, messages]) => <p key={key} role="alert">{messages[0]}</p>)}
        <Button type="submit" disabled={blocked}>{text('save')}</Button>
      </form>}
      {loaded && !writable && <p>{text('readOnly')}</p>}
      {loaded && !writable && plan && <p>{plan.enabled ? text('enabled') : text('disabled')} · {plan.offsets.join(', ')} {text('days')} · {plan.channels.map(text).join(', ')}
        · {text('notice')} : {plan.notice_on ?? '—'} · {text('renewal')} : {plan.renewal_on ?? '—'}</p>}
      {loaded && <Button disabled={blocked} onClick={() => setRefresh((value) => value + 1)}>{text('refresh')}</Button>}
      {history && <section aria-label={text('history')} className="grid min-w-0 gap-3">
        <h4 className="font-bold">{text('history')}</h4>
        {history.data.length === 0 && <p>{text('empty')}</p>}
        {history.data.map((delivery) => <article key={delivery.id} aria-label={text(delivery.kind) + ' ' + delivery.due_on} className="grid min-w-0 gap-2 rounded-lg border border-slate-700 p-3 [overflow-wrap:anywhere]">
          <DeliverySummary delivery={delivery} />
          {delivery.email_status === 'uncertain' && <p>{text('uncertainHint')}</p>}
          {writable && delivery.email_status === 'uncertain' && delivery.attempts < 20 && <form noValidate aria-label={text('resolve')} className="grid min-w-0 gap-2" onSubmit={(event) => {
            const values = new FormData(event.currentTarget)
            mutation.propose(event, text('resolve'), path + '/' + delivery.id + '/resolution',
              { expected_version: delivery.version, action: values.get('action'), ...(values.has('confirm_possible_duplicate') ? { confirm_possible_duplicate: true } : {}) })
          }}>
            <SelectField name="action" title={text('decision')} disabled={blocked}><option value="confirm_sent">{text('confirmSent')}</option><option value="retry">{text('retry')}</option></SelectField>
            <label><input type="checkbox" name="confirm_possible_duplicate" disabled={blocked} /> {text('duplicateRisk')}</label>
            <Button type="submit" disabled={blocked}>{text('resolve')}</Button>
          </form>}
        </article>)}
        <Pagination currentPage={history.meta.current_page} totalPages={history.meta.last_page} disabled={blocked} onPageChange={setPage} />
      </section>}
    </>}
  </section>
}

function DeliverySummary({ delivery }: { delivery: ReminderDelivery }) {
  return <>
    <p>{text(delivery.kind)} · {delivery.due_on} · {delivery.offset_days} {text('days')}</p>
    <p>{text('in_app')} : {text(delivery.in_app_status === 'sent' ? 'available' : delivery.in_app_status)} · {text('email')} : {text(delivery.email_status)}</p>
    {delivery.reason && <p>{text(delivery.reason)}</p>}
    <p>{text('attempts')} : {delivery.attempts}</p>
  </>
}

export function MyContractReminders({ active, disabled, onInvalid, onSelect }: { active: ActiveOrganization; disabled: boolean; onInvalid: () => void; onSelect: (id: string) => void }) {
  const org = active.context?.organization_id, revision = active.revision ?? undefined
  const allowed = active.context?.permissions.includes('administrative.read') ?? false
  const [open, setOpen] = useState(false), [page, setPage] = useState(1), [refresh, setRefresh] = useState(0)
  const [items, setItems] = useState<Page<ReminderDelivery> | null>(null), [loading, setLoading] = useState(false)
  const [error, setError] = useState<ReturnType<typeof apiError> | null>(null)
  useEffect(() => {
    if (!allowed || !org || !open) return
    const controller = new AbortController()
    const load = async () => {
      setLoading(true); setItems(null); setError(null)
      try {
        const result = await tenancyRequest<Page<ReminderDelivery>>(contractsPath(org) + '/reminders?per_page=10&page=' + page, { revision, signal: controller.signal })
        if (!controller.signal.aborted) setItems(result)
      } catch (failure) {
        if (controller.signal.aborted || isAbortError(failure)) return
        const next = apiError(failure); setError(next); if (shouldReloadContext(next)) onInvalid()
      } finally { if (!controller.signal.aborted) setLoading(false) }
    }
    void load()
    return () => controller.abort()
  }, [allowed, org, revision, open, page, refresh, onInvalid])
  if (!allowed || !org) return null
  const blocked = disabled || loading
  return <section aria-label={text('inbox')} className="grid min-w-0 gap-3">
    <Button disabled={disabled} onClick={() => setOpen((value) => !value)}>{open ? text('closeInbox') : text('inbox')}</Button>
    {open && <>
      <p className="text-sm text-slate-400">{text('inboxHint')}</p>
      {loading && <p role="status">{text('loading')}</p>}
      {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
      {items?.data.length === 0 && <p>{text('empty')}</p>}
      {items?.data.map((delivery) => <article key={delivery.id} className="grid min-w-0 gap-2 rounded-lg border border-slate-700 p-3 [overflow-wrap:anywhere]">
        <DeliverySummary delivery={delivery} /><Button disabled={blocked} onClick={() => onSelect(delivery.contract_id)}>{text('viewContract')}</Button>
      </article>)}
      <Button disabled={blocked} onClick={() => setRefresh((value) => value + 1)}>{text('refresh')}</Button>
      {items && <Pagination currentPage={items.meta.current_page} totalPages={items.meta.last_page} disabled={blocked} onPageChange={setPage} />}
    </>}
  </section>
}
