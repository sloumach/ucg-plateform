import { useState, type FormEvent } from 'react'
import { academyDay, type AcademyCycle, type WeeklySlot } from '../../api/academy'
import { t } from '../../i18n/fr'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { SelectField } from '../people/peopleForms'

type Props = { cycle: AcademyCycle | null; timezone: string; disabled: boolean; errors: Record<string, string[]>; onCancel: () => void; onSubmit: (event: FormEvent<HTMLFormElement>, body: Record<string, unknown>) => void }
export function AcademyCycleForm({ cycle, timezone, disabled, errors, onCancel, onSubmit }: Props) {
  const [slots, setSlots] = useState<WeeklySlot[]>(cycle?.timetable ?? [])
  function slot(index: number, changes: Partial<WeeklySlot>) { setSlots((values) => values.map((value, position) => index === position ? { ...value, ...changes } : value)) }
  function save(event: FormEvent<HTMLFormElement>) {
    const data = new FormData(event.currentTarget)
    onSubmit(event, { name: String(data.get('name')), starts_on: String(data.get('starts_on')), ends_on: String(data.get('ends_on')), timezone: String(data.get('timezone')), timetable: slots })
  }
  return <form aria-label={t('academy.saveCycle')} className="grid min-w-0 gap-3" onSubmit={save}>
    <FormField label={t('academy.name')} name="name" required maxLength={120} defaultValue={cycle?.name} disabled={disabled} error={errors.name?.[0]} />
    <div className="grid min-w-0 gap-3 sm:grid-cols-2"><FormField label={t('academy.starts')} type="date" name="starts_on" required defaultValue={cycle?.starts_on} disabled={disabled} error={errors.starts_on?.[0]} /><FormField label={t('academy.ends')} type="date" name="ends_on" required defaultValue={cycle?.ends_on} disabled={disabled} error={errors.ends_on?.[0]} /></div>
    <FormField label={t('academy.timezone')} name="timezone" required maxLength={64} defaultValue={cycle?.timezone ?? timezone} disabled={disabled} error={errors.timezone?.[0]} />
    <p>{t('academy.scheduleHint')}</p>
    {errors.timetable && <p role="alert">{errors.timetable[0]}</p>}
    {slots.map((value, index) => <fieldset key={index} disabled={disabled} className="grid min-w-0 gap-3 rounded-xl border border-slate-700 p-3 sm:grid-cols-2">
      <legend>{t('academy.slot')} {index + 1}</legend>
      <SelectField name={'day-' + index} title={t('academy.day')} value={String(value.day)} onChange={(next) => slot(index, { day: Number(next) })} error={errors['timetable.' + index + '.day']?.[0]}>{[1, 2, 3, 4, 5, 6, 7].map((day) => <option key={day} value={day}>{academyDay(day)}</option>)}</SelectField>
      <FormField label={t('academy.slotStart')} type="time" required value={value.starts_at} onChange={(event) => slot(index, { starts_at: event.target.value })} error={errors['timetable.' + index + '.starts_at']?.[0]} />
      <FormField label={t('academy.slotEnd')} type="time" required value={value.ends_at} onChange={(event) => slot(index, { ends_at: event.target.value })} error={errors['timetable.' + index + '.ends_at']?.[0]} />
      <Button onClick={() => setSlots((values) => values.filter((_, position) => position !== index))}>{t('academy.removeSlot')}</Button>
    </fieldset>)}
    <Button disabled={disabled || slots.length >= 28} onClick={() => setSlots((values) => [...values, { day: 1, starts_at: '18:00', ends_at: '20:00' }])}>{t('academy.addSlot')}</Button>
    <div className="flex flex-wrap gap-2"><Button type="submit" disabled={disabled}>{t('academy.saveCycle')}</Button><Button disabled={disabled} onClick={onCancel}>{t('academy.cancel')}</Button></div>
  </form>
}
