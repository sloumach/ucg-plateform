import { useId, useState, type FormEvent } from 'react'
import { calendarKinds, calendarVisibilities, calendarText as text, type CalendarActivity } from '../../api/calendar'
import { calendarInstants } from '../../api/calendarTime'
import type { ActiveOrganization } from '../../api/tenancy'
import { t } from '../../i18n/fr'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { SelectField } from '../people/peopleForms'
import { CalendarParticipantPicker, CalendarTeamSelect } from './CalendarReferences'
type Props = { active: ActiveOrganization; root: string; item: CalendarActivity | null; disabled: boolean; onInvalid: () => void; errors: Record<string, string[]>;
  onSubmit: (event: FormEvent<HTMLFormElement>, values: Record<string, unknown>) => void; onCancel: () => void; defaultTimezone: string }
export function CalendarForm({ active, root, item, disabled, onInvalid, errors, onSubmit, onCancel, defaultTimezone }: Props) {
  const [timezone, setTimezone] = useState(item?.origin_timezone ?? defaultTimezone), [start, setStart] = useState(item?.starts_at_origin.slice(0, 19) ?? '')
  const [end, setEnd] = useState(item?.ends_at_origin.slice(0, 19) ?? ''), [startChoice, setStartChoice] = useState(item?.starts_at_origin ?? ''), [endChoice, setEndChoice] = useState(item?.ends_at_origin ?? '')
  const [team, setTeam] = useState(item?.team_id ?? ''), [participants, setParticipants] = useState(item?.participants ?? [])
  const [visibility, setVisibility] = useState(item?.visibility ?? 'private')
  const canPublish = active.context!.permissions.includes('calendar.publish'), id = useId()
  const startOptions = calendarInstants(start, timezone), endOptions = calendarInstants(end, timezone)
  const startInstant = startOptions.includes(startChoice) ? startChoice : startOptions.length === 1 ? startOptions[0] : ''
  const endInstant = endOptions.includes(endChoice) ? endChoice : endOptions.length === 1 ? endOptions[0] : ''
  const blocked = disabled || !startInstant || !endInstant
  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (blocked) return
    const data = new FormData(event.currentTarget)
    const values: Record<string, unknown> = { title: String(data.get('title') ?? '').trim(), kind: String(data.get('kind') ?? ''),
      description: String(data.get('description') ?? '').trim() || null, location: String(data.get('location') ?? '').trim() || null,
      url: String(data.get('url') ?? '').trim() || null, team_id: team || null, visibility,
      starts_at: startInstant, ends_at: endInstant, origin_timezone: timezone, participant_ids: participants.map((person) => person.id).sort() }
    if (item) {
      const datesChanged = startInstant !== item.starts_at_origin || endInstant !== item.ends_at_origin || timezone !== item.origin_timezone
      for (const key of ['title','kind','description','location','url','team_id','visibility'] as const) if (values[key] === item[key]) delete values[key]
      if (!datesChanged) { delete values.starts_at; delete values.ends_at; delete values.origin_timezone }
      if (JSON.stringify(values.participant_ids) === JSON.stringify(item.participants.map((person) => person.id).sort())) delete values.participant_ids
    }
    if (Object.keys(values).length && visibility !== 'private' && data.has('confirm_sharing')) values.confirm_sharing = true
    onSubmit(event, values)
  }
  return <form noValidate aria-label={text('save')} onSubmit={submit} className="grid min-w-0 gap-3 rounded-xl border border-cyan-400/30 p-3">
    <FormField name="title" label={text('name')} defaultValue={item?.title ?? ''} required maxLength={160} disabled={disabled} error={errors.title?.[0]} />
    <SelectField name="kind" title={text('kind')} defaultValue={item?.kind ?? 'training'} disabled={disabled} error={errors.kind?.[0]}>{calendarKinds.map((kind) => <option key={kind} value={kind}>{text('kind.' + kind)}</option>)}</SelectField>
    <div className="grid min-w-0 gap-2">
      <label htmlFor={id}>{text('description')}</label>
      <textarea id={id} name="description" defaultValue={item?.description ?? ''} maxLength={4000} rows={4} disabled={disabled}
        aria-invalid={errors.description ? true : undefined} aria-describedby={errors.description ? id + '-error' : undefined}
        className="min-w-0 w-full rounded-xl border border-white/15 bg-slate-900 px-4 py-3" />
      {errors.description && <p id={id + '-error'} className="text-sm text-rose-200">{errors.description[0]}</p>}
    </div>
    <FormField name="origin_timezone" label={text('originTimezone')} value={timezone} disabled={disabled} required error={errors.origin_timezone?.[0]} onChange={(event) => { setTimezone(event.target.value); setStartChoice(''); setEndChoice('') }} />
    <FormField name="starts_at" label={text('startsLocal')} value={start} onChange={(event) => { setStart(event.target.value); setStartChoice('') }} type="datetime-local" step="1" required disabled={disabled} error={errors.starts_at?.[0]} />
    {start && <TimeChoice options={startOptions} value={startInstant} onChange={setStartChoice} disabled={disabled} label={text('startOccurrence')} />}
    <FormField name="ends_at" label={text('endsLocal')} value={end} onChange={(event) => { setEnd(event.target.value); setEndChoice('') }} type="datetime-local" step="1" required disabled={disabled} error={errors.ends_at?.[0]} />
    {end && <TimeChoice options={endOptions} value={endInstant} onChange={setEndChoice} disabled={disabled} label={text('endOccurrence')} />}
    <p className="text-sm text-slate-400">{text('timeHint')}</p>
    <CalendarTeamSelect {...{ active, root, disabled, onInvalid }} value={team} onChange={setTeam} currentLabel={item?.team_label ?? null} error={errors.team_id?.[0]} />
    <CalendarParticipantPicker {...{ active, root, disabled, onInvalid }} selected={participants} onChange={setParticipants} error={errors.participant_ids?.[0] ?? Object.entries(errors).find(([key]) => key.startsWith('participant_ids.'))?.[1][0]} />
    <FormField name="location" label={text('location')} defaultValue={item?.location ?? ''} maxLength={200} disabled={disabled} error={errors.location?.[0]} />
    <FormField name="url" label={text('url')} type="url" defaultValue={item?.url ?? ''} maxLength={2000} disabled={disabled} error={errors.url?.[0]} />
    <SelectField name="visibility" title={text('visibility')} value={visibility} onChange={(next) => setVisibility(next as CalendarActivity['visibility'])} disabled={disabled} error={errors.visibility?.[0]}>
      {calendarVisibilities.filter((key) => canPublish || key === 'private' || key === item?.visibility).map((key) => <option key={key} value={key}>{text('visibility.' + key)}</option>)}
    </SelectField>
    <p className="text-sm text-slate-400">{text('visibilityHint')}</p>
    {visibility !== 'private' && <FormField name="confirm_sharing" label={text('confirmSharing')} type="checkbox" disabled={disabled || !canPublish} error={errors.confirm_sharing?.[0]} />}
    <div className="flex flex-wrap gap-3"><Button type="submit" disabled={blocked}>{text('save')}</Button><Button disabled={disabled} onClick={onCancel}>{t('tenant.cancel')}</Button></div>
  </form>
}
function TimeChoice({ options, value, onChange, disabled, label }: { options: string[]; value: string; onChange: (value: string) => void; disabled: boolean; label: string }) {
  if (!options.length) return <p role="alert" className="text-sm text-rose-200">{text('invalidLocal')}</p>
  if (options.length === 1) return <p className="text-sm text-slate-400">{text('offset')} : UTC{options[0].slice(-6)}</p>
  return <div className="grid min-w-0 gap-2"><p className="text-sm text-amber-200">{text('ambiguousLocal')}</p>
    <SelectField name={label} title={label} value={value} onChange={onChange} disabled={disabled}><option value="">{text('chooseOccurrence')}</option>
      {options.map((option, index) => <option key={option} value={option}>{text(index === 0 ? 'firstOccurrence' : 'secondOccurrence')} · UTC{option.slice(-6)}</option>)}
    </SelectField>
  </div>
}
