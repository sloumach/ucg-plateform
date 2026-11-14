import type { ReactNode } from 'react'
import { Button } from '../Button'

type AsyncStateProps = {
  actionLabel?: string
  description?: string
  onAction?: () => void
  title: string
  variant: 'empty' | 'error' | 'loading'
}

export function AsyncState({
  actionLabel,
  description,
  onAction,
  title,
  variant,
}: AsyncStateProps) {
  const role = variant === 'error' ? 'alert' : 'status'
  const marker: ReactNode =
    variant === 'loading' ? (
      <span
        aria-hidden="true"
        className="size-5 animate-spin rounded-full border-2 border-cyan-300/30 border-t-cyan-300 motion-reduce:animate-none"
      />
    ) : (
      <span
        aria-hidden="true"
        className={`size-2.5 rounded-full ${
          variant === 'error' ? 'bg-rose-400' : 'bg-slate-500'
        }`}
      />
    )

  return (
    <div
      role={role}
      className="ui-state grid justify-items-center gap-3 px-4 py-6 text-center sm:py-8"
    >
      {marker}
      <div className="grid gap-1">
        <p className="font-semibold text-slate-100">{title}</p>
        {description && <p className="max-w-prose text-sm leading-6 text-muted">{description}</p>}
      </div>
      {actionLabel && onAction && (
        <Button onClick={onAction} className="mt-1 py-2 text-sm">
          {actionLabel}
        </Button>
      )}
    </div>
  )
}
