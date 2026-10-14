import { useCallback, useEffect, useId, useState, type FormEvent } from 'react'
import { tenancyRequest, type ActiveOrganization, type Page } from '../../api/tenancy'
import { isAbortError } from '../../api/errors'
import type { ContractRecord } from '../../api/contractRecords'
import { documentsPath, documentText as text, signedContractStates, type ContractDocument, type ContractDocumentVersion } from '../../api/contractDocuments'
import { DocumentSecurityControls } from './DocumentSecurityControls'
import { ContractFinancialPanel } from './ContractFinancialPanel'
import { AmendmentApprovalPanel } from './AmendmentApprovalPanel'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { SelectField } from '../people/peopleForms'
import { apiError, shouldReloadContext } from '../people/peopleFormValues'
import { usePeopleMutation } from '../people/usePeopleMutation'

type Props = { active: ActiveOrganization; record: ContractRecord; disabled: boolean; onInvalid: () => void; onChanged: () => void }
export function ContractDocumentsPanel({ active, record, disabled, onInvalid, onChanged }: Props) {
  const org = active.context?.organization_id, revision = active.revision ?? undefined
  const allowed = (active.context?.permissions.includes('administrative.read') ?? false)
    && (!record.financial_documents_restricted || (active.context?.permissions.includes('financial.read') ?? false))
  const administrativeWrite = allowed && (active.context?.permissions.includes('administrative.write') ?? false)
  const manage = administrativeWrite && (!record.financial_documents_restricted || (active.context?.permissions.includes('financial.write') ?? false))
  const validate = administrativeWrite && (active.context?.permissions.includes('administrative.validate') ?? false)
  const [open, setOpen] = useState(false), [page, setPage] = useState(1), [versionPage, setVersionPage] = useState(1)
  const [list, setList] = useState<Page<ContractDocument> | null>(null), [versions, setVersions] = useState<Page<ContractDocumentVersion> | null>(null)
  const [selected, setSelected] = useState<string | null>(null), [original, setOriginal] = useState<ContractDocument | null>(null)
  const [refresh, setRefresh] = useState(0), [loading, setLoading] = useState(false), [error, setError] = useState<ReturnType<typeof apiError> | null>(null)
  const [creating, setCreating] = useState(false)
  const invalidate = useCallback(() => { setSelected(null); setVersions(null); setCreating(false); setRefresh((value) => value + 1); onInvalid() }, [onInvalid])
  const mutation = usePeopleMutation(active, () => { setCreating(false); setRefresh((value) => value + 1); onChanged() }, invalidate)
  useEffect(() => {
    if (!org || !allowed || !open) return
    const controller = new AbortController()
    const load = async () => {
      setLoading(true); setList(null); setVersions(null); setError(null)
      try {
        const [next, history] = await Promise.all([
          tenancyRequest<Page<ContractDocument>>(documentsPath(org, record.id) + '?per_page=10&page=' + page, { revision, signal: controller.signal }),
          selected ? tenancyRequest<Page<ContractDocumentVersion>>(documentsPath(org, record.id, selected) + '/versions?per_page=10&page=' + versionPage, { revision, signal: controller.signal }) : Promise.resolve(null),
        ])
        if (controller.signal.aborted) return
        setList(next); setVersions(history)
        const root = next.data.find((item) => item.kind === 'original')
        if (root) setOriginal(root)
      } catch (failure) {
        if (controller.signal.aborted || isAbortError(failure)) return
        const next = apiError(failure); setError(next); if (shouldReloadContext(next)) invalidate()
      } finally { if (!controller.signal.aborted) setLoading(false) }
    }
    void load(); return () => controller.abort()
  }, [org, record.id, record.version, revision, allowed, open, page, selected, versionPage, refresh, invalidate])
  if (!org || !allowed) return null
  const path = documentsPath(org, record.id), document = list?.data.find((item) => item.id === selected)
  const blocked = disabled || loading || mutation.blocked, writable = administrativeWrite && record.status !== 'archived'
  const canAmend = !!original?.signed_version && signedContractStates.includes(record.status)
  const canCreate = writable && manage && (!original || canAmend)
  function upload(event: FormEvent<HTMLFormElement>) {
    if (!document) return
    const body = new FormData(event.currentTarget), input = event.currentTarget.elements.namedItem('file')
    if (input instanceof HTMLInputElement && input.files?.[0]) body.set('file', input.files[0])
    body.set('contract_version', record.version)
    mutation.propose(event, text('upload'), documentsPath(org!, record.id, document.id) + '/versions', body)
  }
  const draftAllowed = manage && (document?.kind === 'amendment' || ['draft', 'in_review'].includes(record.status))
  const actor = active.context?.actor_user_id
  const signatureActors = document?.kind === 'amendment'
    ? [document.created_by, document.latest_prepared_by, document.financial_prepared_by, document.approval?.approved_by]
    : [record.prepared_by, record.approved_by]
  const signedAllowed = validate && signedContractStates.includes(record.status)
    && (document?.kind !== 'amendment' || !!document.approval)
    && (!record.signature_separation_required || !signatureActors.includes(actor))
  return <section aria-label={text('title')} className="grid min-w-0 gap-3 rounded-xl border border-slate-700 p-3">
    <Button disabled={disabled || mutation.blocked} onClick={() => setOpen((value) => !value)}>{open ? text('close') : text('title')}</Button>
    {open && <>
      <p className="text-sm text-slate-400 [overflow-wrap:anywhere]">{text('hint')}</p>
      <Button disabled={disabled || loading || mutation.blocked} onClick={() => setRefresh((value) => value + 1)}>{text('refresh')}</Button>
      {loading && <p role="status">{text('loading')}</p>}
      {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
      {mutation.feedback}
      {list?.data.length === 0 && <p>{text('empty')}</p>}
      {list && <ul className="grid min-w-0 gap-3">{list.data.map((item) => <li key={item.id} className="grid min-w-0 gap-2 rounded-xl border border-slate-700 p-3">
        <h4 className="font-bold [overflow-wrap:anywhere]">{item.title}</h4>
        <p className="[overflow-wrap:anywhere]">{item.reference} · {text(item.kind)} · {item.versions_count} {text('versions')}</p>
        {item.original_document_id && <p className="[overflow-wrap:anywhere]">{text('origin')} : {original?.id === item.original_document_id ? original.reference : item.original_document_id}</p>}
        {item.effective_on && <p>{text('effective')} : {item.effective_on}</p>}
        {item.signed_version && <p className="[overflow-wrap:anywhere]">{text('signedVersion')} : {item.signed_version.number} · {item.signed_version.signed_on}</p>}
        <Button disabled={blocked} onClick={() => { setSelected(item.id); setVersionPage(1); setCreating(false) }}>{text('history')} · {item.reference}</Button>
      </li>)}</ul>}
      {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={blocked} onPageChange={(next) => { setPage(next); setSelected(null); setVersions(null); setCreating(false) }} />}
      {canCreate && <Button disabled={blocked} onClick={() => setCreating((value) => !value)}>{text('create')}</Button>}
      {creating && canCreate && <DocumentForm key={record.version} amendment={canAmend} original={original} disabled={blocked} errors={mutation.errors(path)}
        onSubmit={(event, body) => mutation.propose(event, text('create'), path, { ...body, contract_version: record.version })} />}
      {document && <section aria-label={text('history')} className="grid min-w-0 gap-3">
        <h4 className="font-bold [overflow-wrap:anywhere]">{text('history')} · {document.reference}</h4>
        {document.kind === 'amendment' && <>
          <ContractFinancialPanel key={'finance:' + document.id + ':' + record.version} active={active} path={documentsPath(org, record.id, document.id) + '/financial-terms'}
            contractVersion={record.version} editable={!document.signed_version && record.status !== 'archived'} disabled={blocked} onInvalid={invalidate} onChanged={onChanged} />
          <AmendmentApprovalPanel key={'approval:' + document.id + ':' + record.version} active={active} document={document} path={documentsPath(org, record.id, document.id) + '/approvals'}
            contractVersion={record.version} financialRequired={!!record.financial_documents_restricted} disabled={blocked || record.status === 'archived'} onInvalid={invalidate} onChanged={onChanged} />
        </>}
        {versions?.data.length === 0 && <p>{text('noVersions')}</p>}
        {versions?.data.map((version) => <article aria-label={text('version') + ' ' + version.number} key={version.id} className="grid min-w-0 gap-2 rounded-xl border border-slate-700 p-3">
          <p className="[overflow-wrap:anywhere]">{text('version')} {version.number} · {version.filename} · {text(version.purpose)}</p>
          {document.signed_version?.id === version.id && <p className="font-semibold">{text('signedVersion')}</p>}
          <p className="text-sm [overflow-wrap:anywhere]">{text('hash')} : {version.sha256}</p>
          <p className="text-sm [overflow-wrap:anywhere]">{version.bytes} {text('bytes')}</p>
          <DocumentSecurityControls key={revision ?? ''} active={active} path={documentsPath(org, record.id, document.id) + '/versions/' + version.id} version={version} manage={manage} disabled={blocked}
            onChanged={() => { setRefresh((value) => value + 1); onChanged() }} onInvalid={invalidate} />
        </article>)}
        {versions && <Pagination currentPage={versions.meta.current_page} totalPages={versions.meta.last_page} disabled={blocked} onPageChange={setVersionPage} />}
        {document.signed_version && <p className="[overflow-wrap:anywhere]">{text('frozen')}</p>}
        {writable && !document.signed_version && (draftAllowed || signedAllowed) && <form noValidate key={'upload:' + record.version} aria-label={text('upload')} onSubmit={upload} className="grid min-w-0 gap-3">
          <FormField type="file" name="file" accept=".pdf,application/pdf" label={text('file')} required disabled={blocked} error={mutation.errors(documentsPath(org, record.id, document.id) + '/versions').file?.[0]} />
          <SelectField name="purpose" title={text('purpose')} disabled={blocked} defaultValue={draftAllowed ? 'draft' : 'signed_copy'}>
            {draftAllowed && <option value="draft">{text('draft')}</option>}{signedAllowed && <option value="signed_copy">{text('signed_copy')}</option>}
          </SelectField><Button type="submit" disabled={blocked}>{text('upload')}</Button>
        </form>}
        {signedAllowed && writable && !document.signed_version && versions?.data.some((item) => item.purpose === 'signed_copy') && <SignatureForm key={'signature:' + record.version + ':' + versionPage}
          versions={versions.data.filter((item) => item.purpose === 'signed_copy')} signedOn={document.kind === 'original' ? record.signed_on : null} disabled={blocked}
          errors={mutation.errors(documentsPath(org, record.id, document.id) + '/signature')}
          onSubmit={(event, body) => mutation.propose(event, text('identifySignature'), documentsPath(org, record.id, document.id) + '/signature', { ...body, contract_version: record.version })} />}
      </section>}
    </>}
  </section>
}

function DocumentForm({ amendment, original, disabled, errors, onSubmit }: {
  amendment: boolean; original: ContractDocument | null; disabled: boolean; errors: Record<string, string[]>
  onSubmit: (event: FormEvent<HTMLFormElement>, body: Record<string, unknown>) => void
}) {
  return <form noValidate aria-label={text('create')} className="grid min-w-0 gap-3" onSubmit={(event) => {
    const data = new FormData(event.currentTarget)
    onSubmit(event, { reference: data.get('reference'), title: data.get('title'), kind: amendment ? 'amendment' : 'original',
      ...(amendment ? { original_document_id: original!.id, effective_on: String(data.get('effective_on') ?? '') || null } : {}) })
  }}>
    <p>{text(amendment ? 'amendment' : 'original')}{amendment && ' · ' + original?.reference}</p>
    <FormField name="reference" label={text('reference')} required maxLength={80} disabled={disabled} error={errors.reference?.[0]} />
    <FormField name="title" label={text('documentTitle')} required maxLength={160} disabled={disabled} error={errors.title?.[0]} />
    {amendment && <FormField name="effective_on" label={text('effective')} type="date" disabled={disabled} error={errors.effective_on?.[0]} />}
    <Button type="submit" disabled={disabled}>{text('create')}</Button>
  </form>
}

function SignatureForm({ versions, signedOn, disabled, errors, onSubmit }: {
  versions: ContractDocumentVersion[]; signedOn: string | null; disabled: boolean; errors: Record<string, string[]>
  onSubmit: (event: FormEvent<HTMLFormElement>, body: Record<string, unknown>) => void
}) {
  const errorId = useId()
  return <form noValidate aria-label={text('identifySignature')} className="grid min-w-0 gap-3" onSubmit={(event) => {
    const data = new FormData(event.currentTarget)
    onSubmit(event, { document_version_id: data.get('document_version_id'), signed_on: data.get('signed_on'), confirm_signed_copy: data.has('confirm_signed_copy') })
  }}>
    <SelectField name="document_version_id" title={text('signedVersion')} disabled={disabled} error={errors.document_version_id?.[0]}>
      {versions.map((version) => <option value={version.id} key={version.id}>{text('version')} {version.number} · {version.filename}</option>)}
    </SelectField>
    <FormField name="signed_on" label={text('signedOn')} type="date" defaultValue={signedOn ?? ''} required disabled={disabled} error={errors.signed_on?.[0]} />
    <label className="flex min-w-0 items-start gap-2"><input type="checkbox" name="confirm_signed_copy" disabled={disabled} className="mt-1 shrink-0" aria-invalid={!!errors.confirm_signed_copy}
      aria-describedby={errors.confirm_signed_copy ? errorId : undefined} /><span className="[overflow-wrap:anywhere]">{text('confirm')}</span></label>
    {errors.confirm_signed_copy && <p role="alert" id={errorId}>{errors.confirm_signed_copy[0]}</p>}
    <Button type="submit" disabled={disabled}>{text('identifySignature')}</Button>
  </form>
}
