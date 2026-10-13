import { useId, type ReactNode } from 'react'
import { FormField } from '../FormField'
import { label, personStatuses } from './peopleFormValues'

export function SelectField({ name, title, children, error, disabled, value, onChange, defaultValue }: {
  name: string; title: string; children: ReactNode; error?: string; disabled?: boolean
  value?: string; defaultValue?: string; onChange?: (value: string) => void
}) {
  const id = useId()
  return <div className="grid min-w-0 grid-cols-1 gap-2">
    <label className="[overflow-wrap:anywhere]" htmlFor={id}>{title}</label>
    <select id={id} name={name} value={value} defaultValue={defaultValue} onChange={onChange ? (event) => onChange(event.target.value) : undefined}
      disabled={disabled} aria-invalid={error ? true : undefined} aria-describedby={error ? id + '-error' : undefined}
      className="min-w-0 w-full rounded-xl border border-white/15 bg-slate-900 px-3 py-3 text-slate-100">
      {children}
    </select>
    {error && <p id={id + '-error'} className="text-sm text-rose-200">{error}</p>}
  </div>
}

export function PersonField({ name, value, error, disabled, language, timezone }: {
  name: string; value?: string | number | null; error?: string; disabled: boolean; language?: string; timezone?: string
}) {
  const id = useId()
  if (name === 'status') return <SelectField name={name} title={label(name)} defaultValue={String(value ?? 'candidate')} error={error} disabled={disabled}>
    {personStatuses.map((status) => <option key={status} value={status}>{label('status.' + status)}</option>)}
  </SelectField>
  if (name.startsWith('biography')) return <div className="grid min-w-0 gap-2">
    <label htmlFor={id}>{label(name)}</label>
    <textarea id={id} name={name} defaultValue={value ?? ''} disabled={disabled} maxLength={4000} rows={4}
      aria-invalid={error ? true : undefined} aria-describedby={error ? id + '-error' : undefined}
      className="min-w-0 w-full rounded-xl border border-white/15 bg-slate-900 px-4 py-3 text-slate-100" />
    {error && <p id={id + '-error'} className="text-sm text-rose-200">{error}</p>}
  </div>
  const defaults: Record<string, string> = { language: language ?? 'fr', timezone: timezone ?? 'UTC' }
  return <FormField name={name} label={label(name)} defaultValue={value ?? defaults[name] ?? ''} disabled={disabled} error={error}
    required={['pseudonym', 'language', 'timezone'].includes(name)} type={name === 'birth_date' ? 'date' : name === 'user_id' ? 'number' : name === 'contact_email' ? 'email' : 'text'}
    min={name === 'user_id' ? 1 : undefined} maxLength={name === 'pseudonym' ? 80 : name === 'contact_email' ? 254 : name === 'contact_phone' ? 32 : undefined} />
}
