import { useId, type InputHTMLAttributes } from 'react'

type FormFieldProps = InputHTMLAttributes<HTMLInputElement> & {
  error?: string
  hint?: string
  label: string
}

export function FormField({
  'aria-describedby': ariaDescribedBy,
  className = '',
  error,
  hint,
  id,
  label,
  name,
  ...inputProps
}: FormFieldProps) {
  const generatedId = useId()
  const inputId = id ?? `${name ?? 'field'}-${generatedId}`
  const errorId = error ? `${inputId}-error` : undefined
  const hintId = hint ? `${inputId}-hint` : undefined
  const describedBy = [ariaDescribedBy, hintId, errorId].filter(Boolean).join(' ')

  return (
    <div className="grid gap-2">
      <label className="text-sm font-medium text-slate-100" htmlFor={inputId}>
        {label}
      </label>
      {hint && (
        <p id={hintId} className="text-sm text-slate-400">
          {hint}
        </p>
      )}
      <input
        {...inputProps}
        id={inputId}
        name={name}
        aria-describedby={describedBy || undefined}
        aria-invalid={error ? true : undefined}
        className={`w-full rounded-xl border bg-slate-900 px-4 py-3 text-slate-100 outline-none transition placeholder:text-slate-500 focus:ring-2 ${
          error
            ? 'border-rose-400 focus:border-rose-300 focus:ring-rose-400/20'
            : 'border-white/10 focus:border-cyan-400 focus:ring-cyan-400/20'
        } ${className}`}
      />
      {error && (
        <p id={errorId} className="text-sm text-rose-200">
          {error}
        </p>
      )}
    </div>
  )
}
