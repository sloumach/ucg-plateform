import { calendarText as text } from '../../api/calendar'
import { SelectField } from '../people/peopleForms'

export function CalendarTimeChoice({ options, value, onChange, disabled, label }: { options: string[]; value: string; onChange: (value: string) => void; disabled: boolean; label: string }) {
  if (!options.length) return <p role="alert" className="text-sm text-rose-200">{text('invalidLocal')}</p>
  if (options.length === 1) return <p className="text-sm text-slate-400">{text('offset')} : UTC{options[0].slice(-6)}</p>
  return <div className="grid min-w-0 gap-2"><p className="text-sm text-amber-200">{text('ambiguousLocal')}</p>
    <SelectField name={label} title={label} value={value} onChange={onChange} disabled={disabled}><option value="">{text('chooseOccurrence')}</option>
      {options.map((option, index) => <option key={option} value={option}>{text(index === 0 ? 'firstOccurrence' : 'secondOccurrence')} · UTC{option.slice(-6)}</option>)}
    </SelectField>
  </div>
}
