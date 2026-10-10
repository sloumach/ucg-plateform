import type { ReactNode } from 'react'
import { t } from '../../i18n/fr'

export type FeedbackTone = 'error' | 'info' | 'success' | 'warning'

type AlertProps = {
  actions?: ReactNode
  children?: ReactNode
  requestId?: string
  title: string
  tone?: FeedbackTone
}

const toneClasses: Record<FeedbackTone, string> = {
  error: 'border-rose-400/30 bg-rose-400/10 text-rose-100',
  info: 'border-cyan-400/30 bg-cyan-400/10 text-cyan-100',
  success: 'border-emerald-400/30 bg-emerald-400/10 text-emerald-100',
  warning: 'border-amber-300/30 bg-amber-300/10 text-amber-100',
}

export function Alert({
  actions,
  children,
  requestId,
  title,
  tone = 'info',
}: AlertProps) {
  const role = tone === 'error' || tone === 'warning' ? 'alert' : 'status'

  return (
    <div
      role={role}
      className={`rounded-xl border p-4 text-sm ${toneClasses[tone]}`}
    >
      <p className="font-semibold">{title}</p>
      {children && <div className="mt-1 leading-6">{children}</div>}
      {requestId && (
        <p className="mt-2 break-all font-mono text-xs opacity-80">
          {t('errors.requestReference', { requestId })}
        </p>
      )}
      {actions && <div className="mt-3 flex flex-wrap gap-2">{actions}</div>}
    </div>
  )
}
