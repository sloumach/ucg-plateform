import { useState } from 'react'
import type { ActiveOrganization } from '../../api/tenancy'
import { agreementText as text, type SponsorshipAgreement, type SponsorshipFinancial } from '../../api/sponsorshipAgreements'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { Alert } from '../feedback/Alert'
import { SelectField } from '../people/peopleForms'
import { usePeopleMutation } from '../people/usePeopleMutation'
import { SponsorshipTextArea } from './SponsorshipFields'
import { useAgreementRead } from './useAgreementRead'

type Props = { active: ActiveOrganization; record: SponsorshipAgreement; path: string; disabled: boolean; readOnly?: boolean; onChanged: () => void; onInvalid: () => void }
export function AgreementFinancialPanel({ active, record, path, disabled, readOnly = false, onChanged, onInvalid }: Props) {
  const [open, setOpen] = useState(false)
  const permissions = active.context!.permissions, allowed = permissions.includes('financial.read')
  const { data, loading, error } = useAgreementRead<SponsorshipFinancial>(path, active, open && allowed, onInvalid)
  const writable = !readOnly && permissions.includes('partnerships.write') && permissions.includes('financial.write') && ['draft', 'approved'].includes(record.status)
  if (!allowed) return null
  return <section aria-label={text('financial')} className="grid min-w-0 gap-3 rounded-xl border border-amber-400/30 p-3">
    <Button disabled={disabled} onClick={() => setOpen(!open)}>{text(open ? 'closeFinancial' : 'financial')}</Button>
    {open && <>
      {loading && <p role="status">{text('loading')}</p>}
      {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
      {data && !data.terms && <p>{text('noFinancial')}</p>}
      {data?.terms && <dl className="grid min-w-0 gap-2">{Object.entries(data.terms).map(([field, value]) => <div key={field} className="min-w-0 [overflow-wrap:anywhere]">
        <dt className="font-semibold">{text(field)}</dt><dd className="whitespace-pre-wrap">{field === 'kind' ? text('kind.' + value) : value ?? '—'}</dd>
      </div>)}</dl>}
      {data && writable && <FinancialForm key={data.version ?? 'new'} {...{ active, record, path, disabled, onChanged, onInvalid }} financial={data} />}
    </>}
  </section>
}

function FinancialForm({ active, record, path, financial, disabled, onChanged, onInvalid }: Props & { financial: SponsorshipFinancial }) {
  const [kind, setKind] = useState(financial.terms?.kind ?? 'cash')
  const mutation = usePeopleMutation(active, onChanged, onInvalid), errors = mutation.errors(path)
  const blocked = disabled || mutation.blocked, cash = kind !== 'in_kind', inKind = kind !== 'cash'
  return <>
    {mutation.feedback}
    <form noValidate aria-label={text('saveFinancial')} className="grid min-w-0 gap-3" onSubmit={(event) => {
      const data = new FormData(event.currentTarget)
      mutation.propose(event, text('saveFinancial'), path, { agreement_version: record.version, expected_version: financial.version,
        terms: { kind, currency: cash ? String(data.get('currency') ?? '') : null, amount: cash ? String(data.get('amount') ?? '') : null,
          in_kind: inKind ? String(data.get('in_kind') ?? '') || null : null, payment_terms: String(data.get('payment_terms') ?? '') || null } }, true)
    }}>
      <SelectField name="kind" title={text('kind')} value={kind} onChange={(value) => setKind(value as typeof kind)} disabled={blocked} error={errors['terms.kind']?.[0]}>
        {(['cash', 'in_kind', 'mixed'] as const).map((value) => <option key={value} value={value}>{text('kind.' + value)}</option>)}
      </SelectField>
      {cash && <>
        <FormField name="currency" label={text('currency')} defaultValue={financial.terms?.currency ?? 'EUR'} required maxLength={3} disabled={blocked} error={errors['terms.currency']?.[0]} />
        <FormField name="amount" label={text('amount')} defaultValue={financial.terms?.amount ?? ''} inputMode="decimal" required maxLength={15} disabled={blocked} error={errors['terms.amount']?.[0]} hint={text('decimal')} />
      </>}
      {inKind && <SponsorshipTextArea name="in_kind" title={text('in_kind')} value={financial.terms?.in_kind ?? ''} required disabled={blocked} error={errors['terms.in_kind']?.[0]} />}
      <SponsorshipTextArea name="payment_terms" title={text('payment_terms')} value={financial.terms?.payment_terms ?? ''} disabled={blocked} error={errors['terms.payment_terms']?.[0]} />
      {errors.terms && <p role="alert">{errors.terms[0]}</p>}
      <Button type="submit" disabled={blocked}>{text('saveFinancial')}</Button>
    </form>
  </>
}
