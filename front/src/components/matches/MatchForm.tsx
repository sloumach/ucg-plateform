import { useState, type FormEvent } from 'react'
import type { ActiveOrganization } from '../../api/tenancy'
import type { ApiFieldErrors } from '../../api/contracts'
import { matchFormats, matchText as text, type MatchRecord, type MatchSelection, type MatchChoice } from '../../api/matches'
import { calendarInstants } from '../../api/calendarTime'
import { calendarText } from '../../api/calendar'
import { CalendarTimeChoice } from '../calendar/CalendarTimeChoice'
import { FormField } from '../FormField'
import { Button } from '../Button'
import { SelectField } from '../people/peopleForms'
import { MatchReferenceSelect, MatchParticipants } from './MatchReferences'

type Props = { active: ActiveOrganization; root: string; item: MatchRecord | null; disabled: boolean; onInvalid: () => void;
  errors: ApiFieldErrors; onCancel: () => void; onSubmit: (event: FormEvent<HTMLFormElement>, body: Record<string, unknown>) => void }
export function MatchForm({ active, root, item, disabled, onInvalid, errors, onCancel, onSubmit }: Props) {
  const [game, setGame] = useState<MatchChoice | null>(item ? { id: item.game_id, label: item.game_name } : null)
  const [responsible, setResponsible] = useState<MatchChoice | null>(item ? { id: item.responsible_person_id, label: item.responsible_label } : null)
  const [participants, setParticipants] = useState<MatchSelection[]>(item?.participants ?? [])
  const [zone, setZone] = useState(item?.origin_timezone ?? active.context!.timezone)
  const [start, setStart] = useState(item?.starts_at_origin.slice(0, 19) ?? ''), [end, setEnd] = useState(item?.ends_at_origin.slice(0, 19) ?? '')
  const [startChoice, setStartChoice] = useState(item?.starts_at_origin ?? ''), [endChoice, setEndChoice] = useState(item?.ends_at_origin ?? '')
  const [visibility, setVisibility] = useState(item?.visibility ?? 'private')
  const starts = calendarInstants(start, zone), ends = calendarInstants(end, zone)
  const startInstant = starts.includes(startChoice) ? startChoice : starts.length === 1 ? starts[0] : ''
  const endInstant = ends.includes(endChoice) ? endChoice : ends.length === 1 ? ends[0] : ''
  const canPublish = active.context!.permissions.includes('sports.publish')
  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!startInstant || !endInstant) return
    const data = new FormData(event.currentTarget)
    const body: Record<string, unknown> = { title: String(data.get('title') ?? '').trim(), kind: String(data.get('kind') ?? ''), format: String(data.get('format') ?? ''),
      responsible_person_id: responsible?.id ?? '', visibility, starts_at: startInstant, ends_at: endInstant, origin_timezone: zone,
      participants: participants.map(({ kind, source_id }) => ({ kind, source_id })) }
    if (item) {
      for (const key of ['title', 'kind', 'format', 'responsible_person_id', 'visibility'] as const) if (body[key] === item[key]) delete body[key]
      if (startInstant === item.starts_at_origin && endInstant === item.ends_at_origin && zone === item.origin_timezone) {
        delete body.starts_at; delete body.ends_at; delete body.origin_timezone
      }
      if (JSON.stringify(body.participants) === JSON.stringify(item.participants.map(({ kind, source_id }) => ({ kind, source_id })))) delete body.participants
    } else body.game_id = game?.id ?? ''
    if (visibility !== 'private' && data.has('confirm_sharing')) body.confirm_sharing = true
    onSubmit(event, body)
  }
  return <form noValidate aria-label={text('save')} onSubmit={submit} className="grid min-w-0 gap-3 rounded-xl border border-cyan-400/30 p-3">
    <FormField name="title" label={text('name')} defaultValue={item?.title ?? ''} required maxLength={160} disabled={disabled} error={errors.title?.[0]} />
    <MatchReferenceSelect {...{ active, root, onInvalid }} name="game_id" type="game" title={text('game')} value={game?.id ?? ''} label={game?.label} load={!item} disabled={disabled || Boolean(item)}
      onChange={(next) => { setGame(next); setParticipants([]) }} error={errors.game_id?.[0]} />
    <SelectField name="kind" title={text('kind')} defaultValue={item?.kind ?? 'scrim'} disabled={disabled} error={errors.kind?.[0]}>
      <option value="scrim">{text('kind.scrim')}</option><option value="official">{text('kind.official')}</option>
    </SelectField>
    <SelectField name="format" title={text('format')} defaultValue={item?.format ?? 'bo1'} disabled={disabled} error={errors.format?.[0]}>
      {matchFormats.map((format) => <option key={format} value={format}>{text('format.' + format)}</option>)}
    </SelectField>
    <MatchReferenceSelect {...{ active, root, onInvalid, disabled }} name="responsible_person_id" type="person" title={text('responsible')} value={responsible?.id ?? ''} label={responsible?.label} onChange={setResponsible} error={errors.responsible_person_id?.[0]} />
    <FormField name="origin_timezone" label={calendarText('originTimezone')} value={zone} required disabled={disabled} error={errors.origin_timezone?.[0]} onChange={(event) => { setZone(event.target.value); setStartChoice(''); setEndChoice('') }} />
    <FormField name="starts_at" label={calendarText('startsLocal')} value={start} type="datetime-local" step="1" required disabled={disabled} error={errors.starts_at?.[0]} onChange={(event) => { setStart(event.target.value); setStartChoice('') }} />
    {start && <CalendarTimeChoice label={calendarText('startOccurrence')} options={starts} value={startInstant} disabled={disabled} onChange={setStartChoice} />}
    <FormField name="ends_at" label={calendarText('endsLocal')} value={end} type="datetime-local" step="1" required disabled={disabled} error={errors.ends_at?.[0]} onChange={(event) => { setEnd(event.target.value); setEndChoice('') }} />
    {end && <CalendarTimeChoice label={calendarText('endOccurrence')} options={ends} value={endInstant} disabled={disabled} onChange={setEndChoice} />}
    <p className="text-sm text-slate-400">{calendarText('timeHint')}</p>
    <MatchParticipants {...{ active, root, disabled, onInvalid }} game={game?.id ?? ''} selected={participants} onChange={setParticipants} error={errors.participants?.[0] ?? Object.entries(errors).find(([key]) => key.startsWith('participants.'))?.[1][0]} />
    <SelectField name="visibility" title={text('visibility')} value={visibility} disabled={disabled} error={errors.visibility?.[0]} onChange={(value) => setVisibility(value as MatchRecord['visibility'])}>
      {(['private', 'team', 'organization'] as const).filter((value) => canPublish || value === 'private' || value === item?.visibility).map((value) => <option key={value} value={value}>{text('visibility.' + value)}</option>)}
    </SelectField>
    {visibility !== 'private' && <FormField name="confirm_sharing" label={text('confirmSharing')} type="checkbox" disabled={disabled || !canPublish} error={errors.confirm_sharing?.[0]} />}
    <div className="flex flex-wrap gap-3"><Button type="submit" disabled={disabled || !startInstant || !endInstant}>{text('save')}</Button><Button disabled={disabled} onClick={onCancel}>{text('cancel')}</Button></div>
  </form>
}
