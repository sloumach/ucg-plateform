import type { ButtonHTMLAttributes } from 'react'

type ButtonVariant = 'primary' | 'secondary'

type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & {
  isLoading?: boolean
  loadingLabel?: string
  variant?: ButtonVariant
}

const variantClasses: Record<ButtonVariant, string> = {
  primary:
    'bg-cyan-400 text-slate-950 hover:bg-cyan-300 focus-visible:outline-cyan-300',
  secondary:
    'border border-white/15 text-slate-100 hover:bg-white/10 focus-visible:outline-slate-200',
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
      className={`rounded-xl px-4 py-3 font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-wait disabled:opacity-60 ${variantClasses[variant]} ${className}`}
    >
      {isLoading && loadingLabel ? loadingLabel : children}
    </button>
  )
}
