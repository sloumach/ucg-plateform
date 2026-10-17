import { useId, useState, type FormEvent } from 'react'
import { calendarKinds, calendarVisibilities, calendarText as text, type CalendarActivity } from '../../api/calendar'
import { calendarInstants } from '../../api/calendarTime'
import type { ActiveOrganization } from '../../api/tenancy'
import { t } from '../../i18n/fr'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { SelectField } from '../people/peopleForms'
import { CalendarParticipantPicker, CalendarTeamSelect } from './CalendarReferences'
import { CalendarTimeChoice } from './CalendarTimeChoice'
import { CalendarResourcePicker } from './CalendarResourcePicker'
import { CalendarConflictResult } from './CalendarConflictResult'
import { useCalendarConflictReview } from './useCalendarConflictReview'
import { bookingText } from '../../api/calendarConflicts'
import { Alert } from '../feedback/Alert'
type Props = { active: ActiveOrganization; root: string; item: CalendarActivity | null; disabled: boolean; onInvalid: () => void; errors: Record<string, string[]>;
  onSubmit: (event: FormEvent<HTMLFormElement>, values: Record<string, unknown>) => void; onCancel: () => void; defaultTimezone: string }
export function CalendarForm({ active, root, item, disabled, onInvalid, errors, onSubmit, onCancel, defaultTimezone }: Props) {
  const [timezone, setTimezone] = useState(item?.origin_timezone ?? defaultTimezone), [start, setStart] = useState(item?.starts_at_origin.slice(0, 19) ?? '')
  const [end, setEnd] = useState(item?.ends_at_origin.slice(0, 19) ?? ''), [startChoice, setStartChoice] = useState(item?.starts_at_origin ?? ''), [endChoice, setEndChoice] = useState(item?.ends_at_origin ?? '')
  const [team, setTeam] = useState(item?.team_id ?? ''), [participants, setParticipants] = useState(item?.participants ?? [])
  const [visibility, setVisibility] = useState(item?.visibility ?? 'private')
  const [resources, setResources] = useState(item?.resources ?? []), [reason, setReason] = useState(''), [confirmed, setConfirmed] = useState(false)
  const review = useCalendarConflictReview(active, root, onInvalid)
  const report = review.review?.report
  function invalidate() { review.clear(); setConfirmed(false) }
  const canPublish = active.context!.permissions.includes('calendar.publish'), id = useId()
  const startOptions = calendarInstants(start, timezone), endOptions = calendarInstants(end, timezone)
  const startInstant = startOptions.includes(startChoice) ? startChoice : startOptions.length === 1 ? startOptions[0] : ''
  const endInstant = endOptions.includes(endChoice) ? endChoice : endOptions.length === 1 ? endOptions[0] : ''
  const blocked = disabled || review.busy || !startInstant || !endInstant || (report && (report.truncated || (report.conflicts.length > 0 && (!report.can_override || !confirmed || reason.trim().length < 10))))
  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (blocked) return
    const data = new FormData(event.currentTarget)
    const values: Record<string, unknown> = { title: String(data.get('title') ?? '').trim(), kind: String(data.get('kind') ?? ''),
      description: String(data.get('description') ?? '').trim() || null, location: String(data.get('location') ?? '').trim() || null,
      url: String(data.get('url') ?? '').trim() || null, team_id: team || null, visibility,
      starts_at: startInstant, ends_at: endInstant, origin_timezone: timezone, participant_ids: participants.map((person) => person.id).sort(), resource_ids: resources.map((resource) => resource.id).sort() }
    if (item) {
      const datesChanged = startInstant !== item.starts_at_origin || endInstant !== item.ends_at_origin || timezone !== item.origin_timezone
      for (const key of ['title','kind','description','location','url','team_id','visibility'] as const) if (values[key] === item[key]) delete values[key]
      if (!datesChanged) { delete values.starts_at; delete values.ends_at; delete values.origin_timezone }
      if (JSON.stringify(values.participant_ids) === JSON.stringify(item.participants.map((person) => person.id).sort())) delete values.participant_ids
      if (JSON.stringify(values.resource_ids) === JSON.stringify((item.resources ?? []).map((resource) => resource.id).sort())) delete values.resource_ids
    }
    if (Object.keys(values).length && visibility !== 'private' && data.has('confirm_sharing')) values.confirm_sharing = true
    if (!Object.keys(values).length) { onSubmit(event, values); return }
    const candidate = { ...values, ...(item ? { activity_id: item.id, version: item.version } : {}) }
    if (!review.review || review.review.key !== JSON.stringify(candidate)) { setConfirmed(false); void review.inspect(candidate); return }
    onSubmit(event, { ...values, conflict_review: review.review.report.review_token,
      ...(report && report.conflicts.length > 0 ? { conflict_reason: reason.trim(), confirm_conflicts: confirmed } : {}) })
  }
  const fieldErrors = { ...errors, ...review.error?.fieldErrors }
  return <form noValidate aria-label={text('save')} onSubmit={submit} onChange={(event) => {
    const name = event.target.name
    if (!['conflict_reason', 'confirm_conflicts'].includes(name)) invalidate()
  }} className="grid min-w-0 gap-3 rounded-xl border border-cyan-400/30 p-3">
    {review.error && <Alert tone="error" title={review.error.message} requestId={review.error.requestId} />}
    <FormField name="title" label={text('name')} defaultValue={item?.title ?? ''} required maxLength={160} disabled={disabled} error={fieldErrors.title?.[0]} />
    <SelectField name="kind" title={text('kind')} defaultValue={item?.kind ?? 'training'} disabled={disabled} error={fieldErrors.kind?.[0]}>{calendarKinds.map((kind) => <option key={kind} value={kind}>{text('kind.' + kind)}</option>)}</SelectField>
    <div className="grid min-w-0 gap-2">
      <label htmlFor={id}>{text('description')}</label>
      <textarea id={id} name="description" defaultValue={item?.description ?? ''} maxLength={4000} rows={4} disabled={disabled}
        aria-invalid={errors.description ? true : undefined} aria-describedby={errors.description ? id + '-error' : undefined}
        className="min-w-0 w-full rounded-xl border border-white/15 bg-slate-900 px-4 py-3" />
      {errors.description && <p id={id + '-error'} className="text-sm text-rose-200">{errors.description[0]}</p>}
    </div>
    <FormField name="origin_timezone" label={text('originTimezone')} value={timezone} disabled={disabled} required error={fieldErrors.origin_timezone?.[0]} onChange={(event) => { setTimezone(event.target.value); setStartChoice(''); setEndChoice('') }} />
    <FormField name="starts_at" label={text('startsLocal')} value={start} onChange={(event) => { setStart(event.target.value); setStartChoice('') }} type="datetime-local" step="1" required disabled={disabled} error={fieldErrors.starts_at?.[0]} />
    {start && <CalendarTimeChoice options={startOptions} value={startInstant} onChange={setStartChoice} disabled={disabled} label={text('startOccurrence')} />}
    <FormField name="ends_at" label={text('endsLocal')} value={end} onChange={(event) => { setEnd(event.target.value); setEndChoice('') }} type="datetime-local" step="1" required disabled={disabled} error={fieldErrors.ends_at?.[0]} />
    {end && <CalendarTimeChoice options={endOptions} value={endInstant} onChange={setEndChoice} disabled={disabled} label={text('endOccurrence')} />}
    <p className="text-sm text-slate-400">{text('timeHint')}</p>
    <CalendarTeamSelect {...{ active, root, disabled, onInvalid }} value={team} onChange={(next) => { setTeam(next); invalidate() }} currentLabel={item?.team_label ?? null} error={fieldErrors.team_id?.[0]} />
    <CalendarParticipantPicker {...{ active, root, disabled, onInvalid }} selected={participants} onChange={(next) => { setParticipants(next); invalidate() }} error={fieldErrors.participant_ids?.[0] ?? Object.entries(fieldErrors).find(([key]) => key.startsWith('participant_ids.'))?.[1][0]} />
    <CalendarResourcePicker {...{ active, root, disabled, onInvalid }} selected={resources} onChange={(next) => { setResources(next); invalidate() }} error={fieldErrors.resource_ids?.[0] ?? Object.entries(fieldErrors).find(([key]) => key.startsWith('resource_ids.'))?.[1][0]} />
    <FormField name="location" label={text('location')} defaultValue={item?.location ?? ''} maxLength={200} disabled={disabled} error={fieldErrors.location?.[0]} />
    <FormField name="url" label={text('url')} type="url" defaultValue={item?.url ?? ''} maxLength={2000} disabled={disabled} error={fieldErrors.url?.[0]} />
    <SelectField name="visibility" title={text('visibility')} value={visibility} onChange={(next) => setVisibility(next as CalendarActivity['visibility'])} disabled={disabled} error={fieldErrors.visibility?.[0]}>
      {calendarVisibilities.filter((key) => canPublish || key === 'private' || key === item?.visibility).map((key) => <option key={key} value={key}>{text('visibility.' + key)}</option>)}
    </SelectField>
    <p className="text-sm text-slate-400">{text('visibilityHint')}</p>
    {visibility !== 'private' && <FormField name="confirm_sharing" label={text('confirmSharing')} type="checkbox" disabled={disabled || !canPublish} error={fieldErrors.confirm_sharing?.[0]} />}
    <p className="text-sm text-slate-400">{bookingText('scopeHint')}</p>
    {review.busy && <p role="status">{bookingText('checking')}</p>}
    {report && <CalendarConflictResult report={report} timezone={timezone} people={participants} resources={resources} />}
    {report && report.conflicts.length > 0 && report.can_override && !report.truncated && <>
      <FormField name="conflict_reason" label={bookingText('reason')} value={reason} minLength={10} maxLength={1000} disabled={disabled} error={fieldErrors.conflict_reason?.[0]} onChange={(event) => setReason(event.target.value)} />
      <FormField name="confirm_conflicts" label={bookingText('confirmOverride')} type="checkbox" checked={confirmed} disabled={disabled} onChange={(event) => setConfirmed(event.target.checked)} />
    </>}
    <div className="flex flex-wrap gap-3"><Button type="submit" disabled={Boolean(blocked)}>{report ? text('save') : bookingText('check')}</Button><Button disabled={disabled} onClick={onCancel}>{t('tenant.cancel')}</Button></div>
  </form>
}
