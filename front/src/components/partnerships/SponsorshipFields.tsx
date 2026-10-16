import { useId, useState } from 'react'
import type { ActiveOrganization } from '../../api/tenancy'
import { agreementText as text, type SponsorshipReference } from '../../api/sponsorshipAgreements'
import { SelectField } from '../people/peopleForms'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { usePartnershipList } from './usePartnershipList'

type Choice = SponsorshipReference & { title?: string; target_label?: string }
export function SponsorshipReferenceSelect({ active, path, name, title, value = '', optional = false, disabled, error, onInvalid, filters }: {
  active: ActiveOrganization; path: string; name: string; title: string; value?: string; optional?: boolean; disabled: boolean; error?: string; onInvalid: () => void; filters?: Record<string, string>
}) {
  const [page, setPage] = useState(1), [selected, setSelected] = useState(value)
  const { list, loading, error: loadError } = usePartnershipList<Choice>(path + '?' + new URLSearchParams({ per_page: '10', page: String(page), ...filters }), active, true, onInvalid)
  return <div className="grid min-w-0 gap-2">
    <SelectField name={name} title={title} value={selected} onChange={setSelected} disabled={disabled} error={error}>
      <option value="">{text(optional ? 'none' : 'choose')}</option>
      {selected && !list?.data.some((choice) => choice.id === selected) && <option value={selected}>{text('current')}</option>}
      {list?.data.map((choice) => <option key={choice.id} value={choice.id}>{choice.label ?? choice.title ?? choice.target_label}</option>)}
    </SelectField>
    {loading && <p role="status">{text('loading')}</p>}
    {loadError && <Alert tone="error" title={loadError.message} requestId={loadError.requestId} />}
    {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={disabled || loading} onPageChange={setPage} />}
  </div>
}

export function SponsorshipTextArea({ name, title, value = '', maxLength = 2000, required = false, disabled, error }: {
  name: string; title: string; value?: string; maxLength?: number; required?: boolean; disabled: boolean; error?: string
}) {
  const id = useId()
  return <div className="grid min-w-0 gap-2">
    <label htmlFor={id}>{title}</label>
    <textarea id={id} name={name} defaultValue={value} maxLength={maxLength} rows={3} required={required} disabled={disabled}
      aria-invalid={error ? true : undefined} aria-describedby={error ? id + '-error' : undefined}
      className="min-w-0 w-full rounded-xl border border-white/15 bg-slate-900 px-4 py-3 text-slate-100" />
    {error && <p id={id + '-error'} className="text-sm text-rose-200">{error}</p>}
  </div>
}
