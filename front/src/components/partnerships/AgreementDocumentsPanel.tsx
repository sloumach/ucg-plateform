import { useState } from 'react'
import type { ActiveOrganization } from '../../api/tenancy'
import type { ContractRecord } from '../../api/contractRecords'
import { contractsPath } from '../../api/contractRecords'
import { agreementText as text, type SponsorshipAgreement } from '../../api/sponsorshipAgreements'
import { Button } from '../Button'
import { Alert } from '../feedback/Alert'
import { ContractDocumentsPanel } from '../contracts/ContractDocumentsPanel'
import { useAgreementRead } from './useAgreementRead'

export function AgreementDocumentsPanel({ active, record, disabled, onChanged, onInvalid }: {
  active: ActiveOrganization; record: SponsorshipAgreement; disabled: boolean; onChanged: () => void; onInvalid: () => void
}) {
  const [open, setOpen] = useState(false)
  const allowed = !!record.contract_id && active.context!.permissions.includes('administrative.read')
  const { data, loading, error } = useAgreementRead<ContractRecord>(contractsPath(active.context!.organization_id, record.contract_id ?? ''), active, open && allowed, onInvalid)
  if (!allowed) return null
  return <section className="grid min-w-0 gap-3" aria-label={text('documents')}>
    <Button disabled={disabled} onClick={() => setOpen(!open)}>{text(open ? 'closeDocuments' : 'documents')}</Button>
    {open && <>
      <p className="text-sm text-slate-400">{text('documentHint')}</p>
      {loading && <p role="status">{text('loading')}</p>}
      {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
      {data && <ContractDocumentsPanel {...{ active, disabled, onChanged, onInvalid }} record={data} />}
    </>}
  </section>
}
