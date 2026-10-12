import { useEffect, useRef, useState, type FormEvent } from 'react'
import type { ActiveOrganization, Page } from '../../api/tenancy'
import { downloadPersonImportReport, getPersonImport, importFields, listPersonImports, personImportsPath, type PersonImport, type ImportRow } from '../../api/personImports'
import { isAbortError } from '../../api/errors'
import { t, type TranslationKey } from '../../i18n/fr'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { DataTable, type DataTableColumn } from '../data/DataTable'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { apiError, label, personStatuses, shouldReloadContext } from './peopleFormValues'
import { SelectField } from './peopleForms'
import { usePeopleMutation } from './usePeopleMutation'

const text = (key: string) => t(('people.import.' + key) as TranslationKey)
export function PersonImportsPanel({ active, disabled, onInvalid, onImported }: {
  active: ActiveOrganization; disabled: boolean; onInvalid: () => void; onImported: () => void
}) {
  const org = active.context?.organization_id
  const revision = active.revision ?? undefined
  const allowed = ['sports.read', 'sports.write', 'administrative.read', 'administrative.write'].every((right) => active.context?.permissions.includes(right))
  const [open, setOpen] = useState(false)
  const [listPage, setListPage] = useState(1)
  const [rowPage, setRowPage] = useState(1)
  const [list, setList] = useState<Page<PersonImport> | null>(null)
  const [run, setRun] = useState<PersonImport | null>(null)
  const [selected, setSelected] = useState<string | null>(null)
  const [refresh, setRefresh] = useState(0)
  const [loading, setLoading] = useState(false)
  const [downloadBusy, setDownloadBusy] = useState(false)
  const [error, setError] = useState<ReturnType<typeof apiError> | null>(null)
  const alive = useRef(true)
  const download = useRef<AbortController | null>(null)
  useEffect(() => { alive.current = true; return () => { alive.current = false; download.current?.abort() } }, [])
  const mutation = usePeopleMutation<PersonImport>(active, (result) => {
    setSelected(result.id); setRun(result); setRowPage(1); setError(null); setRefresh((value) => value + 1)
    if (['queued', 'processing', 'completed'].includes(result.status)) onImported()
  }, onInvalid)
  useEffect(() => {
    if (!org || !allowed || !open) return
    const controller = new AbortController()
    const load = async () => {
      setLoading(true); setList(null); setRun(null); setError(null)
      await Promise.all([
      listPersonImports(org, listPage, revision, controller.signal),
      selected ? getPersonImport(org, selected, rowPage, revision, controller.signal) : Promise.resolve(null),
    ]).then(([nextList, nextRun]) => {
      if (!controller.signal.aborted) { setList(nextList); setRun(nextRun?.data ?? null) }
    }).catch((failure: unknown) => {
      if (controller.signal.aborted || isAbortError(failure)) return
      const next = apiError(failure); setError(next)
      if (shouldReloadContext(next)) onInvalid()
    }).finally(() => { if (!controller.signal.aborted) setLoading(false) })
    }
    void load()
    return () => controller.abort()
  }, [org, revision, allowed, open, listPage, selected, rowPage, refresh, onInvalid])
  if (!org || !allowed) return null
  const blocked = disabled || loading || mutation.blocked || downloadBusy
  const previewErrors = run ? mutation.errors(personImportsPath(org, run.id, 'preview')) : {}
  function upload(event: FormEvent<HTMLFormElement>) {
    const body = new FormData(event.currentTarget)
    const input = event.currentTarget.elements.namedItem('file')
    if (input instanceof HTMLInputElement && input.files?.[0]) body.set('file', input.files[0])
    mutation.propose(event, text('upload'), personImportsPath(org!), body)
  }
  function preview(event: FormEvent<HTMLFormElement>) {
    if (!run) return
    const form = new FormData(event.currentTarget)
    const mapping = Object.fromEntries(importFields.flatMap((field) => form.get(field) === '' ? [] : [[field, Number(form.get(field))]]))
    mutation.propose(event, text('preview'), personImportsPath(org!, run.id, 'preview'), {
      version: run.version, mapping,
      defaults: { language: form.get('default_language'), timezone: form.get('default_timezone'), status: form.get('default_status') },
    })
  }
  function confirm(event: FormEvent<HTMLFormElement>) {
    if (!run) return
    const form = new FormData(event.currentTarget)
    mutation.propose(event, text('confirm'), personImportsPath(org!, run.id, 'confirm'), {
      version: run.version, confirm: form.has('confirm'), acknowledge_rejected: form.has('acknowledge_rejected'),
    })
  }
  async function report() {
    if (!run || !org || blocked) return
    const controller = new AbortController(); download.current = controller; setDownloadBusy(true)
    try {
      const blob = await downloadPersonImportReport(org, run.id, revision, controller.signal)
      if (!alive.current || controller.signal.aborted) return
      const url = URL.createObjectURL(blob); const link = document.createElement('a')
      link.href = url; link.download = 'people-import-report.csv'; link.click(); URL.revokeObjectURL(url)
    } catch (failure) {
      if (alive.current && !isAbortError(failure)) {
        const next = apiError(failure); setError(next); if (shouldReloadContext(next)) onInvalid()
      }
    } finally { if (alive.current) setDownloadBusy(false) }
  }
  const columns: DataTableColumn<ImportRow>[] = [
    { key: 'line', header: text('line'), render: (row) => row.line },
    { key: 'status', header: label('status'), render: (row) => text(row.status) },
    { key: 'values', header: text('values'), render: (row) => <dl className="grid min-w-0 gap-1">{Object.entries(row.values).map(([field, value]) => <div className="break-words [overflow-wrap:anywhere]" key={field}><dt className="font-semibold">{label(field)}</dt><dd>{value ?? label('none')}</dd></div>)}</dl> },
    { key: 'errors', header: text('errors'), render: (row) => row.errors.map((code) => <p key={code}>{code.startsWith('INVALID_') ? t('people.import.invalidField', { field: label(code.slice(8).toLowerCase()) }) : text(code)}</p>) },
  ]
  return <section className="grid min-w-0 gap-4 rounded-xl border border-slate-700 p-4" aria-label={text('title')}>
    <Button disabled={disabled || mutation.blocked} onClick={() => setOpen((value) => !value)}>{open ? text('close') : text('title')}</Button>
    {open && <>
      <p className="text-sm text-slate-300">{text('hint')}</p>
      {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
      {mutation.feedback}
      <form noValidate aria-label={text('upload')} onSubmit={upload} className="grid min-w-0 gap-3 md:grid-cols-2">
        <FormField name="file" type="file" accept=".csv,text/csv" label={text('file')} disabled={blocked} required error={mutation.errors(personImportsPath(org)).file?.[0]} />
        <SelectField name="delimiter" title={text('delimiter')} disabled={blocked} defaultValue=","><option value=",">{text('comma')}</option><option value=";">{text('semicolon')}</option></SelectField>
        <Button type="submit" disabled={blocked}>{text('upload')}</Button>
      </form>
      {loading && <p role="status">{label('loading')}</p>}
      {list && <><div className="flex flex-wrap gap-2">{list.data.map((item) => <Button key={item.id} disabled={blocked} onClick={() => { setSelected(item.id); setRowPage(1) }}>{text(item.status)} · {item.row_count} · {item.id.slice(-8)}</Button>)}</div>
        <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={blocked} onPageChange={setListPage} /></>}
      {run && <>
        <h4 className="font-semibold break-words">{text('title')} · {run.id}</h4>
        <p role="status">{text(run.status)} · {text('ready')} : {run.counts.ready ?? 0} · {text('created')} : {run.counts.created ?? 0} · {text('invalid')} : {run.counts.invalid ?? 0} · {text('duplicate')} : {run.counts.duplicate ?? 0} · {text('skipped')} : {run.counts.skipped ?? 0}</p>
        <Button disabled={blocked} onClick={() => setRefresh((value) => value + 1)}>{text('refresh')}</Button>
        {['uploaded', 'previewed'].includes(run.status) && <form key={run.id + run.version} noValidate aria-label={text('mapping')} onSubmit={preview} className="grid min-w-0 gap-3 md:grid-cols-2">
          {previewErrors.mapping?.[0] && <Alert tone="error" title={previewErrors.mapping[0]} />}
          {importFields.map((field) => <SelectField key={field} name={field} title={label(field)} defaultValue={String(run.mapping[field] ?? (field === 'pseudonym' ? 0 : ''))} disabled={blocked} error={previewErrors['mapping.' + field]?.[0]}><option value="">{text('ignore')}</option>{run.headers.map((header, index) => <option key={index} value={index}>{index + 1} · {header}</option>)}</SelectField>)}
          <FormField name="default_language" label={text('defaultLanguage')} defaultValue={run.defaults.language ?? active.context?.language ?? 'fr'} disabled={blocked} error={previewErrors['defaults.language']?.[0]} />
          <FormField name="default_timezone" label={text('defaultTimezone')} defaultValue={run.defaults.timezone ?? active.context?.timezone ?? 'UTC'} disabled={blocked} error={previewErrors['defaults.timezone']?.[0]} />
          <SelectField name="default_status" title={text('defaultStatus')} defaultValue={run.defaults.status ?? 'active'} disabled={blocked} error={previewErrors['defaults.status']?.[0]}>{personStatuses.map((status) => <option key={status} value={status}>{label('status.' + status)}</option>)}</SelectField>
          <Button type="submit" disabled={blocked}>{text('preview')}</Button>
        </form>}
        {run.rows.length > 0 && <><DataTable caption={text('preview')} columns={columns} rows={run.rows} rowKey={(row) => row.id} emptyTitle={text('empty')} />
          <Pagination currentPage={run.pagination.current_page} totalPages={run.pagination.last_page} disabled={blocked} onPageChange={setRowPage} /></>}
        {['previewed', 'queued', 'failed'].includes(run.status) && <form key={run.id + run.version + '-confirm'} aria-label={text('confirm')} onSubmit={confirm} className="grid min-w-0 gap-3">
          <label className="flex items-start gap-2"><input type="checkbox" name="confirm" required disabled={blocked} /><span>{text('confirmation')}</span></label>
          {((run.counts.invalid ?? 0) + (run.counts.duplicate ?? 0)) > 0 && <label className="flex items-start gap-2"><input type="checkbox" name="acknowledge_rejected" required disabled={blocked} /><span>{text('acknowledge')}</span></label>}
          <Button type="submit" disabled={blocked || (run.status === 'previewed' && !run.counts.ready)}>{run.status === 'previewed' ? text('confirm') : text('resume')}</Button>
        </form>}
        {!['queued', 'processing'].includes(run.status) && <Button disabled={blocked} onClick={() => void report()}>{text('report')}</Button>}
      </>}
    </>}
  </section>
}
