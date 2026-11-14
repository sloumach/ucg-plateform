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
  const checkable = inputProps.type === 'checkbox' || inputProps.type === 'radio'

  return (
    <div className={checkable ? 'grid min-w-0 grid-cols-[auto_minmax(0,1fr)] items-center gap-x-2 gap-y-1' : 'grid min-w-0 grid-cols-1 gap-2'}>
      <label className={`${checkable ? 'col-start-2 row-start-1 ' : ''}text-sm leading-5 font-semibold text-slate-100`} htmlFor={inputId}>
        {label}
      </label>
      {hint && (
        <p id={hintId} className={checkable ? 'col-start-2 text-sm leading-5 text-muted' : 'text-sm leading-5 text-muted'}>
          {hint}
        </p>
      )}
      <input
        {...inputProps}
        id={inputId}
        name={name}
        aria-describedby={describedBy || undefined}
        aria-invalid={error ? true : inputProps['aria-invalid']}
        className={`${checkable ? 'col-start-1 row-start-1 size-4' : 'ui-input'} ${className}`}
      />
      {error && (
        <p id={errorId} className={checkable ? 'col-start-2 text-sm text-rose-200' : 'text-sm text-rose-200'}>
          {error}
        </p>
      )}
    </div>
  )
}
