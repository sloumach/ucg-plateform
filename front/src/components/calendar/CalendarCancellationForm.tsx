import type { FormEvent } from 'react'
import type { ApiFieldErrors } from '../../api/contracts'
import { changeText as text } from '../../api/calendarChanges'
import { Button } from '../Button'
import { FormField } from '../FormField'

type Props = { title: string; disabled: boolean; errors: ApiFieldErrors; onCancel: () => void; onSubmit: (event: FormEvent<HTMLFormElement>, reason: string) => void }
export function CalendarCancellationForm({ title, disabled, errors, onCancel, onSubmit }: Props) {
  return <form aria-label={text('cancelTitle')} className="grid min-w-0 gap-3 rounded-xl border border-amber-400/30 p-3" onSubmit={(event) => {
    onSubmit(event, String(new FormData(event.currentTarget).get('change_reason') ?? '').trim())
  }}>
    <h3 className="font-bold [overflow-wrap:anywhere]">{text('cancelTitle')} · {title}</h3>
    <p className="text-sm text-amber-200">{text('cancelHint')}</p>
    <FormField name="change_reason" label={text('reasonLabel')} required minLength={10} maxLength={1000} disabled={disabled} error={errors.change_reason?.[0]} />
    <p className="text-sm text-slate-400">{text('reasonHint')}</p>
    <div className="flex flex-wrap gap-3"><Button type="submit" disabled={disabled}>{text('cancelAction')}</Button><Button disabled={disabled} onClick={onCancel}>{text('keep')}</Button></div>
  </form>
}