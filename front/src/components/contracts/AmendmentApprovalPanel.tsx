import { useEffect, useState } from 'react'
import { tenancyRequest, type ActiveOrganization, type Page } from '../../api/tenancy'
import type { ContractDocument } from '../../api/contractDocuments'
import { financialText as text, type AmendmentApproval } from '../../api/contractGovernance'
import { isAbortError } from '../../api/errors'
import { Button } from '../Button'
import { Alert } from '../feedback/Alert'
import { Pagination } from '../data/Pagination'
import { apiError, shouldReloadContext } from '../people/peopleFormValues'
import { usePeopleMutation } from '../people/usePeopleMutation'

export function AmendmentApprovalPanel({ active, document, path, contractVersion, financialRequired, disabled, onChanged, onInvalid }: {
  active: ActiveOrganization; document: ContractDocument; path: string; contractVersion: string; financialRequired: boolean; disabled: boolean; onChanged: () => void; onInvalid: () => void
}) {
  const [open, setOpen] = useState(false), [page, setPage] = useState(1), [list, setList] = useState<Page<AmendmentApproval> | null>(null)
  const [loading, setLoading] = useState(false), [error, setError] = useState<ReturnType<typeof apiError> | null>(null)
  const permissions = active.context?.permissions ?? [], actor = active.context?.actor_user_id
  const canApprove = permissions.includes('administrative.write') && permissions.includes('administrative.validate')
    && (!financialRequired || (permissions.includes('financial.read') && permissions.includes('financial.validate')))
    && ![document.created_by, document.latest_prepared_by, document.financial_prepared_by].includes(actor)
  const mutation = usePeopleMutation(active, onChanged, onInvalid)
  const revision = active.revision ?? undefined
  useEffect(() => {
    if (!open) return
    const controller = new AbortController()
    const load = async () => {
      setLoading(true); setList(null); setError(null)
      try {
        const result = await tenancyRequest<Page<AmendmentApproval>>(path + '?per_page=10&page=' + page, { revision, signal: controller.signal })
        if (!controller.signal.aborted) setList(result)
      } catch (failure) {
        if (controller.signal.aborted || isAbortError(failure)) return
        const next = apiError(failure); setError(next); if (shouldReloadContext(next)) onInvalid()
      } finally { if (!controller.signal.aborted) setLoading(false) }
    }
    void load(); return () => controller.abort()
  }, [open, path, page, revision, contractVersion, onInvalid])
  const blocked = disabled || mutation.blocked
  return <section aria-label={text('approvalTitle')} className="grid min-w-0 gap-3">
    <p className="[overflow-wrap:anywhere]">{document.approval ? text('approved') + ' · ' + document.approval.approved_by : text(document.signed_version ? 'legacySignature' : 'approvalRequired')}</p>
    {mutation.feedback}
    {!document.signed_version && !document.approval && document.latest_draft_id && canApprove && <form noValidate aria-label={text('approve')} className="grid min-w-0 gap-3" onSubmit={(event) =>
      mutation.propose(event, text('approve'), path, { contract_version: contractVersion, document_version_id: document.latest_draft_id, confirm_approval: new FormData(event.currentTarget).has('confirm_approval') })}>
      <label className="flex min-w-0 items-start gap-2"><input type="checkbox" name="confirm_approval" disabled={blocked} /><span className="[overflow-wrap:anywhere]">{text('confirmApproval')}</span></label>
      {mutation.errors(path).confirm_approval && <p role="alert">{mutation.errors(path).confirm_approval[0]}</p>}
      <Button type="submit" disabled={blocked}>{text('approve')}</Button>
    </form>}
    <Button disabled={blocked} onClick={() => setOpen((value) => !value)}>{text('history')}</Button>
    {open && <>{loading && <p role="status">{text('loading')}</p>}{error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
      {list?.data.map((item) => <p key={item.id} className="[overflow-wrap:anywhere]">{text('approved')} · {item.approved_by} · {item.approved_at} · {item.document_version_id}</p>)}
      {list?.data.length === 0 && <p>{text('noApprovals')}</p>}
      {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={loading || blocked} onPageChange={setPage} />}
    </>}
  </section>
}
