import { useEffect, useState } from 'react'
import type { ApiSuccessResponse } from '../../api/contracts'
import { tenancyRequest, type ActiveOrganization } from '../../api/tenancy'
import { isAbortError } from '../../api/errors'
import { financialText as text, type ContractFinancialTerms, type FinancialValues } from '../../api/contractGovernance'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { Alert } from '../feedback/Alert'
import { SelectField } from '../people/peopleForms'
import { apiError, shouldReloadContext } from '../people/peopleFormValues'
import { usePeopleMutation } from '../people/usePeopleMutation'

type Props = { active: ActiveOrganization; path: string; contractVersion: string; editable: boolean; disabled: boolean; onInvalid: () => void; onChanged: () => void }
export function ContractFinancialPanel({ active, path, contractVersion, editable, disabled, onInvalid, onChanged }: Props) {
  const permissions = active.context?.permissions ?? [], allowed = permissions.includes('administrative.read') && permissions.includes('financial.read')
  const writable = editable && permissions.includes('administrative.write') && permissions.includes('financial.write')
  const [open, setOpen] = useState(false), [loaded, setLoaded] = useState(false), [loading, setLoading] = useState(false)
  const [terms, setTerms] = useState<ContractFinancialTerms | null>(null), [error, setError] = useState<ReturnType<typeof apiError> | null>(null)
  const revision = active.revision ?? undefined
  const mutation = usePeopleMutation(active, () => { setLoaded(false); onChanged() }, onInvalid)
  useEffect(() => {
    if (!allowed || !open) return
    const controller = new AbortController()
    const load = async () => {
      setLoading(true); setLoaded(false); setTerms(null); setError(null)
      try {
        const result = await tenancyRequest<ApiSuccessResponse<ContractFinancialTerms | null>>(path, { revision, signal: controller.signal })
        if (!controller.signal.aborted) { setTerms(result.data); setLoaded(true) }
      } catch (failure) {
        if (controller.signal.aborted || isAbortError(failure)) return
        const next = apiError(failure); setError(next); if (shouldReloadContext(next)) onInvalid()
      } finally { if (!controller.signal.aborted) setLoading(false) }
    }
    void load(); return () => controller.abort()
  }, [allowed, open, path, revision, contractVersion, onInvalid])
  if (!allowed) return null
  const blocked = disabled || loading || !loaded || mutation.blocked
  const errors = mutation.errors(path)
  return <section aria-label={text('title')} className="grid min-w-0 gap-3 rounded-xl border border-amber-400/30 p-3">
    <Button disabled={disabled || mutation.blocked} onClick={() => setOpen((value) => !value)}>{open ? text('close') : text('title')}</Button>
    {open && <>
      <p className="text-sm text-slate-400 [overflow-wrap:anywhere]">{text('hint')}</p>
      {loading && <p role="status">{text('loading')}</p>}
      {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
      {mutation.feedback}
      {loaded && !terms && <p>{text('empty')}</p>}
      {loaded && terms && <dl className="grid min-w-0 gap-2">{Object.entries(terms.terms).map(([field, value]) => <div key={field} className="min-w-0 [overflow-wrap:anywhere]">
        <dt className="font-semibold">{text(field)}</dt><dd className="whitespace-pre-wrap">{field === 'remuneration_period' && value ? text(value) : value ?? '—'}</dd>
      </div>)}</dl>}
      {loaded && writable && <form key={terms?.version ?? 'new'} noValidate aria-label={text('save')} className="grid min-w-0 gap-3" onSubmit={(event) => {
        const data = new FormData(event.currentTarget)
        const values = Object.fromEntries(['currency', 'remuneration', 'remuneration_period', 'bonus', 'prize_share_percent', 'payment_terms'].map((field) => [field, String(data.get('terms.' + field) ?? '') || null])) as FinancialValues
        mutation.propose(event, text('save'), path, { contract_version: contractVersion, expected_version: terms?.version ?? null, terms: values }, true)
      }}>
        <FormField name="terms.currency" label={text('currency')} defaultValue={terms?.terms.currency ?? 'EUR'} required maxLength={3} disabled={blocked} error={errors['terms.currency']?.[0]} />
        {(['remuneration', 'bonus', 'prize_share_percent'] as const).map((field) => <FormField key={field} name={'terms.' + field} label={text(field)} inputMode="decimal" maxLength={15}
          defaultValue={terms?.terms[field] ?? ''} disabled={blocked} error={errors['terms.' + field]?.[0]} hint={text('decimalHint')} />)}
        <SelectField name="terms.remuneration_period" title={text('remuneration_period')} defaultValue={terms?.terms.remuneration_period ?? ''} disabled={blocked} error={errors['terms.remuneration_period']?.[0]}>
          <option value="">{text('none')}</option>{(['once', 'monthly', 'yearly'] as const).map((period) => <option key={period} value={period}>{text(period)}</option>)}
        </SelectField>
        <FormField name="terms.payment_terms" label={text('payment_terms')} defaultValue={terms?.terms.payment_terms ?? ''} maxLength={2000} disabled={blocked} error={errors['terms.payment_terms']?.[0]} />
        {errors.terms && <p role="alert">{errors.terms[0]}</p>}
        <Button type="submit" disabled={blocked}>{text('save')}</Button>
      </form>}
      {loaded && !writable && <p>{text('readOnly')}</p>}
    </>}
  </section>
}
