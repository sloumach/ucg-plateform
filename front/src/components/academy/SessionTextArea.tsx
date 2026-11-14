import { useId, type TextareaHTMLAttributes } from 'react'
export function SessionTextArea({ label, error, ...props }: TextareaHTMLAttributes<HTMLTextAreaElement> & { label: string; error?: string }) {
  const id = useId()
  return <div className="grid min-w-0 gap-2"><label htmlFor={id}>{label}</label><textarea id={id} {...props} aria-invalid={error ? true : undefined} aria-describedby={error ? id + '-error' : undefined} className="ui-input" />{error && <p id={id + '-error'} className="text-sm text-rose-200">{error}</p>}</div>
}
