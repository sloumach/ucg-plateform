import { useState, type FormEvent } from 'react'
import type { ActiveOrganization } from '../../api/tenancy'
import { availabilityText as text, availabilityStatuses, type Availability } from '../../api/availability'
import { calendarText as calendar } from '../../api/calendar'
import { calendarInstants } from '../../api/calendarTime'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { SelectField } from '../people/peopleForms'
import { CalendarTimeChoice } from './CalendarTimeChoice'
import { AvailabilityPersonSelect } from './AvailabilityPersonSelect'

type Props = {
  active: ActiveOrganization; root: string; item: Availability | null; disabled: boolean; onInvalid: () => void; errors: Record<string, string[]>
  viewerId: string | null; defaultTimezone: string; canSelf: boolean; canManage: boolean
  onSubmit: (event: FormEvent<HTMLFormElement>, values: Record<string, unknown>) => void; onCancel: () => void
}
export function AvailabilityForm({ active, root, item, disabled, onInvalid, errors, viewerId, defaultTimezone, canSelf, canManage, onSubmit, onCancel }: Props) {
  const [timezone, setTimezone] = useState(item?.origin_timezone ?? defaultTimezone), [start, setStart] = useState(item?.starts_at_origin.slice(0, 19) ?? '')
  const [end, setEnd] = useState(item?.ends_at_origin.slice(0, 19) ?? ''), [startChoice, setStartChoice] = useState(item?.starts_at_origin ?? ''), [endChoice, setEndChoice] = useState(item?.ends_at_origin ?? '')
  const [forMe, setForMe] = useState(canSelf && viewerId !== null), [personId, setPersonId] = useState('')
  const startOptions = calendarInstants(start, timezone), endOptions = calendarInstants(end, timezone)
  const startInstant = startOptions.includes(startChoice) ? startChoice : startOptions.length === 1 ? startOptions[0] : ''
  const endInstant = endOptions.includes(endChoice) ? endChoice : endOptions.length === 1 ? endOptions[0] : ''
  const blocked = disabled || !startInstant || !endInstant || (!item && !forMe && !personId)
  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (blocked) return
    const data = new FormData(event.currentTarget)
    const values: Record<string, unknown> = { status: String(data.get('status') ?? ''), comment: String(data.get('comment') ?? '').trim() || null,
      starts_at: startInstant, ends_at: endInstant, origin_timezone: timezone }
    if (!item && !forMe) values.person_id = personId
    if (item) {
      if (values.status === item.status) delete values.status
      if (values.comment === (item.comment ?? null)) delete values.comment
      if (startInstant === item.starts_at_origin && endInstant === item.ends_at_origin && timezone === item.origin_timezone) {
        delete values.starts_at; delete values.ends_at; delete values.origin_timezone
      }
    }
    onSubmit(event, values)
  }
  return <form aria-label={text('save')} onSubmit={submit} className="grid min-w-0 gap-3 rounded-xl border border-cyan-400/30 p-4 sm:grid-cols-2">
    <h3 className="font-bold sm:col-span-2">{text(item ? 'edit' : 'new')}</h3>
    {item && <p className="sm:col-span-2 [overflow-wrap:anywhere]">{text('person')} : {item.person_label ?? text('unknownPerson')}</p>}
    {!item && canSelf && viewerId && canManage && <FormField name="for_me" type="checkbox" label={text('forMe')} checked={forMe} disabled={disabled} onChange={(event) => setForMe(event.target.checked)} />}
    {!item && !forMe && canManage && <AvailabilityPersonSelect {...{ active, root, disabled, onInvalid }} value={personId} onChange={setPersonId} error={errors.person_id?.[0]} required />}
    <SelectField name="status" title={text('status')} defaultValue={item?.status ?? 'available'} disabled={disabled} error={errors.status?.[0]}>
      {availabilityStatuses.map((status) => <option key={status} value={status}>{text('status.' + status)}</option>)}
    </SelectField>
    <FormField name="origin_timezone" label={calendar('originTimezone')} value={timezone} required disabled={disabled} error={errors.origin_timezone?.[0]} onChange={(event) => { setTimezone(event.target.value); setStartChoice(''); setEndChoice('') }} />
    <FormField name="starts_at" label={calendar('startsLocal')} type="datetime-local" step="1" value={start} required disabled={disabled} error={errors.starts_at?.[0]} onChange={(event) => { setStart(event.target.value); setStartChoice('') }} />
    {start && <CalendarTimeChoice options={startOptions} value={startInstant} onChange={setStartChoice} disabled={disabled} label={calendar('startOccurrence')} />}
    <FormField name="ends_at" label={calendar('endsLocal')} type="datetime-local" step="1" value={end} required disabled={disabled} error={errors.ends_at?.[0]} onChange={(event) => { setEnd(event.target.value); setEndChoice('') }} />
    {end && <CalendarTimeChoice options={endOptions} value={endInstant} onChange={setEndChoice} disabled={disabled} label={calendar('endOccurrence')} />}
    <p className="text-sm text-slate-400 sm:col-span-2">{calendar('timeHint')}</p>
    <FormField name="comment" label={text('comment')} defaultValue={item?.comment ?? ''} maxLength={1000} disabled={disabled} error={errors.comment?.[0]} hint={text('commentHint')} />
    <div className="flex flex-wrap gap-3 sm:col-span-2"><Button type="submit" disabled={blocked}>{text('save')}</Button><Button disabled={disabled} onClick={onCancel}>{text('closeForm')}</Button></div>
  </form>
}
