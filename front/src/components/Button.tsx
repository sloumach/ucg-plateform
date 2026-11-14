import type { ButtonHTMLAttributes } from 'react'

type ButtonVariant = 'primary' | 'secondary'

type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & {
  isLoading?: boolean
  loadingLabel?: string
  variant?: ButtonVariant
}

const variantClasses: Record<ButtonVariant, string> = {
  primary:
    'border border-transparent bg-accent text-slate-950 hover:bg-cyan-200',
  secondary:
    'border border-control bg-raised text-slate-100 hover:border-muted hover:bg-slate-700',
}

export function Button({
  children,
  className = '',
  disabled,
  isLoading = false,
  loadingLabel,
  type = 'button',
  variant = 'secondary',
  ...props
}: ButtonProps) {
  return (
    <button
      {...props}
      type={type}
      disabled={disabled || isLoading}
      aria-busy={isLoading || props['aria-busy']}
      className={`inline-flex min-h-11 min-w-0 max-w-full items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-center text-sm leading-5 font-semibold wrap-anywhere transition-colors disabled:cursor-not-allowed disabled:opacity-60 aria-busy:cursor-wait ${variantClasses[variant]} ${className}`}
    >
      {isLoading && <span aria-hidden="true" className="size-4 shrink-0 animate-spin rounded-full border-2 border-current border-r-transparent motion-reduce:animate-none" />}
      {isLoading && loadingLabel ? loadingLabel : children}
      {props['aria-expanded'] !== undefined && !isLoading && (
        <span aria-hidden="true" className={`ms-1 size-2 shrink-0 border-e-2 border-b-2 transition-transform motion-reduce:transition-none ${props['aria-expanded'] ? 'translate-y-0.5 -rotate-135' : '-translate-y-0.5 rotate-45'}`} />
      )}
    </button>
  )
}
