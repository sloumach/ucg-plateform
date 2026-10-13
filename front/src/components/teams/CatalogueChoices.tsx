import { t } from '../../i18n/fr'
import type { Choice } from '../../api/gameCatalogue'

export function CatalogueChoices({ title, choices, value, onChange, disabled, error, inherit = false }: {
  title: string; choices: Choice[]; value: string[] | undefined; onChange: (value: string[] | undefined) => void
  disabled: boolean; error?: string; inherit?: boolean
}) {
  const retired = (value ?? []).filter((key) => !choices.some((choice) => choice.key === key))
  return <fieldset disabled={disabled} className="grid min-w-0 gap-3 rounded-xl border border-slate-700 p-3">
    <legend className="px-1 font-semibold">{title}</legend>
    {inherit && <label className="flex items-start gap-2"><input type="checkbox" checked={value === undefined}
      onChange={(event) => onChange(event.target.checked ? undefined : [])} />{t('games.inherit')}</label>}
    {value !== undefined && <div className="grid min-w-0 gap-2 sm:grid-cols-2">
      {choices.map((choice) => <label className="flex min-w-0 items-start gap-2 break-words [overflow-wrap:anywhere]" key={choice.key}>
        <input type="checkbox" checked={value.includes(choice.key)} onChange={(event) => onChange(event.target.checked
          ? [...value, choice.key] : value.filter((key) => key !== choice.key))} />{choice.name}
      </label>)}
      {choices.length === 0 && <p className="text-slate-400">{t('games.noOptions')}</p>}
    </div>}
    {retired.length > 0 && <p className="break-words text-amber-200 [overflow-wrap:anywhere]">{t('games.retiredChoices', { keys: retired.join(', ') })}</p>}
    {error && <p role="alert" className="text-rose-200">{error}</p>}
  </fieldset>
}
