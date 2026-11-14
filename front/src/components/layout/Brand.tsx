import { t } from '../../i18n/fr'

export function Brand() {
  return (
    <div className="flex min-w-0 items-center gap-3">
      <span aria-hidden="true" className="grid size-10 shrink-0 place-items-center rounded-xl bg-cyan-300 text-xs font-black text-slate-950">
        UCG
      </span>
      <span className="text-sm font-semibold text-slate-100">{t('app.name')}</span>
    </div>
  )
}
