import type { ReactNode } from 'react'
import { Brand } from './Brand'

export function AuthLayout({ children, footer }: { children: ReactNode; footer?: ReactNode }) {
  return (
    <div className="auth-background flex min-h-screen flex-col text-slate-100">
      <header className="workspace-header">
        <div className="mx-auto max-w-screen-2xl px-4 py-4 sm:px-8"><Brand /></div>
      </header>
      <main className="grid flex-1 place-items-center px-4 py-8 sm:py-12">
        <div className="grid w-full max-w-md gap-4">
          <div className="auth-card grid gap-5 p-5 sm:p-8">{children}</div>
          {footer}
        </div>
      </main>
    </div>
  )
}
