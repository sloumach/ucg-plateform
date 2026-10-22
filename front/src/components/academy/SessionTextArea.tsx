import { useId, type TextareaHTMLAttributes } from 'react'
export function SessionTextArea({ label, error, ...props }: TextareaHTMLAttributes<HTMLTextAreaElement> & { label: string; error?: string }) {
  const id = useId()
  return <div className="grid min-w-0 gap-2"><label htmlFor={id}>{label}</label><textarea id={id} {...props} aria-invalid={error ? true : undefined} aria-describedby={error ? id + '-error' : undefined} className="min-w-0 w-full rounded-xl border border-white/15 bg-slate-900 px-3 py-3 text-slate-100" />{error && <p id={id + '-error'} className="text-sm text-rose-200">{error}</p>}</div>
}
